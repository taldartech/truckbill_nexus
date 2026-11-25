<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Reports\PartloadInTransitService;
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

class PartloadInTransitController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly PartloadInTransitService $inTransit,
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
            $snapshot = $this->inTransit->snapshot(
                company: $company,
                branch: $branch,
                actor: $actor,
                fromStationId: $fromStationId,
                toStationId: $toStationId,
            );
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return Inertia::render('partload/in-transit/index', [
            'filters' => [
                'from_station_id' => $fromStationId,
                'to_station_id' => $toStationId,
            ],
            'rows' => $snapshot['rows'],
            'totals' => $snapshot['totals'],
            'stationOptions' => Station::query()
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('owner_user_id', $company->owner_user_id)
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'code'])
                ->map(fn (Station $station): array => [
                    'id' => $station->id,
                    'name' => $station->name,
                    'code' => $station->code,
                ])->values()->all(),
            'urls' => [
                'index' => route('branch.partload.in-transit.index', absolute: false),
                'challans' => route('branch.partload.challans.index', absolute: false),
                'pending_loading' => route('branch.partload.pending-loading.index', absolute: false),
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
