<?php

namespace App\Console\Commands;

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Identity\Enums\IdentityType;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Deterministic non-production fixtures for company-create Playwright journeys.
 */
#[Signature('e2e:seed-company-create {--password= : Persona password (defaults to local_demo.password or \"password\")}')]
#[Description('Seed isolated Customer/Internal personas for company-create E2E (non-production only)')]
class E2eSeedCompanyCreateCommand extends Command
{
    public const OWNER_EMAIL = 'e2e.owner@truckbill.test';

    public const NO_COMPANY_EMAIL = 'e2e.nocompany@truckbill.test';

    public const INTERNAL_EMAIL = 'e2e.internal@truckbill.test';

    public function handle(CompanyWorkspaceService $workspace): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: e2e:seed-company-create must not run in production.');

            return self::FAILURE;
        }

        $password = trim((string) ($this->option('password') ?: config('local_demo.password') ?: 'password'));
        if ($password === '') {
            throw new RuntimeException('Provide --password or set LOCAL_DEMO_PASSWORD for e2e seeding.');
        }

        GstRate::query()->firstOrCreate(
            ['code' => 'GST18', 'version' => 1],
            [
                'name' => 'GST 18%',
                'rate_percent' => '18.000',
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'is_active' => true,
            ],
        );

        $owner = $this->ensureUser(self::OWNER_EMAIL, 'E2E Owner', $password, IdentityType::Customer);
        $ownsCompany = Company::query()->where('owner_user_id', $owner->id)->exists();
        if (! $ownsCompany) {
            $workspace->createCompany($owner, 'E2E Existing Co', 'Head Office');
        }

        $noCompany = $this->ensureUser(self::NO_COMPANY_EMAIL, 'E2E No Company', $password, IdentityType::Customer);
        $this->resetZeroCompanyPersona($noCompany);

        $this->ensureUser(self::INTERNAL_EMAIL, 'E2E Internal', $password, IdentityType::Internal);

        $this->info('E2E company-create personas ready.');
        $this->line('  owner: '.self::OWNER_EMAIL);
        $this->line('  no-company: '.self::NO_COMPANY_EMAIL);
        $this->line('  internal: '.self::INTERNAL_EMAIL);

        return self::SUCCESS;
    }

    private function ensureUser(string $email, string $name, string $password, IdentityType $type): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'identity_type' => $type,
                'is_super_admin' => false,
                'deactivated_at' => null,
            ]);
            $user->save();

            return $user;
        }

        $user->forceFill([
            'name' => $name,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password' => Hash::make($password),
            'identity_type' => $type,
            'is_super_admin' => false,
            'deactivated_at' => null,
        ])->save();

        return $user->refresh();
    }

    /**
     * Scenario B requires a Customer with no accessible companies.
     * Owned companies remain listed even when deactivated, so reassign ownership
     * to the E2E owner persona and deactivate memberships for this user.
     */
    private function resetZeroCompanyPersona(User $user): void
    {
        $graveyardOwner = User::query()->where('email', self::OWNER_EMAIL)->first();
        if ($graveyardOwner === null) {
            return;
        }

        // Bypass Company::updating owner guard — E2E fixture hygiene only.
        DB::table('companies')
            ->where('owner_user_id', $user->id)
            ->update([
                'owner_user_id' => $graveyardOwner->id,
                'deactivated_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('company_memberships')
            ->where('user_id', $user->id)
            ->whereNull('deactivated_at')
            ->update([
                'deactivated_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
