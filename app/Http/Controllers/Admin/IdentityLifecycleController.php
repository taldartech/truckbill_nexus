<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Identity\Exceptions\IdentityTypeBoundaryException;
use App\Domains\Identity\Exceptions\LastSuperAdminProtectedException;
use App\Domains\Identity\IdentityLifecycleService;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Internal Super Admin identity lifecycle screens (PO-P2-INTERNAL-UI-AUTH).
 * Target user is resolved from the route.
 */
class IdentityLifecycleController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        $actor = $this->assertSuperAdmin($request);

        $ownedCompanies = Company::query()
            ->where('owner_user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'deactivated_at']);

        return Inertia::render('admin/identities/show', [
            'identity' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'identity_type' => $user->identity_type?->value,
                'is_super_admin' => $user->is_super_admin,
                'is_active' => $user->isActive(),
                'deactivated_at' => $user->deactivated_at?->toIso8601String(),
                'is_self' => (int) $actor->id === (int) $user->id,
            ],
            'owned_companies' => $ownedCompanies->map(fn (Company $company): array => [
                'id' => $company->id,
                'name' => $company->name,
                'is_active' => $company->isActive(),
                'show_url' => route('admin.companies.show', $company),
            ])->values()->all(),
            'urls' => [
                'index' => $user->isInternal()
                    ? route('admin.internal-users.index')
                    : route('admin.companies.index'),
                'deactivate' => route('admin.identities.deactivate', $user),
                'reactivate' => route('admin.identities.reactivate', $user),
            ],
        ]);
    }

    public function deactivate(
        Request $request,
        User $user,
        IdentityLifecycleService $identities,
    ): RedirectResponse {
        $this->assertSuperAdmin($request);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $identities->deactivate($user, $validated['remark']);
        } catch (LastSuperAdminProtectedException|IdentityTypeBoundaryException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.identities.show', $user)
            ->with('success', 'Identity deactivated. Active sessions are revoked.');
    }

    public function reactivate(
        Request $request,
        User $user,
        IdentityLifecycleService $identities,
    ): RedirectResponse {
        $this->assertSuperAdmin($request);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $identities->reactivate($user, $validated['remark']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.identities.show', $user)
            ->with('success', 'Identity reactivated.');
    }

    private function assertSuperAdmin(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isSuperAdmin() || ! $actor->isActive()) {
            abort(403, 'Only an active Super Admin may manage identity lifecycle.');
        }

        return $actor;
    }
}
