<?php

declare(strict_types=1);

/**
 * Scenario-level legacy test catalog (read-only legacy sources).
 *
 * Usage: php scripts/extract-legacy-scenarios.php > docs/engineering/LEGACY_SCENARIO_CATALOG.json
 */
$legacyRoot = dirname(__DIR__).'/legacy';
$apps = [
    'Broker' => 'truckbill_broker',
    'OwnFleet' => 'truckbill_ownfleet',
    'Partload' => 'truckbill_partload',
    'Admin' => 'truckbill_admin',
];

$catalog = [
    'generated_at' => (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
    'nexus_head' => trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')),
    'scenarios' => [],
];

$seq = 0;

foreach ($apps as $label => $dir) {
    $testsDir = $legacyRoot.'/'.$dir.'/tests';
    if (! is_dir($testsDir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        if (str_ends_with($path, 'TestCase.php') || str_ends_with($path, 'CreatesApplication.php') || str_ends_with($path, 'Pest.php')) {
            continue;
        }

        $relative = str_replace($testsDir.'/', '', $path);
        $contents = file_get_contents($path) ?: '';
        $scenarios = [];

        if (preg_match_all('/\bfunction\s+(test[A-Za-z0-9_]*)\s*\(/', $contents, $matches)) {
            foreach ($matches[1] as $name) {
                $scenarios[] = ['kind' => 'phpunit', 'name' => $name];
            }
        }

        if (preg_match_all("/\b(?:test|it)\s*\(\s*['\"]([^'\"]+)['\"]/", $contents, $pestMatches)) {
            foreach ($pestMatches[1] as $title) {
                $scenarios[] = ['kind' => 'pest', 'name' => $title];
            }
        }

        if ($scenarios === []) {
            $scenarios[] = ['kind' => 'unknown', 'name' => basename($path, '.php')];
        }

        foreach ($scenarios as $scenario) {
            $seq++;
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($scenario['name'])) ?? (string) $seq;
            $slug = trim($slug, '-') ?: (string) $seq;
            $catalog['scenarios'][] = [
                'id' => sprintf('LEG-%s-%05d', strtoupper(substr($label, 0, 3)), $seq),
                'application' => $label,
                'legacy_file' => "legacy/{$dir}/tests/{$relative}",
                'legacy_identifier' => $scenario['name'],
                'legacy_kind' => $scenario['kind'],
                'stage' => classifyStage($label, $relative),
                'execution_status' => 'UNMAPPED',
            ];
        }
    }
}

$catalog['totals'] = [
    'scenarios' => count($catalog['scenarios']),
];

echo json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

function classifyStage(string $app, string $relative): string
{
    if ($app === 'Admin') {
        return '1-admin-owner';
    }

    if (preg_match('#^(Unit/)?Support/|Subscription|Company/|Auth/|Users/#', $relative)) {
        return str_contains($relative, 'Subscription') || str_contains($relative, 'Company') ? '1-admin-owner' : '2-masters';
    }

    if (preg_match('#Master|Consignee|Transport|Drivers|Truck#i', $relative)) {
        return '2-masters';
    }

    if ($app === 'Broker') {
        return '3-broker';
    }

    if ($app === 'OwnFleet') {
        return '4-ownfleet';
    }

    if (preg_match('#Accounting#', $relative)) {
        return '6-accounting';
    }

    return '5-partload';
}
