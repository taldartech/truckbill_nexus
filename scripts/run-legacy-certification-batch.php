<?php

declare(strict_types=1);

/**
 * Run Nexus tests for a certification stage and record results.
 *
 * Usage: DB_CONNECTION=mysql DB_DATABASE=nexus_test php scripts/run-legacy-certification-batch.php 3-broker
 */
$stage = $argv[1] ?? 'all';
$mapPath = dirname(__DIR__).'/docs/engineering/LEGACY_NEXUS_SCENARIO_MAP.json';
$outPath = dirname(__DIR__).'/docs/engineering/LEGACY_CERTIFICATION_EXECUTION.json';

if (! is_file($mapPath)) {
    fwrite(STDERR, "Missing {$mapPath}\n");
    exit(1);
}

/** @var array<string, mixed> $map */
$map = json_decode((string) file_get_contents($mapPath), true, 512, JSON_THROW_ON_ERROR);
$batches = $map['module_nexus_batches'] ?? [];

$targets = [];
if ($stage === 'all') {
    foreach ($batches as $paths) {
        $targets = array_merge($targets, $paths);
    }
} elseif (! isset($batches[$stage])) {
    fwrite(STDERR, "Unknown stage: {$stage}\n");
    exit(1);
} else {
    $targets = $batches[$stage];
}

$expanded = [];
foreach ($targets as $path) {
    $absolute = dirname(__DIR__).'/'.ltrim($path, '/');
    if (is_dir($absolute)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $expanded[] = str_replace(dirname(__DIR__).'/', '', $file->getPathname());
            }
        }
    } elseif (is_file($absolute)) {
        $expanded[] = ltrim($path, '/');
    }
}

$expanded = array_values(array_unique($expanded));
sort($expanded);

$args = array_merge(['php', 'artisan', 'test', '--compact'], $expanded);
$cmd = implode(' ', array_map('escapeshellarg', $args));
$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$start = microtime(true);
$process = proc_open($cmd, $descriptorSpec, $pipes, dirname(__DIR__));
if (! is_resource($process)) {
    fwrite(STDERR, "Failed to start pest\n");
    exit(1);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exit = proc_close($process);
$durationMs = (int) round((microtime(true) - $start) * 1000);

$summary = json_decode($stdout, true);
if (! is_array($summary) && preg_match('/"tool":"pest".*"result":"(\w+)".*"tests":(\d+).*"passed":(\d+)/', $stdout, $m)) {
    $summary = [
        'result' => $m[1],
        'tests' => (int) $m[2],
        'passed' => (int) $m[3],
    ];
}

$record = is_file($outPath)
    ? json_decode((string) file_get_contents($outPath), true, 512, JSON_THROW_ON_ERROR)
    : ['runs' => []];

$record['updated_at'] = (new DateTimeImmutable)->format(DateTimeInterface::ATOM);
$record['nexus_head'] = trim((string) shell_exec('git rev-parse HEAD 2>/dev/null'));
$record['runs'][] = [
    'stage' => $stage,
    'exit_code' => $exit,
    'duration_ms' => $durationMs,
    'nexus_files' => $expanded,
    'pest_summary' => $summary,
    'stderr_tail' => substr($stderr, -2000),
];

file_put_contents($outPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

echo json_encode([
    'stage' => $stage,
    'exit_code' => $exit,
    'files' => count($expanded),
    'summary' => $summary,
], JSON_PRETTY_PRINT)."\n";

exit($exit === 0 ? 0 : 1);
