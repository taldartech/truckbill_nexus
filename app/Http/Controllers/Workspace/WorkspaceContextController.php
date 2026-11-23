<?php

namespace App\Http\Controllers\Workspace;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Explicit, CSRF-protected workspace context switches (PO session routing).
 * Company/branch come from route model binding — never request body tenant IDs
 * (RejectClientTenantIdentifiers).
 */
class WorkspaceContextController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
    ) {}

    public function company(Request $request, Company $company): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'redirect' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $this->access->isCompanyOwner($user, $company)) {
            abort(403, 'Only the designated company owner may open management company context.');
        }

        WorkspaceSession::put($request, $company->id, null);

        return redirect()->to($this->safeRedirect(
            $validated['redirect'] ?? null,
            route('owner.companies.index'),
        ));
    }

    public function branch(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'redirect' => ['nullable', 'string', 'max:500'],
        ]);

        if ($branch->company_id !== $company->id) {
            abort(404);
        }

        try {
            $this->access->activateContext($user, $company, $branch);
        } catch (CompanyAccessException) {
            abort(403, 'Branch access denied.');
        }

        WorkspaceSession::put($request, $company->id, $branch->id);

        return redirect()->to($this->safeRedirect(
            $validated['redirect'] ?? null,
            route('branch.dashboard'),
        ));
    }

    public function clear(Request $request): RedirectResponse
    {
        WorkspaceSession::clear($request);

        return redirect()->route('workspace.landing');
    }

    private function safeRedirect(?string $redirect, string $fallback): string
    {
        if ($redirect === null || $redirect === '') {
            return $fallback;
        }

        if (! str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            throw ValidationException::withMessages([
                'redirect' => 'Invalid redirect target.',
            ]);
        }

        return $redirect;
    }
}
