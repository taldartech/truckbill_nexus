<?php

use Database\Seeders\InternalSuperAdminSeeder;

/**
 * Initial Internal Team Super Admin bootstrap credentials.
 *
 * Used only by {@see InternalSuperAdminSeeder}.
 * Never commit real passwords. Seeder will not reset an existing Super Admin password.
 */
return [
    'name' => env('INTERNAL_SUPER_ADMIN_NAME', 'Super Admin'),
    'email' => env('INTERNAL_SUPER_ADMIN_EMAIL'),
    'password' => env('INTERNAL_SUPER_ADMIN_PASSWORD'),
];
