<?php

namespace App\Http\Controllers\Workspace;

use App\Domains\Company\WorkspaceNavigationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unified company selection (PO-P2-DESIGN-SWITCHER / PO-DEC-P2-REVIEW-003).
 */
class SelectCompanyController extends Controller
{
    public function __construct(
        private readonly WorkspaceNavigationService $navigation,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isInternal()) {
            return redirect()->route('dashboard');
        }

        $companies = $this->navigation->accessibleCompanies($user);
        if ($companies->isEmpty()) {
            return redirect()->route('workspace.no-access');
        }

        $owned = $this->navigation->ownedCompanies($user);
        $showOwnedSelection = $owned->count() > 1 && $this->navigation->resolveMruOwnedCompany($user) === null;

        $list = $showOwnedSelection
            ? $companies->where('is_owner', true)->values()
            : $companies;

        return Inertia::render('workspace/select-company', [
            'companies' => $list,
            'mode' => $showOwnedSelection ? 'owned' : 'unified',
            'heading' => $showOwnedSelection
                ? 'Select a company to manage'
                : 'Select a company',
            'description' => $showOwnedSelection
                ? 'Choose which owned company to open in the Owner Panel.'
                : 'Owner companies open the Owner Panel. Member companies continue to branch selection.',
            'canCreateCompany' => $user->isCustomer() && $user->isActive(),
            'links' => [
                'createCompany' => route('owner.companies.create'),
            ],
        ]);
    }
}
