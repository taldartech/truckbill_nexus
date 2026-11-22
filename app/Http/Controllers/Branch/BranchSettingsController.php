<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch-scoped preferences only (PO-DEC-P2-REVIEW-004).
 */
class BranchSettingsController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
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

        WorkspaceSession::put($request, $company->id, $branch->id);

        return Inertia::render('branch/settings', [
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
            'isOwner' => $this->access->isCompanyOwner($user, $company),
            'preferences' => [
                'note' => 'Branch-scoped preferences only. Company ownership, billing, and subscription administration remain in the Owner Panel.',
            ],
            'links' => [
                'dashboard' => route('branch.dashboard', ['company' => $company, 'branch' => $branch]),
                'owner_panel' => $this->access->isCompanyOwner($user, $company)
                    ? route('owner.dashboard', ['company' => $company])
                    : null,
            ],
        ]);
    }
}
