<?php

namespace App\Http\Controllers;

use App\Domains\Invitation\Exceptions\InvitationException;
use App\Domains\Invitation\InvitationService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InvitationAcceptanceController extends Controller
{
    public function show(string $token, InvitationService $invitations): Response|RedirectResponse
    {
        $invitation = $invitations->findPendingByPlainToken($token);

        if ($invitation === null) {
            return redirect()->route('login')
                ->withErrors(['email' => 'This invitation is invalid, expired, or already used.']);
        }

        $existingCustomer = User::query()
            ->where('email', $invitation->email)
            ->where('identity_type', 'customer')
            ->exists();

        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'email' => $invitation->email,
            'requiresRegistration' => ! $existingCustomer,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, string $token, InvitationService $invitations): RedirectResponse
    {
        $invitation = $invitations->findPendingByPlainToken($token);

        if ($invitation === null) {
            throw ValidationException::withMessages([
                'token' => 'This invitation is invalid, expired, or already used.',
            ]);
        }

        $existingCustomer = User::query()
            ->where('email', $invitation->email)
            ->where('identity_type', 'customer')
            ->exists();

        $rules = [];
        if (! $existingCustomer) {
            $rules = [
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ];
        }

        $validated = $request->validate($rules);

        if (! $existingCustomer) {
            $validated['password_confirmation'] = $request->input('password_confirmation');
        }

        try {
            $invitations->accept($token, $validated);
        } catch (InvitationException $e) {
            throw ValidationException::withMessages(['token' => $e->getMessage()]);
        }

        return redirect()->route('login')
            ->with('status', 'Invitation accepted. You can sign in with your account.');
    }
}
