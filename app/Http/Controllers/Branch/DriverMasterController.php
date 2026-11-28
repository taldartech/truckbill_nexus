<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Document\DocumentParentTypes;
use App\Domains\Document\DocumentTypeCatalog;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\Exceptions\PartyDomainException;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentAttachment;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\State;
use App\Support\Presentation\AccountNumberMask;
use App\Support\Presentation\FreightAmountPresentation;
use App\Support\Presentation\PartyProfilePresentation;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dedicated Driver Master register reusing the unified Party identity with the Driver role.
 */
class DriverMasterController extends Controller
{
    use AuthorizesBranchPanel;
    use PresentsDocumentDrawer;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly PartyService $parties,
        private readonly DocumentAttachmentService $documents,
        private readonly FreightAmountPresentation $freightPresentation = new FreightAmountPresentation,
        private readonly PartyProfilePresentation $profilePresentation = new PartyProfilePresentation,
        private readonly ?CompanyCapabilityResolver $capabilities = null,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
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
                role: PartyRoleType::Driver->value,
                perPage: (int) ($filters['per_page'] ?? 15),
                stateId: $filters['state_id'] ?? null,
                city: $filters['city'] ?? null,
                deleted: $status === 'deleted',
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        /** @var list<Party> $items */
        $items = $page->items();
        $licenceFallbacks = $this->licenceDocumentFallbacks(
            $company,
            array_map(static fn (Party $party): string => $party->id, $items),
        );

        return Inertia::render('branch/masters/drivers/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'state_id' => $filters['state_id'] ?? '',
                'city' => $filters['city'] ?? '',
                'status' => $status,
            ],
            'stateOptions' => State::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (State $state): array => ['value' => $state->id, 'label' => $state->name])
                ->all(),
            'drivers' => [
                'data' => array_map(
                    fn (Party $party): array => $this->driverRowPayload($party, $licenceFallbacks[$party->id] ?? null),
                    $items,
                ),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.drivers.index', absolute: false),
                'create' => route('branch.drivers.create', absolute: false),
                'parties_index' => route('branch.parties.index', absolute: false),
            ]),
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_create']) {
            abort(403, 'Not authorized to create drivers.');
        }

        return Inertia::render('branch/masters/parties/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'create',
            'party' => null,
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'roleOptions' => $this->partyRoleOptions($company),
            'defaultRoles' => [PartyRoleType::Driver->value],
            'defaultDriverType' => $this->capabilities()->allowsRead($company, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS)
                ? 'permanent'
                : 'market',
            'driverMasterContext' => true,
            'returnTo' => 'drivers',
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.drivers.index', absolute: false),
                'store' => route('branch.parties.store', absolute: false),
            ]),
        ]);
    }

    public function show(Request $request, Party $party, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertDriverParty($company, $branch, $party);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $this->parties->search($actor, $company, $branch, perPage: 1);
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        $party->load(['roles' => fn ($q) => $q->orderByDesc('created_at')]);
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

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
            'bookBalance' => null,
            'driverMasterContext' => true,
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
                'index' => route('branch.drivers.index', absolute: false),
                'edit' => route('branch.drivers.edit', [$party], absolute: false),
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
        $this->assertDriverParty($company, $branch, $party);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_edit']) {
            abort(403, 'Not authorized to edit drivers.');
        }

        $party->load(['roles' => fn ($q) => $q->whereNull('deactivated_at')]);

        return Inertia::render('branch/masters/parties/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'edit',
            'party' => [
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
            ],
            'roleOptions' => $this->partyRoleOptions($company),
            'driverMasterContext' => true,
            'returnTo' => 'drivers',
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.drivers.index', absolute: false),
                'show' => route('branch.drivers.show', [$party], absolute: false),
                'update' => route('branch.parties.update', [$party], absolute: false),
            ]),
        ]);
    }

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
     * @param  array{license_number: string|null, license_expiry: string|null}|null  $docFallback
     * @return array<string, mixed>
     */
    private function driverRowPayload(Party $party, ?array $docFallback): array
    {
        $driverRole = $party->roles->first(
            static fn (PartyRole $role): bool => (string) $role->role === PartyRoleType::Driver->value
                && $role->deactivated_at === null,
        );
        $attrs = is_array($driverRole?->role_attributes) ? $driverRole->role_attributes : [];
        $licenseNumber = filled($attrs['license_number'] ?? null)
            ? (string) $attrs['license_number']
            : ($docFallback['license_number'] ?? null);

        $rawExpiry = filled($attrs['license_expiry'] ?? null)
            ? (string) $attrs['license_expiry']
            : ($docFallback['license_expiry'] ?? null);
        $formattedExpiry = null;
        if ($rawExpiry !== null && $rawExpiry !== '') {
            try {
                $formattedExpiry = CarbonImmutable::parse($rawExpiry)->format('d-m-Y');
            } catch (InvalidFormatException) {
                $formattedExpiry = $rawExpiry;
            }
        }

        return [
            'id' => $party->id,
            'name' => $party->name,
            'short_name' => $party->short_name,
            'contact_number' => $party->contact_number,
            'driver_type' => $attrs['driver_type'] ?? null,
            'license_number' => $licenseNumber,
            'license_expiry' => $formattedExpiry,
            'city' => $party->city,
            'state' => $party->state,
            'status' => $party->trashed() ? 'deleted' : 'active',
            'urls' => [
                'show' => route('branch.drivers.show', [$party], absolute: false),
                'edit' => route('branch.drivers.edit', [$party], absolute: false),
                'destroy' => route('branch.parties.destroy', [$party], absolute: false),
            ],
        ];
    }

    /**
     * Batch fallback to Driving Licence documents when role_attributes omits licence details.
     *
     * @param  list<string>  $partyIds
     * @return array<string, array{license_number: string|null, license_expiry: string|null}>
     */
    private function licenceDocumentFallbacks(Company $company, array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }

        $attachments = DocumentAttachment::query()
            ->where('company_id', $company->id)
            ->where('parent_type', DocumentParentTypes::PARTY)
            ->whereIn('parent_id', $partyIds)
            ->where('document_type', DocumentTypeCatalog::DRIVING_LICENCE)
            ->where('is_current', true)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get(['parent_id', 'document_number', 'expires_on']);

        $fallbacks = [];
        foreach ($attachments as $attachment) {
            if (isset($fallbacks[$attachment->parent_id])) {
                continue;
            }
            $fallbacks[$attachment->parent_id] = [
                'license_number' => filled($attachment->document_number) ? (string) $attachment->document_number : null,
                'license_expiry' => $attachment->expires_on?->toDateString(),
            ];
        }

        return $fallbacks;
    }

    private function assertDriverParty(Company $company, Branch $branch, Party $party): void
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

        $hasDriverRole = $party->roles()
            ->where('role', PartyRoleType::Driver->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->exists();

        if (! $hasDriverRole) {
            abort(404);
        }
    }
}
