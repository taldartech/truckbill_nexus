<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\LocalDemoWorkspaceSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes Playwright auth fixture for Broker journeys on the local dev database.
 * Uses Demo Alpha owner credentials from LOCAL_DEMO_PASSWORD (never overwrites hashes).
 */
#[Signature('e2e:ensure-broker-journey')]
#[Description('Write Broker Playwright auth fixture for Demo Logistics Alpha (non-production only)')]
class E2eEnsureBrokerJourneyCommand extends Command
{
    private const FIXTURE_RELATIVE = 'storage/app/e2e/broker-journey-auth.json';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: e2e:ensure-broker-journey must not run in production.');

            return self::FAILURE;
        }

        $password = trim((string) config('local_demo.password', ''));

        if ($password === '') {
            $this->error('Set LOCAL_DEMO_PASSWORD in .env, then run LocalDemoWorkspaceSeeder.');

            return self::FAILURE;
        }

        $company = Company::query()
            ->where('name', LocalDemoWorkspaceSeeder::COMPANY_A_NAME)
            ->whereNull('deactivated_at')
            ->first();

        if ($company === null) {
            if (filter_var(env('E2E_ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
                $this->warn('Demo Logistics Alpha missing — running LocalDemoWorkspaceSeeder (E2E_ALLOW_DEMO_SEED).');
                $this->call('db:seed', ['--class' => LocalDemoWorkspaceSeeder::class, '--no-interaction' => true]);
                $company = Company::query()
                    ->where('name', LocalDemoWorkspaceSeeder::COMPANY_A_NAME)
                    ->whereNull('deactivated_at')
                    ->first();
            }

            if ($company === null) {
                $this->error('Demo Logistics Alpha not found. Set E2E_ALLOW_DEMO_SEED=true and LOCAL_DEMO_PASSWORD, or seed manually.');

                return self::FAILURE;
            }
        }

        $owner = User::query()->find($company->owner_user_id);

        if ($owner === null || $owner->email !== LocalDemoWorkspaceSeeder::OWNER_A_EMAIL) {
            $this->error('Demo Alpha owner persona is missing. Re-run LocalDemoWorkspaceSeeder.');

            return self::FAILURE;
        }

        $head = $company->branches()
            ->whereNull('deactivated_at')
            ->orderBy('created_at')
            ->firstOrFail();

        $payload = [
            'email' => LocalDemoWorkspaceSeeder::OWNER_A_EMAIL,
            'password' => $password,
            'company_name' => LocalDemoWorkspaceSeeder::COMPANY_A_NAME,
            'company_id' => $company->id,
            'branch_id' => $head->id,
            'seeded_at' => now()->toIso8601String(),
        ];

        $path = base_path(self::FIXTURE_RELATIVE);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info('Broker journey Playwright fixture written (Demo Alpha owner).');
        $this->line('  email: '.LocalDemoWorkspaceSeeder::OWNER_A_EMAIL);
        $this->line('  fixture: '.self::FIXTURE_RELATIVE);
        $this->line('  (Password is LOCAL_DEMO_PASSWORD from .env — demo users were seeded with the same value.)');

        return self::SUCCESS;
    }
}
