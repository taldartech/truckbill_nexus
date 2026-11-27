<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\BrokerOperationalOverview;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Company\WorkspaceNavigationService;
use App\Domains\OwnFleet\Trip\OwnFleetOperationalOverview;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchDashboardController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly WorkspaceNavigationService $navigation,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly ?CompanyCapabilityResolver $capabilities = null,
        private readonly OwnFleetOperationalOverview $ownFleetOverview = new OwnFleetOperationalOverview,
        private readonly BrokerOperationalOverview $brokerOverview = new BrokerOperationalOverview,
    ) {}

    private function capabilities(): CompanyCapabilityResolver
    {
        return $this->capabilities ?? app(CompanyCapabilityResolver::class);
    }

    public function __invoke(Request $request, Company $company, Branch $branch): Response
    {
        if ($branch->company_id !== $company->id) {
            abort(404);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $this->access->activateContext($user, $company, $branch);
        } catch (CompanyAccessException) {
            abort(403, 'Branch Panel access denied.');
        }

        $isOwner = $this->access->isCompanyOwner($user, $company);

        if (! $this->access->canViewBranchOperationsPanel($user, $company, $branch, $this->authorizer)) {
            abort(403, 'Not authorized to view Branch Panel.');
        }

        WorkspaceSession::sync($request, $company->id, $branch->id);
        $siblingBranches = $this->navigation->accessibleBranches($user, $company);

        $ownFleet = $this->ownFleetOverview->forBranch($company, $branch, $user);
        $broker = $this->brokerOverview->forBranch($company, $branch, $user);
        $nav = $this->capabilities()->navigationForUser($user, $company, $branch, $this->authorizer, $this->access);

        $modules = $this->moduleShortcuts($nav, $broker);

        return Inertia::render('branch/dashboard', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->isActive() ? 'Active' : 'Deactivated',
            ],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'status' => $branch->isActive() ? 'Active' : 'Deactivated',
            ],
            'isOwner' => $isOwner,
            'modules' => $modules,
            'ownFleet' => $ownFleet,
            'broker' => $broker,
            'siblingBranches' => $siblingBranches,
            'links' => [
                'dashboard' => route('branch.dashboard', absolute: false),
                'settings' => route('branch.settings', absolute: false),
                'owner_panel' => $isOwner
                    ? route('owner.home', absolute: false)
                    : null,
                'select_branch' => $siblingBranches->count() > 1
                    ? route('workspace.branches.select', ['company' => $company], absolute: false)
                    : null,
                'bilty_register' => $nav['bilty_register']
                    ? route('branch.lorry-receipts.index', absolute: false)
                    : null,
                'create_bilty' => $nav['create_bilty']
                    ? route('branch.lorry-receipts.create', absolute: false)
                    : null,
            ],
        ]);
    }

    /**
     * @param  array<string, bool>  $nav
     * @param  array<string, mixed>|null  $broker
     * @return list<array{key: string, label: string, href: string, description: string}>
     */
    private function moduleShortcuts(
        array $nav,
        ?array $broker,
    ): array {

        $modules = [];

        if ($nav['bilty_register']) {
            $modules[] = [
                'key' => 'bilty_register',
                'label' => 'Bilty Register',
                'href' => route('branch.lorry-receipts.index', absolute: false),
                'description' => 'Primary operational entry for subscribed Bilty workflows',
            ];
        }

        if ($nav['create_bilty']) {
            $modules[] = [
                'key' => 'create_bilty',
                'label' => 'Create Bilty',
                'href' => route('branch.lorry-receipts.create', absolute: false),
                'description' => 'Create a Bilty under the company’s subscribed workflows',
            ];
        }

        if ($nav['ownfleet_vehicle_drivers']) {
            $modules[] = [
                'key' => 'vehicle_drivers',
                'label' => 'Vehicle Drivers',
                'href' => route('branch.vehicle-drivers.index', absolute: false),
                'description' => 'OwnFleet primary driver assignments and daily attendance',
            ];
        }

        if ($nav['broker_lorry_hires']) {
            $modules[] = [
                'key' => 'broker_hires',
                'label' => 'Lorry Hires',
                'href' => (string) ($broker['links']['lorry_hires'] ?? route('branch.broker.lorry-hires.index', absolute: false)),
                'description' => 'Market vehicle transporter hire and settlement',
            ];
        }

        if ($nav['partload_challans']) {
            $modules[] = [
                'key' => 'partload_challans',
                'label' => 'Partload Challans',
                'href' => route('branch.partload.challans.index', absolute: false),
                'description' => 'Partload consolidation, challan dispatch, inward and delivery',
            ];
        }

        return $modules;
    }
}
