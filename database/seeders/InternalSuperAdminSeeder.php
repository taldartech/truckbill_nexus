<?php

namespace Database\Seeders;

use App\Domains\Identity\Enums\IdentityType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Bootstraps the first Internal Team Super Admin for a fresh installation.
 *
 * Super Admin privilege is the protected `users.is_super_admin` flag (PRD §6),
 * not an ordinary Permission Master role. This seeder never creates a Customer
 * Owner, Company, or Branch.
 *
 * Idempotent:
 * - If any active Internal Super Admin already exists, does nothing (no password reset).
 * - If the configured email already exists as Internal Super Admin, preserves it.
 * - Never prints the password.
 */
class InternalSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->activeSuperAdminExists()) {
            $this->command?->info('Internal Super Admin already present — skipping bootstrap.');

            return;
        }

        $name = trim((string) config('internal_super_admin.name', 'Super Admin'));
        $email = strtolower(trim((string) config('internal_super_admin.email', '')));
        $password = (string) config('internal_super_admin.password', '');

        if ($email === '' || $password === '') {
            throw new RuntimeException(
                'INTERNAL_SUPER_ADMIN_EMAIL and INTERNAL_SUPER_ADMIN_PASSWORD are required to create the initial Internal Super Admin. Set them in the environment (see .env.example), then re-run db:seed.'
            );
        }

        $validated = Validator::make(
            [
                'name' => $name !== '' ? $name : 'Super Admin',
                'email' => $email,
                'password' => $password,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', Password::defaults()],
            ],
        )->validate();

        $existing = User::query()->where('email', $validated['email'])->first();

        if ($existing !== null) {
            if ($existing->identity_type !== IdentityType::Internal) {
                throw ValidationException::withMessages([
                    'email' => 'Configured INTERNAL_SUPER_ADMIN_EMAIL belongs to a non-Internal identity and cannot be promoted by the seeder.',
                ]);
            }

            // Promote existing Internal identity without resetting the password.
            $existing->forceFill([
                'name' => $validated['name'],
                'is_super_admin' => true,
                'email_verified_at' => $existing->email_verified_at ?? now(),
                'deactivated_at' => null,
            ])->save();

            $this->command?->info('Existing Internal user promoted to Super Admin (password unchanged).');

            return;
        }

        $user = new User;
        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'identity_type' => IdentityType::Internal,
            'is_super_admin' => true,
            'email_verified_at' => now(),
            'deactivated_at' => null,
        ]);
        $user->save();

        $this->command?->info('Initial Internal Super Admin created.');
    }

    private function activeSuperAdminExists(): bool
    {
        return User::query()
            ->where('identity_type', IdentityType::Internal->value)
            ->where('is_super_admin', true)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->exists();
    }
}
