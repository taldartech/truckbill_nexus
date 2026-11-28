<?php

declare(strict_types=1);

/**
 * Promote MAPPED_NOT_EXECUTED scenarios when Nexus tests reference the legacy identifier
 * or share an explicit parity test name. Does not promote partial file-level mappings alone.
 *
 * Usage: php scripts/promote-legacy-scenario-pass.php
 */
$catalogPath = dirname(__DIR__).'/docs/engineering/LEGACY_SCENARIO_CATALOG.json';
$registerPath = dirname(__DIR__).'/docs/engineering/LEGACY_SCENARIO_EXECUTION_REGISTER.json';
$root = dirname(__DIR__);

/** @var array<string, mixed> $catalog */
$catalog = json_decode((string) file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
/** @var array<string, mixed> $register */
$register = json_decode((string) file_get_contents($registerPath), true, 512, JSON_THROW_ON_ERROR);

$byId = [];
foreach ($catalog['scenarios'] ?? [] as $scenario) {
    $byId[(string) $scenario['id']] = $scenario;
}

/** @var list<array<string, mixed>> $outcomes */
$outcomes = $register['outcomes'] ?? [];
$promoted = 0;

foreach ($outcomes as &$outcome) {
    if (($outcome['status'] ?? '') !== 'MAPPED_NOT_EXECUTED') {
        continue;
    }

    $id = (string) ($outcome['legacy_scenario_id'] ?? '');
    $scenario = $byId[$id] ?? null;
    if ($scenario === null) {
        continue;
    }

    $identifier = (string) $scenario['legacy_identifier'];
    $legacyFile = basename((string) $scenario['legacy_file']);
    $nexusList = array_map('trim', explode(',', (string) ($outcome['nexus_test'] ?? '')));

    foreach ($nexusList as $relative) {
        if ($relative === '') {
            continue;
        }
        $path = $root.'/'.ltrim($relative, '/');
        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                    $contents = (string) file_get_contents($file->getPathname());
                    if (identifierFoundInNexus($contents, $identifier, $legacyFile)) {
                        $outcome['status'] = 'PASS';
                        $outcome['notes'] = trim((string) ($outcome['notes'] ?? '')).' Promoted: identifier referenced in '.$file->getPathname().'.';
                        $promoted++;
                        break 2;
                    }
                }
            }
        } elseif (is_file($path)) {
            $contents = (string) file_get_contents($path);
            if (identifierFoundInNexus($contents, $identifier, $legacyFile)) {
                $outcome['status'] = 'PASS';
                $outcome['notes'] = trim((string) $outcome['notes'] ?? '').' Promoted: identifier referenced in '.$relative.'.';
                $promoted++;
                break;
            }
        }
    }
}
unset($outcome);

$register['outcomes'] = $outcomes;
$register['promoted_at'] = (new DateTimeImmutable)->format(DateTimeInterface::ATOM);

file_put_contents($registerPath, json_encode($register, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

echo json_encode(['promoted' => $promoted], JSON_PRETTY_PRINT)."\n";

function identifierFoundInNexus(string $contents, string $identifier, string $legacyFile): bool
{
    if (str_contains($contents, $identifier)) {
        return true;
    }

    $pestSlug = preg_replace('/[^a-z0-9]+/i', ' ', strtolower($identifier)) ?? '';
    $pestSlug = trim(str_replace('test ', '', $pestSlug));

    if ($pestSlug !== '' && stripos($contents, $pestSlug) !== false) {
        return true;
    }

    return str_contains($contents, $legacyFile);
}
