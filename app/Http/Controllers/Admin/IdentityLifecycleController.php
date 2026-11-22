<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Identity\Exceptions\IdentityTypeBoundaryException;
use App\Domains\Identity\Exceptions\LastSuperAdminProtectedException;
use App\Domains\Identity\IdentityLifecycleService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Internal Super Admin identity deactivation / reactivation (P2-WS-6).
 * Target user is resolved from the route.
 */
class IdentityLifecycleController extends Controller
{
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

        return back();
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

        return back();
    }

    private function assertSuperAdmin(Request $request): void
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isSuperAdmin() || ! $actor->isActive()) {
            abort(403, 'Only an active Super Admin may manage identity lifecycle.');
        }
    }
}
