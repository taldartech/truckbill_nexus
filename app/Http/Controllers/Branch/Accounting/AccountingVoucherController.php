<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\AccountingWorkspaceService;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Domains\Accounting\GlLedgerService;
use App\Domains\Accounting\VoucherPostingService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\AccountingVoucher;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountingVoucherController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly AccountingWorkspaceService $workspace,
        private readonly VoucherPostingService $posting,
        private readonly GlLedgerService $ledgerService,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_VIEW);
        $bootstrap = $this->workspace->bootstrap($company);

        $page = $this->workspace->dayBook(
            company: $company,
            branch: $branch,
            fromDate: $request->string('from_date')->toString() ?: null,
            toDate: $request->string('to_date')->toString() ?: null,
            voucherType: $request->string('voucher_type')->toString() ?: null,
            perPage: min(100, max(1, (int) $request->input('per_page', 25))),
        );

        return Inertia::render('accounting/vouchers/index', [
            'bootstrap' => $bootstrap,
            'filters' => [
                'from_date' => $request->string('from_date')->toString(),
                'to_date' => $request->string('to_date')->toString(),
                'voucher_type' => $request->string('voucher_type')->toString(),
            ],
            'voucherTypeOptions' => $this->workspace->voucherTypeOptions(),
            'permissions' => $this->permissions($actor, $company, $branch),
            'vouchers' => [
                'data' => collect($page->items())->map(fn (AccountingVoucher $voucher): array => [
                    'id' => $voucher->id,
                    'voucher_number' => $voucher->voucher_number,
                    'voucher_date' => $voucher->voucher_date?->toDateString(),
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
                'ledgers' => route('branch.accounting.ledgers.index', absolute: false),
                'trial_balance' => route('branch.accounting.reports.trial-balance', absolute: false),
                'profit_and_loss' => route('branch.accounting.reports.profit-and-loss', absolute: false),
                'balance_sheet' => route('branch.accounting.reports.balance-sheet', absolute: false),
                'cash_book' => route('branch.accounting.reports.cash-book', absolute: false),
                'bank_book' => route('branch.accounting.reports.bank-book', absolute: false),
                'outstanding' => route('branch.accounting.reports.outstanding', absolute: false),
                'fund_transfer' => route('branch.accounting.fund-transfers.create', absolute: false),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);
        $this->workspace->bootstrap($company);

        return Inertia::render('accounting/vouchers/create', [
            'voucherTypeOptions' => $this->workspace->voucherTypeOptions(),
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
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);

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
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_VIEW);

        if ($accountingVoucher->company_id !== $company->id) {
            abort(404);
        }

        $accountingVoucher->load(['lines.ledger']);

        return Inertia::render('accounting/vouchers/show', [
            'voucher' => [
                'id' => $accountingVoucher->id,
                'voucher_number' => $accountingVoucher->voucher_number,
                'voucher_date' => $accountingVoucher->voucher_date?->toDateString(),
                'voucher_type_label' => $accountingVoucher->voucher_type->label(),
                'narration' => $accountingVoucher->narration,
                'total_debit' => (string) $accountingVoucher->total_debit,
                'total_credit' => (string) $accountingVoucher->total_credit,
                'lines' => $accountingVoucher->lines->map(fn ($line): array => [
                    'ledger_name' => $line->ledger?->name,
                    'debit_amount' => (string) $line->debit_amount,
                    'credit_amount' => (string) $line->credit_amount,
                    'narration' => $line->narration,
                ])->values()->all(),
            ],
            'urls' => [
                'index' => route('branch.accounting.vouchers.index', absolute: false),
            ],
        ]);
    }

    /**
     * @return array{can_view: bool, can_create: bool, can_report: bool}
     */
    private function permissions(User $actor, Company $company, Branch $branch): array
    {
        $isOwner = (int) $actor->id === (int) $company->owner_user_id;

        return [
            'can_view' => $isOwner || $this->authorizer->allowsCustomer($actor, PermissionCodes::BRANCH_ACCOUNTING_VIEW, $company, $branch),
            'can_create' => $isOwner || $this->authorizer->allowsCustomer($actor, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, $company, $branch),
            'can_report' => $isOwner || $this->authorizer->allowsCustomer($actor, PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW, $company, $branch),
        ];
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403);
        }
    }
}
