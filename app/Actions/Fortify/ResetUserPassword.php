<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Domains\Auth\WebSessionService;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(
        private readonly WebSessionService $webSessions = new WebSessionService,
    ) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();

        $this->webSessions->revokeAllSessionsForSecurityEvent(
            $user,
            'Password reset completed; all web sessions revoked',
        );
    }
}
