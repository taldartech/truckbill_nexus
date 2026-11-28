<?php

declare(strict_types=1);

/**
 * Read-only inventory of legacy automated test cases (does not modify legacy apps).
 *
 * Usage: php scripts/inventory-legacy-tests.php > docs/engineering/LEGACY_TEST_INVENTORY.json
 */

$legacyRoot = dirname(__DIR__).'/legacy';
$apps = [
    'truckbill_broker' => 'Broker',
    'truckbill_ownfleet' => 'OwnFleet',
    'truckbill_partload' => 'Partload',
    'truckbill_admin' => 'Admin',
];

$inventory = [
    'generated_at' => (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
    'nexus_head' => trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')),
    'applications' => [],
    'totals' => [
        'files' => 0,
        'estimated_cases' => 0,
    ],
];

foreach ($apps as $dir => $label) {
    $testsDir = $legacyRoot.'/'.$dir.'/tests';
    if (! is_dir($testsDir)) {
        continue;
    }

    $files = [];
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
        $cases = 0;
        if (preg_match_all('/\bfunction\s+test[A-Za-z0-9_]*\s*\(/', $contents, $m)) {
            $cases += count($m[0]);
        }
        if (preg_match_all('/\b(?:test|it)\s*\(\s*[\'"]/', $contents, $m2)) {
            $cases += count($m2[0]);
        }

        $module = 'General';
        if (preg_match('#^(Feature|Unit)(?:/([^/]+))?#', $relative, $mod)) {
            $module = $mod[2] ?? $mod[1];
        }

        $files[] = [
            'relative_path' => $relative,
            'module' => $module,
            'estimated_cases' => $cases,
        ];
        $inventory['totals']['files']++;
        $inventory['totals']['estimated_cases'] += $cases;
    }

    usort($files, static fn (array $a, array $b): int => $a['relative_path'] <=> $b['relative_path']);

    $inventory['applications'][$label] = [
        'legacy_directory' => "legacy/{$dir}",
        'test_files' => $files,
        'file_count' => count($files),
        'estimated_cases' => array_sum(array_column($files, 'estimated_cases')),
        'manual_qa' => array_values(array_filter([
            is_file($legacyRoot.'/'.$dir.'/docs/Truckbill (Broker) - Calculations.md') ? 'docs/Truckbill (Broker) - Calculations.md' : null,
            is_file($legacyRoot.'/'.$dir.'/MODEL_DOCUMENTATION.md') ? 'MODEL_DOCUMENTATION.md' : null,
        ])),
    ];
}

echo json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";