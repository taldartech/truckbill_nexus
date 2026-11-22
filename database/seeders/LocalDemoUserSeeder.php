<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Legacy single-user local demo helper.
 *
 * Prefer LocalDemoWorkspaceSeeder for full workspace fixtures:
 *   LOCAL_DEMO_PASSWORD=... php artisan db:seed --class=LocalDemoWorkspaceSeeder
 *
 * Never called by DatabaseSeeder. Refuses outside local/testing.
 * Password must come from LOCAL_DEMO_PASSWORD (never committed).
 */
class LocalDemoUserSeeder extends Seeder
{
    public const DEMO_EMAIL = 'demo.local@example.com';

    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            $this->command?->error('LocalDemoUserSeeder is blocked outside local/testing.');

            return;
        }

        $password = trim((string) config('local_demo.password', ''));
        if ($password === '') {
            $this->command?->error('Set LOCAL_DEMO_PASSWORD before running LocalDemoUserSeeder.');

            return;
        }

        if (User::query()->where('email', self::DEMO_EMAIL)->exists()) {
            $this->command?->info('Local demo user already exists: '.self::DEMO_EMAIL);

            return;
        }

        User::factory()->customer()->create([
            'name' => 'Local Demo User',
            'email' => self::DEMO_EMAIL,
            'password' => $password,
            'email_verified_at' => now(),
        ]);

        $this->command?->warn(
            'Seeded local-only demo user '.self::DEMO_EMAIL.'. Prefer LocalDemoWorkspaceSeeder for full fixtures.'
        );
    }
}
