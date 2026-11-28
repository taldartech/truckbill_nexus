<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransporterBookBalanceController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly TransporterBookBalanceService $balances,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly ?CompanyCapabilityResolver $capabilities = null,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertViewPermission($actor, $company, $branch);

        $validated = $request->validate([
            'transporter_party_id' => ['nullable', 'uuid'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $transporterId = filled($validated['transporter_party_id'] ?? null)
            ? (string) $validated['transporter_party_id']
            : null;
        $fromDate = filled($validated['from_date'] ?? null) ? (string) $validated['from_date'] : null;
        $toDate = filled($validated['to_date'] ?? null) ? (string) $validated['to_date'] : null;
        $query = filled($validated['q'] ?? null) ? trim((string) $validated['q']) : '';

        $transporters = app(PartyOptionQuery::class)
            ->forRoles($company, $branch, PartyRoleType::Transporter, limit: 300, selectedId: $transporterId);

        $statement = null;
        $summaries = [];
        if ($transporterId !== null) {
            try {
                $rawStatement = $this->balances->statementReport(
                    company: $company,
                    branch: $branch,
                    transporterPartyId: $transporterId,
                    fromDate: $fromDate,
                    toDate: $toDate,
                );
                $statement = $this->presentStatement($rawStatement, $maySeeFreight);
            } catch (ValidationException) {
                $transporterId = null;
            }
        }

        if ($transporterId === null) {
            $summaries = array_map(
                fn (array $row): array => [
                    ...$row,
                    'book_balance' => $this->freightPresentation->presentAmount($row['book_balance'], $maySeeFreight),
                    'available_balance' => $this->freightPresentation->presentAmount($row['available_balance'], $maySeeFreight),
                ],
                $this->balances->transporterDirectorySummaries($company, $branch, $query),
            );
        }

        $canManage = $this->canManage($actor, $company, $branch, $maySeeFreight);
        $masterPerms = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);
        $canCreateBankCash = $canManage && $masterPerms['can_create'];

        return Inertia::render('broker/transporter-balances/index', [
            'filters' => [
                'transporter_party_id' => $transporterId ?? '',
                'from_date' => $fromDate ?? '',
                'to_date' => $toDate ?? '',
                'q' => $query,
            ],
            'transporters' => $transporters,
            'summaries' => $summaries,
            'statement' => $statement,
            'bank_cash_accounts' => $canManage ? $this->balances->activeBankAccounts($company, $branch) : [],
            'today' => $this->balances->defaultEntryDate($company),
            'permissions' => [
                'can_view_freight' => $maySeeFreight,
                'can_manage' => $canManage,
                'can_create_bank_cash' => $canCreateBankCash,
            ],
            'urls' => [
                'parties_options' => route('branch.parties.options', absolute: false),
                'index' => route('branch.broker.transporter-balances.index', absolute: false),
                'store' => $canManage ? route('branch.broker.transporter-balances.store', absolute: false) : null,
                'context' => route('branch.broker.transporter-balances.context', absolute: false),
                'bank_cash_store' => $canCreateBankCash ? route('branch.bank-cash.store', absolute: false) : null,
            ],
        ]);
    }

    public function context(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertViewPermission($actor, $company, $branch);

        $validated = $request->validate([
            'transporter_party_id' => ['required', 'uuid'],
        ]);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $statement = $this->balances->statementReport(
            company: $company,
            branch: $branch,
            transporterPartyId: (string) $validated['transporter_party_id'],
        );

        $canManage = $this->canManage($actor, $company, $branch, $maySeeFreight);
        $masterPerms = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);
        $canCreateBankCash = $canManage && $masterPerms['can_create'];

        $recent = $this->balances->recentEntriesForTransporter(
            company: $company,
            branch: $branch,
            transporterPartyId: (string) $validated['transporter_party_id'],
            limit: 10,
        );

        return response()->json([
            'transporter' => $statement['transporter'],
            'available_balance' => $this->freightPresentation->presentAmount($statement['available_balance'], $maySeeFreight),
            'running_balance' => $this->freightPresentation->presentAmount($statement['current_balance'], $maySeeFreight),
            'recent_entries' => array_map(
                fn (array $row): array => [
                    ...$row,
                    'debit' => $this->freightPresentation->presentAmount($row['debit'], $maySeeFreight),
                    'credit' => $this->freightPresentation->presentAmount($row['credit'], $maySeeFreight),
                    'amount' => $this->freightPresentation->presentAmount($row['amount'], $maySeeFreight),
                ],
                $recent,
            ),
            'bank_cash_accounts' => $canManage ? $this->balances->activeBankAccounts($company, $branch) : [],
            'today' => $this->balances->defaultEntryDate($company),
            'bank_cash_store_url' => $canCreateBankCash ? route('branch.bank-cash.store', absolute: false) : null,
            'permissions' => [
                'can_manage' => $canManage,
                'can_view_freight' => $maySeeFreight,
                'can_create_bank_cash' => $canCreateBankCash,
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertManagePermission($actor, $company, $branch);

        $validated = $request->validate([
            'transporter_party_id' => ['required', 'uuid'],
            'transaction_type' => ['required', Rule::in(['advance', 'recovery', 'adjustment', 'opening_balance'])],
            'adjustment_direction' => ['nullable', Rule::in(['add', 'deduct'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'entry_date' => ['required', 'date'],
            'payment_mode' => ['nullable', Rule::in(['cash', 'bank'])],
            'bank_cash_account_id' => ['nullable', 'uuid'],
            'reference_number' => ['nullable', 'string', 'max:64'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $entry = $this->balances->recordTransaction($company, $branch, $actor, $validated);
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $entry->id,
                    'entry_type' => $entry->entry_type->value,
                    'available_balance' => $this->balances->availableBalance($company, $branch, $entry->transporter_party_id),
                ],
            ], 201);
        }

        return redirect()
            ->back()
            ->with('success', $entry->entry_type->label().' recorded on Transporter Book Balance.');
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function presentStatement(array $raw, bool $maySeeFreight): array
    {
        $present = fn (mixed $value): ?string => $this->freightPresentation->presentAmount($value, $maySeeFreight);

        return [
            'transporter' => $raw['transporter'],
            'from_date' => $raw['from_date'],
            'to_date' => $raw['to_date'],
            'opening_balance' => $present($raw['opening_balance']),
            'total_debit' => $present($raw['total_debit']),
            'total_credit' => $present($raw['total_credit']),
            'closing_balance' => $present($raw['closing_balance']),
            'current_balance' => $present($raw['current_balance']),
            'available_balance' => $present($raw['available_balance']),
            'entries' => array_map(
                static fn (array $row): array => [
                    ...$row,
                    'debit' => $present($row['debit']),
                    'credit' => $present($row['credit']),
                    'amount' => $present($row['amount']),
                    'running_balance' => $present($row['running_balance']),
                ],
                $raw['entries'],
            ),
        ];
    }

    private function canManage(User $actor, Company $company, Branch $branch, bool $maySeeFreight): bool
    {
        $resolver = $this->capabilities ?? app(CompanyCapabilityResolver::class);

        return $maySeeFreight
            && $resolver->allowsMutate($company, CompanyCapabilities::BROKER_BOOK_BALANCE)
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_BOOK_BALANCE_MANAGE, $company, $branch);
    }

    private function assertViewPermission(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_BOOK_BALANCE_VIEW, $company, $branch)) {
            abort(403, 'Not authorized to view transporter book balance.');
        }
    }

    private function assertManagePermission(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_BOOK_BALANCE_MANAGE, $company, $branch)) {
            abort(403, 'Not authorized to record transporter book balance transactions.');
        }
    }
}
