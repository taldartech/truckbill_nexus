<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Explicit local/development demo Customer identity.
 *
 * Never called by DatabaseSeeder. Opt-in only:
 *   php artisan db:seed --class=LocalDemoUserSeeder
 *
 * Refuses to run outside local/testing environments.
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

        if (User::query()->where('email', self::DEMO_EMAIL)->exists()) {
            $this->command?->info('Local demo user already exists: '.self::DEMO_EMAIL);

            return;
        }

        User::factory()->customer()->create([
            'name' => 'Local Demo User',
            'email' => self::DEMO_EMAIL,
            'password' => 'local-demo-only-change-me',
            'email_verified_at' => now(),
        ]);

        $this->command?->warn(
            'Seeded local-only demo user '.self::DEMO_EMAIL.' (not used by default DatabaseSeeder).'
        );
    }
}
