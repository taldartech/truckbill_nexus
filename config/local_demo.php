<?php

/**
 * Opt-in local/testing demo workspace configuration.
 * Never used by DatabaseSeeder. Seeders refuse production.
 */
return [
    'password' => env('LOCAL_DEMO_PASSWORD'),
    'profile' => env('LOCAL_DEMO_PROFILE', 'standard'),
];
