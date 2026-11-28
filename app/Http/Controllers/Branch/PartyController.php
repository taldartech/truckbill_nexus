<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyProfileValidator;
use App\Domains\Company\MasterDataVisibilityResolver;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Geography\Concerns\ResolvesAddressGeography;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\Exceptions\PartyDomainException;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Domains\Tax\Enums\PanStatus;
use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Domains\Tax\TransporterTdsCalculationService;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\State;
use App\Models\User;
use App\Rules\IndianMobileNumber;
use App\Support\Presentation\AccountNumberMask;
use App\Support\Presentation\FreightAmountPresentation;
use App\Support\Presentation\PartyProfilePresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Party Master Inertia UI + JSON adapters (PO-P3-UI-AUTH).
 */
class PartyController extends Controller
{
    use AuthorizesBranchPanel;
    use PresentsDocumentDrawer;
    use ResolvesAddressGeography;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly PartyService $parties,
        private readonly DocumentAttachmentService $documents,
        private readonly TransporterTdsCalculationService $transporterTds,
        private readonly TransporterBookBalanceService $bookBalances = new TransporterBookBalanceService,
        private readonly FreightAmountPresentation $freightPresentation = new FreightAmountPresentation,
        private readonly PartyProfilePresentation $profilePresentation = new PartyProfilePresentation,
        private readonly ?CompanyCapabilityResolver $capabilities = null,
    ) {}

    private function capabilities(): CompanyCapabilityResolver
    {
        return $this->capabilities ?? app(CompanyCapabilityResolver::class);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function partyRoleOptions(Company $company): array
    {
        return array_map(
            fn (PartyRoleType $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ],
            $this->capabilities()->allowedPartyRoles($company),
        );
    }

    /**
     * @param  list<array{role: string, attributes: array<string, mixed>}>  $roles
     */
    private function assertPartyRolesAllowed(Company $company, array $roles): void
    {
        $allowed = array_map(
            fn (PartyRoleType $role): string => $role->value,
            $this->capabilities()->allowedPartyRoles($company),
        );

        foreach ($roles as $entry) {
            if (! in_array($entry['role'], $allowed, true)) {
                $capability = $entry['role'] === PartyRoleType::Driver->value
                    ? CompanyCapabilities::MASTERS_PARTIES_DRIVER_ROLE
                    : CompanyCapabilities::MASTERS_PARTIES;
                abort(403, $this->capabilities()->denyMessage($company, $capability));
            }
        }
    }

    public function index(Request $request, Company $company, Branch $branch): Response|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::enum(PartyRoleType::class)],
            'state_id' => ['nullable', 'uuid'],
            'city' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'deleted'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'active';

        try {
            $page = $this->parties->search(
                actor: $actor,
                company: $company,
                branch: $branch,
                query: $filters['q'] ?? null,
                role: $filters['role'] ?? null,
                perPage: (int) ($filters['per_page'] ?? 15),
                stateId: $filters['state_id'] ?? null,
                city: $filters['city'] ?? null,
                deleted: $status === 'deleted',
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json($page);
        }

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $mayViewBookBalance = $maySeeFreight
            && $this->capabilities()->allowsRead($company, CompanyCapabilities::BROKER_BOOK_BALANCE)
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_BOOK_BALANCE_VIEW, $company, $branch);
        $canManageBookBalance = $mayViewBookBalance
            && $this->capabilities()->allowsMutate($company, CompanyCapabilities::BROKER_BOOK_BALANCE)
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_BOOK_BALANCE_MANAGE, $company, $branch);

        /** @var list<Party> $items */
        $items = $page->items();
        $transporterIds = [];
        if ($mayViewBookBalance && $status === 'active') {
            foreach ($items as $party) {
                $isTransporter = $party->roles->contains(
                    static fn (PartyRole $role): bool => (string) $role->role === PartyRoleType::Transporter->value
                        && $role->deactivated_at === null,
                );
                if ($isTransporter) {
                    $transporterIds[] = $party->id;
                }
            }
        }
        $balances = $mayViewBookBalance
            ? $this->bookBalances->balancesForTransporters($company, $branch, $transporterIds)
            : [];
        $canCreateBankCash = $canManageBookBalance && $permissions['can_create'];

        return Inertia::render('branch/masters/parties/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'role' => $filters['role'] ?? '',
                'state_id' => $filters['state_id'] ?? '',
                'city' => $filters['city'] ?? '',
                'status' => $status,
            ],
            'roleOptions' => $this->partyRoleOptions($company),
            'stateOptions' => State::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (State $state): array => ['value' => $state->id, 'label' => $state->name])
                ->all(),
            'parties' => [
                'data' => collect($items)->map(function (Party $party) use ($balances, $mayViewBookBalance): array {
                    $payload = $this->partyListPayload($party);
                    $isTransporter = $party->roles->contains(
                        static fn (PartyRole $role): bool => (string) $role->role === PartyRoleType::Transporter->value
                            && $role->deactivated_at === null,
                    );
                    $payload['is_transporter'] = $isTransporter;
                    $payload['book_balance'] = ($mayViewBookBalance && $isTransporter && ! $party->trashed())
                        ? $this->freightPresentation->presentAmount($balances[$party->id] ?? '0.00', true)
                        : null;

                    return $payload;
                })->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'bookBalance' => [
                'can_view' => $mayViewBookBalance,
                'can_manage' => $canManageBookBalance,
                'today' => $canManageBookBalance ? $this->bookBalances->defaultEntryDate($company) : null,
                'bank_cash_accounts' => $canManageBookBalance ? $this->bookBalances->activeBankAccounts($company, $branch) : [],
                'can_create_bank_cash' => $canCreateBankCash,
            ],
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.parties.index', absolute: false),
                'create' => route('branch.parties.create', absolute: false),
                'store' => route('branch.parties.store', absolute: false),
                'book_balance_store' => $canManageBookBalance ? route('branch.broker.transporter-balances.store', absolute: false) : null,
                'book_balance_context' => $mayViewBookBalance ? route('branch.broker.transporter-balances.context', absolute: false) : null,
                'book_balance_index' => $mayViewBookBalance ? route('branch.broker.transporter-balances.index', absolute: false) : null,
                'bank_cash_store' => $canCreateBankCash ? route('branch.bank-cash.store', absolute: false) : null,
            ]),
        ]);
    }

    /**
     * Bounded, role-filtered Party options for operational selectors (JSON).
     */
    public function options(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(PartyRoleType::class)],
            'roles' => ['sometimes', 'array', 'max:8'],
            'roles.*' => [Rule::enum(PartyRoleType::class)],
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'selected_id' => ['nullable', 'uuid'],
        ]);

        $roleInputs = $validated['roles'] ?? [$validated['role']];
        $roles = [];
        foreach ($roleInputs as $roleInput) {
            $roles[] = $roleInput instanceof PartyRoleType
                ? $roleInput
                : PartyRoleType::from((string) $roleInput);
        }

        $this->assertPartyRolesAllowed(
            $company,
            array_map(
                static fn (PartyRoleType $role): array => ['role' => $role->value, 'attributes' => []],
                $roles,
            ),
        );

        $options = app(PartyOptionQuery::class)->forRoles(
            company: $company,
            branch: $branch,
            roles: $roles,
            limit: (int) ($validated['limit'] ?? 50),
            query: $validated['q'] ?? null,
            selectedId: $validated['selected_id'] ?? null,
        );

        return response()->json([
            'data' => $options,
            'meta' => [
                'count' => count($options),
                'limit' => (int) ($validated['limit'] ?? 50),
                'q' => $validated['q'] ?? '',
            ],
        ]);
    }

    /**
     * Informational TDS % for Bilty Pay To preview (authoritative snapshot remains on Lorry Hire).
     */
    public function tdsForPayee(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'party_id' => ['required', 'uuid'],
        ]);

        $party = Party::query()
            ->visibleInBranch($company->id, $branch->id)
            ->whereKey($validated['party_id'])
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            return response()->json([
                'applicable_percent' => null,
                'explanation' => null,
            ]);
        }

        $role = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $party->id)
            ->where('role', PartyRoleType::Transporter->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->first();

        $roleAttrs = is_array($role?->role_attributes) ? $role->role_attributes : [];
        $context = $this->transporterTds->buildContextFromTransporter(
            ['pan' => $party->pan],
            $roleAttrs,
        );

        try {
            $result = $this->transporterTds->calculate($context);
        } catch (TaxDomainException) {
            return response()->json([
                'applicable_percent' => null,
                'explanation' => null,
            ]);
        }

        $declarationRaw = $roleAttrs['declaration'] ?? null;
        $declarationReceived = $declarationRaw === null
            ? null
            : filter_var($declarationRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return response()->json([
            'applicable_percent' => number_format((float) $result->applicableRate, 3, '.', ''),
            'explanation' => (string) $result->explanation,
            'declaration_received' => $declarationReceived,
            'party_name' => $party->name,
            'party_edit_url' => route('branch.parties.edit', [$party], absolute: false),
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_create']) {
            abort(403, 'Not authorized to create parties.');
        }

        $roleOptions = $this->partyRoleOptions($company);
        $allowedRoleValues = array_column($roleOptions, 'value');
        $requestedRole = $request->string('default_role')->toString();
        $defaultRoles = in_array($requestedRole, $allowedRoleValues, true)
            ? [$requestedRole]
            : null;

        return Inertia::render('branch/masters/parties/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'create',
            'party' => null,
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'roleOptions' => $roleOptions,
            'defaultRoles' => $defaultRoles,
            'defaultDriverType' => $this->capabilities()->allowsRead($company, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS)
                ? 'permanent'
                : 'market',
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.parties.index', absolute: false),
                'store' => route('branch.parties.store', absolute: false),
            ]),
        ]);
    }

    public function previewTds(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_create'] && ! $permissions['can_edit']) {
            abort(403, 'Not authorized to preview transporter TDS.');
        }

        $validated = $request->validate([
            'pan' => ['nullable', 'string', 'max:20'],
            'pan_status' => ['nullable', Rule::enum(PanStatus::class)],
            'declaration' => ['nullable'],
            'goods_vehicles_count' => ['nullable'],
            'tds_percentage' => ['nullable'],
            'tds_override' => ['nullable'],
            'tds_adjustment_remark' => ['nullable', 'string', 'max:1000'],
            'party' => ['nullable', 'array'],
            'party.pan' => ['nullable', 'string', 'max:20'],
            'attributes' => ['nullable', 'array'],
        ]);

        $previewKeys = [
            'pan',
            'pan_status',
            'declaration',
            'goods_vehicles_count',
            'tds_percentage',
            'tds_override',
            'tds_adjustment_remark',
        ];
        $fromRequest = array_intersect_key($validated, array_flip($previewKeys));

        $roleAttrs = $this->normalizeIncomingRoleAttributes(array_merge(
            $validated['attributes'] ?? [],
            $fromRequest,
        ));

        $partyAttrs = array_merge(
            $validated['party'] ?? [],
            filled($validated['pan'] ?? null) ? ['pan' => $validated['pan']] : [],
        );

        $pan = strtoupper(trim((string) ($roleAttrs['pan'] ?? $partyAttrs['pan'] ?? '')));
        if ($pan !== '' && ! preg_match(CompanyProfileValidator::PAN_PATTERN, $pan)) {
            throw ValidationException::withMessages(['pan' => 'PAN format is invalid (AAAAA9999A).']);
        }

        if (filled($roleAttrs['pan_status'] ?? null)
            && PanStatus::tryFrom(strtolower(trim((string) $roleAttrs['pan_status']))) === null) {
            throw ValidationException::withMessages(['pan_status' => 'PAN status must be Active or Inactive.']);
        }

        $context = $this->transporterTds->buildContextFromTransporter($partyAttrs, $roleAttrs);
        $override = filter_var($roleAttrs['tds_override'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? (bool) ($roleAttrs['tds_override'] ?? false);

        try {
            $result = $this->transporterTds->calculate(
                $context,
                overrideRate: $override && isset($roleAttrs['tds_percentage'])
                    ? (string) $roleAttrs['tds_percentage']
                    : null,
                overrideRemark: $override ? ($roleAttrs['tds_adjustment_remark'] ?? null) : null,
                allowOverride: $override,
            );
        } catch (TaxDomainException $e) {
            throw ValidationException::withMessages([
                'tds' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            ...$this->partyFieldRules(),
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.role' => ['required', Rule::enum(PartyRoleType::class)],
            'roles.*.attributes' => ['nullable', 'array'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'return_to' => ['nullable', 'string', Rule::in(['drivers'])],
        ]);

        $roles = $this->normalizeIncomingRoles($validated['roles']);
        $this->assertPartyRolesAllowed($company, $roles);
        $attributes = $this->normalizePartyAttributes($validated);

        // PO-MASTER-COMPANY-VISIBILITY: ignore any client-submitted scope.
        $scopeBranch = app(MasterDataVisibilityResolver::class)
            ->scopeBranchForCreate($company, $branch);

        try {
            $party = $this->parties->create(
                actor: $actor,
                company: $company,
                branch: $scopeBranch,
                attributes: $attributes,
                roles: $roles,
                remark: $validated['remark'] ?? 'Create party',
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        }

        if ($request->expectsJson()) {
            $party->loadMissing('roles');

            return response()->json([
                'data' => $this->partyListPayload($party),
            ], 201);
        }

        $returnToDrivers = ($validated['return_to'] ?? null) === 'drivers'
            && $party->roles->contains(fn (PartyRole $role): bool => (string) $role->role === PartyRoleType::Driver->value && $role->deactivated_at === null);

        return redirect()
            ->route($returnToDrivers ? 'branch.drivers.show' : 'branch.parties.show', [$party])
            ->with('success', $returnToDrivers ? 'Driver created.' : 'Party created.');
    }

    public function show(Request $request, Party $party, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $branch, $party);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $this->parties->search($actor, $company, $branch, perPage: 1);
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        $party->load(['roles' => fn ($q) => $q->orderByDesc('created_at')]);

        $isTransporter = $party->roles->contains(
            fn (PartyRole $role): bool => (string) $role->role === PartyRoleType::Transporter->value
                && $role->deactivated_at === null,
        );

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $mayViewBookBalance = $isTransporter
            && $this->capabilities()->allowsRead($company, CompanyCapabilities::BROKER_BOOK_BALANCE)
            && $maySeeFreight
            && $this->authorizer->allowsCustomer(
                $actor,
                PermissionCodes::BROKER_BOOK_BALANCE_VIEW,
                $company,
                $branch,
            );

        $bookBalance = null;
        if ($mayViewBookBalance) {
            $running = $this->bookBalances->runningBalanceForTransporter($company, $branch, $party->id);
            $available = $this->bookBalances->availableBalance($company, $branch, $party->id);
            $canManageBookBalance = $this->capabilities()->allowsMutate($company, CompanyCapabilities::BROKER_BOOK_BALANCE)
                && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_BOOK_BALANCE_MANAGE, $company, $branch);
            $canCreateBankCash = $canManageBookBalance && $permissions['can_create'];
            $recent = $this->bookBalances->recentEntriesForTransporter($company, $branch, $party->id, 10);

            $bookBalance = [
                'party_id' => $party->id,
                'party_name' => $party->name,
                'available_balance' => $this->freightPresentation->presentAmount($available, true),
                'running_balance' => $this->freightPresentation->presentAmount($running, true),
                'can_manage' => $canManageBookBalance,
                'today' => $canManageBookBalance ? $this->bookBalances->defaultEntryDate($company) : null,
                'bank_cash_accounts' => $canManageBookBalance ? $this->bookBalances->activeBankAccounts($company, $branch) : [],
                'can_create_bank_cash' => $canCreateBankCash,
                'recent_entries' => array_map(
                    fn (array $row): array => [
                        ...$row,
                        'debit' => $this->freightPresentation->presentAmount($row['debit'], true),
                        'credit' => $this->freightPresentation->presentAmount($row['credit'], true),
                        'amount' => $this->freightPresentation->presentAmount($row['amount'], true),
                    ],
                    $recent,
                ),
                'store_url' => $canManageBookBalance ? route('branch.broker.transporter-balances.store', absolute: false) : null,
                'context_url' => route('branch.broker.transporter-balances.context', absolute: false),
                'bank_cash_store_url' => $canCreateBankCash ? route('branch.bank-cash.store', absolute: false) : null,
                'statement_url' => route('branch.broker.transporter-balances.index', [
                    'transporter_party_id' => $party->id,
                ], absolute: false),
            ];
        }

        return Inertia::render('branch/masters/parties/show', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'party' => [
                'id' => $party->id,
                'name' => $party->name,
                'roles' => $party->roles
                    ->filter(fn (PartyRole $role): bool => $role->deactivated_at === null)
                    ->map(fn (PartyRole $role): string => PartyRoleType::from((string) $role->role)->label())
                    ->values()
                    ->all(),
                'sections' => $this->profilePresentation->sections($party, $maySeeFreight),
            ],
            'bookBalance' => $bookBalance,
            'documentDrawer' => $this->documentDrawerPayload(
                $actor,
                $company,
                $branch,
                'party',
                $party->id,
                $this->documents,
                $permissions,
            ),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.parties.index', absolute: false),
                'edit' => route('branch.parties.edit', [$party], absolute: false),
                'documents' => route('branch.documents.index', [
                    'company' => $company,
                    'branch' => $branch,
                    'parentType' => 'party',
                    'parentId' => $party->id,
                ], absolute: false),
            ]),
        ]);
    }

    public function edit(Request $request, Party $party, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $branch, $party);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_edit']) {
            abort(403, 'Not authorized to edit parties.');
        }

        $party->load(['roles' => fn ($q) => $q->whereNull('deactivated_at')]);

        return Inertia::render('branch/masters/parties/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'edit',
            'party' => $this->partyDetailPayload($party),
            'roleOptions' => $this->partyRoleOptions($company),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.parties.index', absolute: false),
                'show' => route('branch.parties.show', [$party], absolute: false),
                'update' => route('branch.parties.update', [$party], absolute: false),
            ]),
        ]);
    }

    public function update(Request $request, Party $party, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $branch, $party);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            ...$this->partyFieldRules(),
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*.role' => ['required_with:roles', Rule::enum(PartyRoleType::class)],
            'roles.*.attributes' => ['nullable', 'array'],
            'remark' => ['required', 'string', 'max:1000'],
            'return_to' => ['nullable', 'string', Rule::in(['drivers'])],
        ]);

        $roles = array_key_exists('roles', $validated)
            ? $this->normalizeIncomingRoles($validated['roles'])
            : null;
        if ($roles !== null) {
            $this->assertPartyRolesAllowed($company, $roles);
        }
        $attributes = $this->normalizePartyAttributes($validated);
        // Edit only shows the masked account number; a blank submission keeps the stored value.
        if (blank($attributes['bank_account_number'] ?? null)) {
            unset($attributes['bank_account_number']);
        }

        try {
            $updated = $this->parties->update(
                $actor,
                $party,
                $attributes,
                $validated['remark'],
                $roles,
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $updated]);
        }

        $returnToDrivers = ($validated['return_to'] ?? null) === 'drivers'
            && ($updated->roles()->where('role', PartyRoleType::Driver->value)->whereNull('deleted_at')->whereNull('deactivated_at')->exists());

        return redirect()
            ->route($returnToDrivers ? 'branch.drivers.show' : 'branch.parties.show', [$party])
            ->with('success', $returnToDrivers ? 'Driver updated.' : 'Party updated.');
    }

    public function destroy(Request $request, Party $party, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $branch, $party);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
            'return_to' => ['nullable', 'string', Rule::in(['drivers'])],
        ]);

        try {
            $this->parties->softDelete($actor, $party, $validated['remark']);
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'deleted']);
        }

        $returnRoute = ($validated['return_to'] ?? null) === 'drivers'
            ? 'branch.drivers.index'
            : 'branch.parties.index';

        return redirect()
            ->route($returnRoute)
            ->with('success', $returnRoute === 'branch.drivers.index' ? 'Driver deleted.' : 'Party deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function partyListPayload(Party $party): array
    {
        return [
            'id' => $party->id,
            'name' => $party->name,
            'short_name' => $party->short_name,
            'contact_person' => $party->contact_person,
            'contact_number' => $party->contact_number,
            'email' => $party->email,
            'gstin' => $party->gstin,
            'city' => $party->city,
            'state' => $party->state,
            'scope' => $party->branch_id === null ? 'shared' : 'branch',
            'status' => $party->trashed() ? 'deleted' : 'active',
            'roles' => $party->roles->pluck('role')->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function partyDetailPayload(Party $party): array
    {
        return [
            'id' => $party->id,
            'name' => $party->name,
            'short_name' => $party->short_name,
            'contact_person' => $party->contact_person,
            'contact_number' => $party->contact_number,
            'email' => $party->email,
            'gstin' => $party->gstin,
            'pan' => $party->pan,
            'bank_name' => $party->bank_name,
            'bank_account_holder_name' => $party->bank_account_holder_name,
            'bank_account_number_masked' => AccountNumberMask::mask($party->bank_account_number),
            'bank_ifsc' => $party->bank_ifsc,
            'bank_short_name' => $party->bank_short_name,
            'bank_beneficiary_id' => $party->bank_beneficiary_id,
            'is_gst_applicable' => (bool) $party->is_gst_applicable,
            'state' => $party->state,
            'state_id' => $party->state_id,
            'city' => $party->city,
            'city_id' => $party->city_id,
            'address' => $party->address,
            'scope' => $party->branch_id === null ? 'shared' : 'branch',
            'branch_id' => $party->branch_id,
            'roles' => $party->roles->map(fn (PartyRole $role): array => [
                'id' => $role->id,
                'role' => $role->role,
                'attributes' => $role->role_attributes ?? [],
                'deactivated_at' => $role->deactivated_at?->toIso8601String(),
                'is_active' => $role->deactivated_at === null,
            ])->values()->all(),
            'created_at' => $party->created_at?->toIso8601String(),
            'updated_at' => $party->updated_at?->toIso8601String(),
        ];
    }

    private function assertParty(Company $company, Branch $branch, Party $party): void
    {
        if ($party->company_id !== $company->id) {
            abort(404);
        }

        try {
            app(MasterAccess::class)
                ->assertRecordVisibleInBranch($company, $branch, Party::class, $party->id);
        } catch (MasterDomainException) {
            abort(404);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function partyFieldRules(): array
    {
        return [
            'short_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', new IndianMobileNumber],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'pan' => ['nullable', 'string', 'max:20'],
            'is_gst_applicable' => ['sometimes', 'nullable', 'boolean'],
            ...$this->geographyValidationRules(false),
            'address' => ['nullable', 'string', 'max:2000'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_holder_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'bank_ifsc' => ['nullable', 'string', 'max:16'],
            'bank_short_name' => ['nullable', 'string', 'max:255'],
            'bank_beneficiary_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizePartyAttributes(array $validated): array
    {
        $attributes = collect($validated)->except(['roles', 'remark', 'return_to'])->all();

        if (array_key_exists('is_gst_applicable', $attributes) && $attributes['is_gst_applicable'] === null) {
            unset($attributes['is_gst_applicable']);
        }

        if (array_key_exists('is_gst_applicable', $attributes)) {
            $attributes['is_gst_applicable'] = filter_var(
                $attributes['is_gst_applicable'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? (bool) $attributes['is_gst_applicable'];
        }

        return $this->withResolvedGeography($attributes);
    }

    /**
     * @param  list<array{role: string|\BackedEnum, attributes?: array<string, mixed>|null}>  $roles
     * @return list<array{role: string, attributes: array<string, mixed>}>
     */
    private function normalizeIncomingRoles(array $roles): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            $value = $role['role'];
            $roleValue = $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
            $normalized[] = [
                'role' => $roleValue,
                'attributes' => $this->normalizeIncomingRoleAttributes($role['attributes'] ?? []),
            ];
        }

        return $normalized;
    }

    /**
     * Coerce checkbox / string boolean fields from HTML forms.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeIncomingRoleAttributes(array $attributes): array
    {
        foreach (['declaration', 'tds_override'] as $boolKey) {
            if (! array_key_exists($boolKey, $attributes)) {
                continue;
            }

            $raw = $attributes[$boolKey];
            if (is_bool($raw)) {
                continue;
            }

            if (is_string($raw)) {
                $lower = strtolower(trim($raw));
                if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                    $attributes[$boolKey] = true;
                } elseif (in_array($lower, ['0', 'false', 'no', 'off', ''], true)) {
                    $attributes[$boolKey] = false;
                }
            }
        }

        return $attributes;
    }
}
