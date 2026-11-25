<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\BrokerOperationalOverview;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Company\WorkspaceNavigationService;
use App\Domains\OwnFleet\Trip\OwnFleetOperationalOverview;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
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
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
        private readonly OwnFleetOperationalOverview $ownFleetOverview = new OwnFleetOperationalOverview,
        private readonly BrokerOperationalOverview $brokerOverview = new BrokerOperationalOverview,
    ) {}

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

        $modules = $this->moduleShortcuts($company, $branch, $user, $broker);

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
                'bilty_register' => route('branch.lorry-receipts.index', absolute: false),
                'create_bilty' => route('branch.lorry-receipts.create', absolute: false),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $broker
     * @return list<array{key: string, label: string, href: string, description: string}>
     */
    private function moduleShortcuts(
        Company $company,
        Branch $branch,
        User $user,
        ?array $broker,
    ): array {
        $modules = [];

        if ($this->authorizer->allowsCustomer($user, PermissionCodes::LR_VIEW, $company, $branch)) {
            $modules[] = [
                'key' => 'bilty_register',
                'label' => 'Bilty Register',
                'href' => route('branch.lorry-receipts.index', absolute: false),
                'description' => 'Primary operational entry for Own and Market vehicles',
            ];
            $modules[] = [
                'key' => 'create_bilty',
                'label' => 'Create Bilty',
                'href' => route('branch.lorry-receipts.create', absolute: false),
                'description' => 'Select Own or Market vehicle — workflow follows ownership',
            ];
        }

        if (
            $broker !== null
            && ($broker['can_view_hires'] ?? false)
            && $this->moduleEntitlements->isEntitled($company, SubscriptionModule::Broker)
        ) {
            $modules[] = [
                'key' => 'broker_hires',
                'label' => 'Lorry Hires',
                'href' => (string) ($broker['links']['lorry_hires'] ?? route('branch.broker.lorry-hires.index', absolute: false)),
                'description' => 'Market vehicle transporter hire and settlement',
            ];
        }

        return $modules;
    }
}
