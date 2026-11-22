<?php

namespace App\Http\Controllers\Settings;

use App\Domains\Auth\WebSessionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        return Inertia::render('settings/security', $props);
    }

    /**
     * Update the user's password and revoke other web sessions (P2-WS-4).
     */
    public function update(PasswordUpdateRequest $request, WebSessionService $webSessions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update([
            'password' => $request->password,
        ]);

        $webSessions->revokeOtherSessionsForSecurityEvent(
            $user,
            $request->session()->getId(),
            'Password changed; other web sessions revoked',
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
