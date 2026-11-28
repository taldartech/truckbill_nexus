<?php

declare(strict_types=1);

/**
 * Merge scenario-level execution outcomes into LEGACY_SCENARIO_CATALOG.json.
 *
 * Input: docs/engineering/LEGACY_SCENARIO_EXECUTION_REGISTER.json
 * Usage: php scripts/sync-legacy-scenario-execution.php
 */
$catalogPath = dirname(__DIR__).'/docs/engineering/LEGACY_SCENARIO_CATALOG.json';
$registerPath = dirname(__DIR__).'/docs/engineering/LEGACY_SCENARIO_EXECUTION_REGISTER.json';

if (! is_file($catalogPath) || ! is_file($registerPath)) {
    fwrite(STDERR, "Missing catalog or execution register.\n");
    exit(1);
}

/** @var array<string, mixed> $catalog */
$catalog = json_decode((string) file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
/** @var array<string, mixed> $register */
$register = json_decode((string) file_get_contents($registerPath), true, 512, JSON_THROW_ON_ERROR);

/** @var list<array<string, mixed>> $outcomes */
$outcomes = $register['outcomes'] ?? [];
$byId = [];
foreach ($outcomes as $outcome) {
    $id = $outcome['legacy_scenario_id'] ?? null;
    if (! is_string($id) || $id === '') {
        continue;
    }
    $byId[$id] = $outcome;
}

$updated = 0;
if (! isset($catalog['scenarios']) || ! is_array($catalog['scenarios'])) {
    fwrite(STDERR, "Catalog has no scenarios array.\n");
    exit(1);
}
foreach ($catalog['scenarios'] as &$scenario) {
    $id = $scenario['id'] ?? null;
    if (! is_string($id) || ! isset($byId[$id])) {
        continue;
    }
    $patch = $byId[$id];
    $scenario['execution_status'] = $patch['status'] ?? $scenario['execution_status'] ?? 'UNMAPPED';
    if (isset($patch['nexus_test'])) {
        $scenario['nexus_test'] = $patch['nexus_test'];
    }
    if (isset($patch['notes'])) {
        $scenario['notes'] = $patch['notes'];
    }
    if (isset($patch['deviation_id'])) {
        $scenario['deviation_id'] = $patch['deviation_id'];
    }
    $updated++;
}
unset($scenario);

$catalog['scenario_execution_synced_at'] = (new DateTimeImmutable)->format(DateTimeInterface::ATOM);
$catalog['nexus_head'] = trim((string) shell_exec('git rev-parse HEAD 2>/dev/null'));

file_put_contents(
    $catalogPath,
    json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
);

echo json_encode(['updated_scenarios' => $updated], JSON_PRETTY_PRINT)."\n";
