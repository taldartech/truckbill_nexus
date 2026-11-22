<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * High-level subscription presentation only (PO-P2-DESIGN-SUB-UX / PO-DEC-P2-REVIEW-001).
 * Does not invent trial/grace/restricted permissions or checkout actions.
 */
class OwnerSubscriptionController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
    ) {}

    public function __invoke(Request $request, Company $company): Response
    {
        /** @var User $user */
        $user = $request->user();

        $scope = $this->access->ownerPanelScope($user, $company);
        if ($scope === null) {
            abort(403, 'Owner Panel access denied.');
        }

        try {
            $this->access->activateContext($user, $company);
        } catch (CompanyAccessException) {
            abort(403, 'Owner Panel access denied.');
        }

        WorkspaceSession::put($request, $company->id);

        return Inertia::render('owner/subscription', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->isActive() ? 'Active' : 'Deactivated',
            ],
            'ownerPanelScope' => $scope,
            'subscription' => [
                'presentation' => 'unavailable',
                'message' => 'Billing catalogue and entitlement matrices are not available in Phase 2. Checkout and proration remain Phase 3. Exact trial/grace/restricted permissions remain Pending (AUD-CON-006 / TB-SUB-STATE-001).',
                'company_status' => $company->isActive() ? 'Active' : 'Deactivated',
                'actions_allowed' => false,
            ],
        ]);
    }
}
