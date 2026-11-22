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
 * Honest deferred Owner Panel entry points (PO-P2-DESIGN-DASH Region B).
 * Full CRUD remains outside this P2-WS-8 slice — do not invent write workflows here.
 */
class OwnerDeferredController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
    ) {}

    public function users(Request $request, Company $company): Response
    {
        return $this->render($request, $company, 'users', 'Users & memberships',
            'User invitation, membership, and role assignment product screens are approved as Owner Panel IA but are not delivered as full CRUD in this P2-WS-8 slice. Backend invitation/membership services already exist. Separate Product Owner authorization is required before implementing those screens.');
    }

    public function settings(Request $request, Company $company): Response
    {
        return $this->render($request, $company, 'settings', 'Company settings',
            'Company profile / settings hub screens are reserved in Owner Panel IA. Detailed company master editing is not implemented in this P2-WS-8 slice.');
    }

    public function audit(Request $request, Company $company): Response
    {
        return $this->render($request, $company, 'audit', 'Company audit',
            'Company-level audit entry is reserved in Owner Panel IA. A read-oriented audit product screen is not implemented in this P2-WS-8 slice.');
    }

    private function render(Request $request, Company $company, string $key, string $title, string $message): Response
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

        return Inertia::render('owner/deferred', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->isActive() ? 'Active' : 'Deactivated',
            ],
            'ownerPanelScope' => $scope,
            'section' => [
                'key' => $key,
                'title' => $title,
                'message' => $message,
                'status' => 'deferred',
            ],
            'links' => [
                'dashboard' => route('owner.dashboard', ['company' => $company]),
            ],
        ]);
    }
}
