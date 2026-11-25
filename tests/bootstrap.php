<?php

/**
 * Pest/PHPUnit bootstrap — isolate the test process from a developer shell that
 * sourced .env (SESSION_DRIVER=redis, DB_DATABASE=nexus, APP_ENV=local, etc.).
 *
 * PHPUnit <env force="true"> updates getenv()/$_ENV, but Laravel's env() prefers
 * $_SERVER. A shell-exported SESSION_DRIVER=redis caused CSRF 419 noise and could
 * point RefreshDatabase at the wrong database.
 */
require dirname(__DIR__).'/vendor/autoload.php';

$forced = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'SESSION_DOMAIN' => '',
    'AUTH_CUSTOMER_SESSION_TEST_CLAIM' => 'true',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

// Never allow Pest to mutate the live local application database (`nexus`).
// CI MySQL uses `nexus_mysql_test` / disposable names — leave those alone.
// Optional disposable Postgres: NEXUS_TEST_DB_PORT (e.g. Docker mapped to 5433) when
// the host cannot create `nexus_test` on the developer's Postgres instance.
$configuredDatabase = (string) ($_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');
if ($configuredDatabase === '' || $configuredDatabase === 'nexus') {
    $forced['DB_CONNECTION'] = 'pgsql';
    $forced['DB_HOST'] = '127.0.0.1';
    $forced['DB_DATABASE'] = 'nexus_test';
    $forced['DB_URL'] = '';

    $disposablePort = (string) (getenv('NEXUS_TEST_DB_PORT') ?: ($_SERVER['NEXUS_TEST_DB_PORT'] ?? ''));
    if ($disposablePort !== '') {
        $forced['DB_PORT'] = $disposablePort;
        $forced['DB_USERNAME'] = (string) (getenv('NEXUS_TEST_DB_USERNAME') ?: ($_SERVER['NEXUS_TEST_DB_USERNAME'] ?? 'nexus_user'));
        $forced['DB_PASSWORD'] = (string) (getenv('NEXUS_TEST_DB_PASSWORD') ?: ($_SERVER['NEXUS_TEST_DB_PASSWORD'] ?? 'secret'));
    } else {
        $forced['DB_PORT'] = '5432';
    }
}

foreach ($forced as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
