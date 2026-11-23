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
 * Dedicated zero-access screen (PO-DEC-P2-REVIEW-002).
 */
class WorkspaceNoAccessController extends Controller
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

        if ($this->navigation->hasAnyAccessibleCompany($user)) {
            return redirect()->route('workspace.landing');
        }

        return Inertia::render('workspace/no-access', [
            'userName' => $user->name,
            'supportHint' => 'Contact your company Owner or TruckBill support if you believe you should have access.',
            'canCreateCompany' => $user->isCustomer() && $user->isActive(),
            'links' => [
                'createCompany' => route('owner.companies.create'),
            ],
        ]);
    }
}
