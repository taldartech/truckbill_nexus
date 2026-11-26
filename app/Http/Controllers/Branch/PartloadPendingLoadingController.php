<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Reports\PartloadPendingLoadingService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartloadPendingLoadingController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly PartloadPendingLoadingService $pendingLoading,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPartloadModule($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_VIEW);

        $fromStationId = $request->string('from_station_id')->toString() ?: null;
        $toStationId = $request->string('to_station_id')->toString() ?: null;

        try {
            $snapshot = $this->pendingLoading->snapshot(
                company: $company,
                branch: $branch,
                actor: $actor,
                fromStationId: $fromStationId,
                toStationId: $toStationId,
            );
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $isOwner = (int) $actor->id === (int) $company->owner_user_id;
        $canCreate = $isOwner || $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::PARTLOAD_CHALLAN_CREATE,
            $company,
            $branch,
        );

        $createQuery = array_filter([
            'from_station_id' => $fromStationId,
            'to_station_id' => $toStationId,
        ], fn (?string $value): bool => filled($value));

        return Inertia::render('partload/pending-loading/index', [
            'filters' => [
                'from_station_id' => $fromStationId,
                'to_station_id' => $toStationId,
            ],
            'permissions' => [
                'can_view' => true,
                'can_create_challan' => $canCreate,
            ],
            'rows' => $snapshot['rows'],
            'totals' => $snapshot['totals'],
            'stationOptions' => Station::query()
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('owner_user_id', $company->owner_user_id)
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'city', 'state'])
                ->map(fn (Station $station): array => [
                    'id' => $station->id,
                    'name' => $station->name,
                    'city' => $station->city,
                    'state' => $station->state,
                ])->values()->all(),
            'urls' => [
                'index' => route('branch.partload.pending-loading.index', absolute: false),
                'create_challan' => route('branch.partload.challans.create', absolute: false),
                'create_challan_filtered' => route('branch.partload.challans.create', $createQuery, absolute: false),
                'booking_register' => route('branch.lorry-receipts.index', [
                    'workflow_context' => 'partload',
                ], absolute: false),
            ],
        ]);
    }

    private function assertPartloadModule(Company $company): void
    {
        if (! $this->moduleEntitlements->isEntitled($company, SubscriptionModule::Partload)) {
            abort(403, 'Partload module is not entitled for this company.');
        }
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
