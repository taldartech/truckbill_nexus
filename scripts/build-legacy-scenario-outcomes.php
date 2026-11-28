<?php

declare(strict_types=1);

/**
 * Classify every legacy catalog scenario: deferrals, superseded, Nexus mapping, or N/A.
 * Output: docs/engineering/LEGACY_SCENARIO_EXECUTION_REGISTER.json (full outcomes).
 *
 * Usage: php scripts/build-legacy-scenario-outcomes.php
 */
$catalogPath = dirname(__DIR__).'/docs/engineering/LEGACY_SCENARIO_CATALOG.json';
$mapPath = dirname(__DIR__).'/docs/engineering/LEGACY_NEXUS_SCENARIO_MAP.json';
$registerPath = dirname(__DIR__).'/docs/engineering/LEGACY_SCENARIO_EXECUTION_REGISTER.json';

/** @var array<string, mixed> $catalog */
$catalog = json_decode((string) file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
/** @var array<string, mixed> $map */
$map = json_decode((string) file_get_contents($mapPath), true, 512, JSON_THROW_ON_ERROR);

/** Preserve manually verified scenario outcomes (explicit PASS / deviations). */
$preserved = [];
if (is_file($registerPath)) {
    /** @var array<string, mixed> $existing */
    $existing = json_decode((string) file_get_contents($registerPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($existing['outcomes'] ?? [] as $row) {
        $id = $row['legacy_scenario_id'] ?? null;
        if (! is_string($id)) {
            continue;
        }
        $status = (string) ($row['status'] ?? '');
        if (in_array($status, ['PASS', 'FIXED_AND_PASSED', 'NOT_APPLICABLE', 'SUPERSEDED_APPROVED', 'FAIL'], true)) {
            $preserved[$id] = $row;
        }
    }
}

/** @var list<array<string, mixed>> $mappings */
$mappings = $map['mappings'] ?? [];

/** @var array<string, list<string>> $stageBatches */
$stageBatches = $map['module_nexus_batches'] ?? [];

function classifyDeferral(string $legacyFile, string $identifier): ?array
{
    $p10 = [
        'TyreManagementTest.php',
        '/TyreReportServiceTest.php',
        '/Battery',
        '/SparePart',
        '/Workshop',
        '/JobCard',
        '/Inventory/',
        '/StoreManagement',
        '/PartsIssue',
    ];
    foreach ($p10 as $needle) {
        if (str_contains($legacyFile, $needle)) {
            return [
                'status' => 'DEFERRED_PHASE_10',
                'notes' => 'OwnFleet asset lifecycle / store — Phase 10 scope (not Tyre Layout Master).',
            ];
        }
    }

    $p9 = [
        '/Notification/',
        '/Sms',
        '/WhatsApp',
        '/EmailCampaign',
        '/NotificationTemplate',
    ];
    foreach ($p9 as $needle) {
        if (stripos($legacyFile, $needle) !== false && ! str_contains($identifier, 'Otp')) {
            return [
                'status' => 'DEFERRED_PHASE_9',
                'notes' => 'Notifications and communications — Phase 9.',
            ];
        }
    }

    $p11 = [
        '/DataMigration',
        '/ProductionMigration',
        '/MigrationReconcil',
        '/LegacyImport',
    ];
    foreach ($p11 as $needle) {
        if (stripos($legacyFile, $needle) !== false) {
            return [
                'status' => 'DEFERRED_PHASE_11',
                'notes' => 'Production migration / reconciliation — Phase 11.',
            ];
        }
    }

    return null;
}

function classifyNotApplicable(string $legacyFile, string $identifier): ?array
{
    if (preg_match('/Tally|FreightBookingAmountVerifier.*console|LegacyCorrectionCommand/i', $legacyFile.' '.$identifier)) {
        return [
            'status' => 'NOT_APPLICABLE',
            'notes' => 'Legacy-only integration or console guard without Nexus equivalent.',
        ];
    }

    return null;
}

function classifySuperseded(string $legacyFile, string $identifier): ?array
{
    if (str_contains($legacyFile, 'ConsigneeDisplayNameTest') || str_contains($legacyFile, 'ConsignorDisplayNameTest')) {
        return [
            'status' => 'SUPERSEDED_APPROVED',
            'deviation_id' => 'NX-01',
            'notes' => 'Unified Party Master presentation.',
        ];
    }

    if (str_contains($legacyFile, 'CompanyEnterExitTest') && str_contains($identifier, 'without_confirm')) {
        return [
            'status' => 'SUPERSEDED_APPROVED',
            'deviation_id' => 'NX-03',
            'notes' => 'Branch entry requires confirmation in Nexus.',
        ];
    }

    return null;
}

function findMapping(array $mappings, array $scenario): ?array
{
    $id = (string) $scenario['id'];
    $legacyFile = (string) $scenario['legacy_file'];

    foreach ($mappings as $mapping) {
        $prefix = (string) ($mapping['legacy_ids_prefix'] ?? '');
        if ($prefix !== '' && ! str_starts_with($id, $prefix)) {
            continue;
        }

        if (isset($mapping['legacy_file']) && $mapping['legacy_file'] === $legacyFile) {
            return $mapping;
        }

        $contains = (string) ($mapping['legacy_file_contains'] ?? '');
        if ($contains !== '' && str_contains($legacyFile, $contains)) {
            return $mapping;
        }
    }

    return null;
}

$outcomes = [];
$counts = [];

/** @var list<array<string, mixed>> $scenarios */
$scenarios = $catalog['scenarios'] ?? [];

foreach ($scenarios as $scenario) {
    $id = (string) $scenario['id'];
    $legacyFile = (string) $scenario['legacy_file'];
    $identifier = (string) $scenario['legacy_identifier'];
    $stage = (string) ($scenario['stage'] ?? '');

    if (isset($preserved[$id])) {
        $outcomes[] = $preserved[$id];
        $counts[(string) $preserved[$id]['status']] = ($counts[(string) $preserved[$id]['status']] ?? 0) + 1;

        continue;
    }

    $row = classifyDeferral($legacyFile, $identifier)
        ?? classifyNotApplicable($legacyFile, $identifier)
        ?? classifySuperseded($legacyFile, $identifier);

    if ($row === null) {
        $mapping = findMapping($mappings, $scenario);
        if ($mapping !== null) {
            $tests = $mapping['nexus_tests'] ?? [];
            $coverage = (string) ($mapping['coverage'] ?? 'partial');
            $row = [
                'status' => 'MAPPED_NOT_EXECUTED',
                'nexus_test' => implode(', ', $tests),
                'notes' => trim(
                    ($mapping['notes'] ?? '').' Coverage: '.$coverage.'; adapted Nexus tests — execute batch then promote via promote-legacy-scenario-pass.php.',
                ),
            ];
        } elseif (isset($stageBatches[$stage])) {
            $row = [
                'status' => 'MAPPED_NOT_EXECUTED',
                'nexus_test' => implode(', ', $stageBatches[$stage]),
                'notes' => "Stage default batch ({$stage}); map refined when Nexus twin identified.",
            ];
        } else {
            $row = [
                'status' => 'BLOCKED',
                'notes' => 'No stage batch configured — add LEGACY_NEXUS_SCENARIO_MAP entry.',
            ];
        }
    }

    $outcomes[] = array_merge(['legacy_scenario_id' => $id], $row);
    $status = (string) $row['status'];
    $counts[$status] = ($counts[$status] ?? 0) + 1;
}

$register = [
    'version' => 2,
    'description' => 'Full legacy scenario classification. Rebuild via php scripts/build-legacy-scenario-outcomes.php; preserve explicit PASS rows.',
    'generated_at' => (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
    'nexus_head' => trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')),
    'totals' => array_merge(['scenarios' => count($outcomes)], $counts),
    'outcomes' => $outcomes,
];

file_put_contents(
    $registerPath,
    json_encode($register, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
);

echo json_encode($register['totals'], JSON_PRETTY_PRINT)."\n";
