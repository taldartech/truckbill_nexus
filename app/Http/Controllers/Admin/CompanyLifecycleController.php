<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Internal Super Admin company deactivation / reactivation (P2-WS-6).
 * Company is resolved from the route — never from client tenant fields.
 */
class CompanyLifecycleController extends Controller
{
    public function deactivate(
        Request $request,
        Company $company,
        CompanyWorkspaceService $workspace,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $workspace->deactivateCompany($actor, $company, $validated['remark']);
        } catch (CompanyDomainException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return back();
    }

    public function reactivate(
        Request $request,
        Company $company,
        CompanyWorkspaceService $workspace,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $workspace->reactivateCompany($actor, $company, $validated['remark']);
        } catch (CompanyDomainException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return back();
    }
}
