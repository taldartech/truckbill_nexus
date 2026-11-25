<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Reports\PartloadPendingDeliveryService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartloadPendingDeliveryController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly PartloadPendingDeliveryService $pendingDelivery,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPartloadModule($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_DR_VIEW);

        try {
            $snapshot = $this->pendingDelivery->snapshot($company, $branch, $actor);
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $isOwner = (int) $actor->id === (int) $company->owner_user_id;
        $canCreate = $isOwner || $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::PARTLOAD_DR_CREATE,
            $company,
            $branch,
        );

        return Inertia::render('partload/pending-delivery/index', [
            'permissions' => [
                'can_create_dr' => $canCreate,
            ],
            'rows' => $snapshot['rows'],
            'totals' => $snapshot['totals'],
            'urls' => [
                'index' => route('branch.partload.pending-delivery.index', absolute: false),
                'in_transit' => route('branch.partload.in-transit.index', absolute: false),
                'create_dr' => route('branch.partload.delivery-receipts.create', absolute: false),
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
