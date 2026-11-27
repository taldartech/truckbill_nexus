<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\AccountingReconciliationService;
use App\Domains\Accounting\AccountingWorkspaceService;
use App\Domains\Accounting\Enums\AccountingVoucherStatus;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Domains\Accounting\GlLedgerService;
use App\Domains\Accounting\VoucherPostingService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Http\Controllers\Branch\Accounting\Concerns\AuthorizesAccountingPanel;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\AccountingVoucher;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountingVoucherController extends Controller
{
    use AuthorizesAccountingPanel;
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly AccountingWorkspaceService $workspace,
        private readonly VoucherPostingService $posting,
        private readonly GlLedgerService $ledgerService,
        private readonly ?AccountingReconciliationService $reconciliation = null,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VIEW);
        $this->workspace->bootstrap($company);

        $filters = $request->validate([
            'financial_year_id' => ['nullable', 'uuid', Rule::exists('accounting_periods', 'id')->where('company_id', $company->id)],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'voucher_type' => ['nullable', Rule::enum(AccountingVoucherType::class)],
        ]);

        $page = $this->workspace->dayBook(
            company: $company,
            branch: $branch,
            fromDate: $filters['from_date'] ?? null,
            toDate: $filters['to_date'] ?? null,
            voucherType: $filters['voucher_type'] ?? null,
            perPage: min(100, max(1, (int) $request->input('per_page', 25))),
            financialYearId: $filters['financial_year_id'] ?? null,
        );

        return Inertia::render('accounting/vouchers/index', [
            'filters' => [
                'financial_year_id' => $filters['financial_year_id'] ?? '',
                'from_date' => $filters['from_date'] ?? '',
                'to_date' => $filters['to_date'] ?? '',
                'voucher_type' => $filters['voucher_type'] ?? '',
            ],
            'financialYearOptions' => $this->workspace->financialYearOptions($company),
            'voucherTypeOptions' => $this->workspace->voucherTypeOptions(),
            'permissions' => $this->accountingAbilities($actor, $company, $branch, $this->authorizer),
            'vouchers' => [
                'data' => collect($page->items())->map(fn (AccountingVoucher $voucher): array => [
                    'id' => $voucher->id,
                    'voucher_number' => $voucher->voucher_number,
                    'voucher_date' => $voucher->voucher_date->toDateString(),
                    'voucher_type' => $voucher->voucher_type->value,
                    'voucher_type_label' => $voucher->voucher_type->label(),
                    'narration' => $voucher->narration,
                    'total_debit' => (string) $voucher->total_debit,
                    'total_credit' => (string) $voucher->total_credit,
                    'urls' => [
                        'show' => route('branch.accounting.vouchers.show', $voucher, absolute: false),
                    ],
                ])->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.accounting.vouchers.index', absolute: false),
                'create' => route('branch.accounting.vouchers.create', absolute: false),
                'workspace' => route('branch.accounting.workspace', absolute: false),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, requireMutation: true);
        $this->workspace->bootstrap($company);

        return Inertia::render('accounting/vouchers/create', [
            'voucherTypeOptions' => $this->workspace->voucherTypeOptions(),
            'defaultVoucherType' => (AccountingVoucherType::tryFrom($request->string('voucher_type')->toString())
                ?? AccountingVoucherType::Journal)->value,
            'ledgers' => $this->workspace->activeLedgers($company)->map(fn ($ledger): array => [
                'id' => $ledger->id,
                'name' => $ledger->name,
            ])->values()->all(),
            'accountGroups' => $this->ledgerService->groupOptions($company),
            'urls' => [
                'store' => route('branch.accounting.vouchers.store', absolute: false),
                'index' => route('branch.accounting.vouchers.index', absolute: false),
                'ledger_quick_create' => route('branch.accounting.ledgers.quick-store', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, requireMutation: true);

        $validated = $request->validate([
            'voucher_type' => ['required', Rule::enum(AccountingVoucherType::class)],
            'voucher_date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.gl_ledger_id' => ['required', 'uuid'],
            'lines.*.debit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $voucher = $this->posting->postManual(
                company: $company,
                actor: $actor,
                header: [
                    'voucher_type' => $validated['voucher_type'],
                    'voucher_date' => $validated['voucher_date'],
                    'narration' => $validated['narration'] ?? null,
                    'branch_id' => $branch->id,
                    'source_type' => 'manual',
                    'source_id' => (string) str()->uuid(),
                    'posting_purpose' => 'manual',
                ],
                lines: $validated['lines'],
                branch: $branch,
            );
        } catch (AccountingDomainException $e) {
            throw ValidationException::withMessages(['voucher' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.accounting.vouchers.show', $voucher)
            ->with('success', 'Voucher '.$voucher->voucher_number.' posted.');
    }

    public function show(Request $request, Company $company, Branch $branch, AccountingVoucher $accountingVoucher): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $maySeeFreight = $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VIEW);

        if ($accountingVoucher->company_id !== $company->id) {
            abort(404);
        }

        $accountingVoucher->load(['lines.ledger']);
        $sourceDocument = ($this->reconciliation ?? app(AccountingReconciliationService::class))
            ->sourceDocumentDrillDown($company, $accountingVoucher, $maySeeFreight);
        $isOwner = (int) $actor->id === (int) $company->owner_user_id;
        $canReverse = $accountingVoucher->status === AccountingVoucherStatus::Posted
            && app(CompanyCapabilityResolver::class)->allowsMutate($company, CompanyCapabilities::ACCOUNTING)
            && ($isOwner || $this->authorizer->allowsCustomer($actor, PermissionCodes::BRANCH_ACCOUNTING_REVERSE, $company, $branch));

        return Inertia::render('accounting/vouchers/show', [
            'voucher' => [
                'id' => $accountingVoucher->id,
                'voucher_number' => $accountingVoucher->voucher_number,
                'voucher_date' => $accountingVoucher->voucher_date->toDateString(),
                'voucher_type_label' => $accountingVoucher->voucher_type->label(),
                'status' => $accountingVoucher->status->value,
                'narration' => $accountingVoucher->narration,
                'total_debit' => (string) $accountingVoucher->total_debit,
                'total_credit' => (string) $accountingVoucher->total_credit,
                'source_document' => $sourceDocument,
                'lines' => $accountingVoucher->lines->map(fn ($line): array => [
                    'ledger_id' => $line->gl_ledger_id,
                    'ledger_name' => $line->ledger?->name,
                    'debit_amount' => (string) $line->debit_amount,
                    'credit_amount' => (string) $line->credit_amount,
                    'narration' => $line->narration,
                ])->values()->all(),
            ],
            'permissions' => [
                'can_reverse' => $canReverse,
            ],
            'urls' => [
                'index' => route('branch.accounting.vouchers.index', absolute: false),
                'reverse' => route('branch.accounting.vouchers.reverse', $accountingVoucher, absolute: false),
            ],
        ]);
    }

    public function reverse(Request $request, Company $company, Branch $branch, AccountingVoucher $accountingVoucher): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_REVERSE, requireMutation: true);

        if ($accountingVoucher->company_id !== $company->id) {
            abort(404);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $reversal = $this->posting->reverse($accountingVoucher, $actor, $validated['reason']);
        } catch (AccountingDomainException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.accounting.vouchers.show', $reversal)
            ->with('flash.success', 'Reversal voucher '.$reversal->voucher_number.' posted.');
    }
}
