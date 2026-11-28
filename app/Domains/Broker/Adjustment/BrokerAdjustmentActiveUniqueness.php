<?php

namespace App\Domains\Broker\Adjustment;

use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Models\LorryHire;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One active fixed adjustment per hire + type; historical soft-deleted rows preserved.
 */
final class BrokerAdjustmentActiveUniqueness
{
    public const string LEGACY_MYSQL_INDEX = 'broker_adjustments_hire_type_active_unique';

    public const string POSTGRES_INDEX = 'broker_adjustments_hire_fixed_active_uniq';

    public const string MYSQL_GENERATED_COLUMN = 'active_fixed_type_uniq';

    public const string MYSQL_INDEX = 'broker_adjustments_hire_active_fixed_type_uniq';

    /**
     * @return list<string>
     */
    public static function fixedTypeValues(): array
    {
        return array_map(
            static fn (BrokerAdjustmentType $type): string => $type->value,
            BrokerAdjustmentType::cases(),
        );
    }

    /**
     * @return list<array{ lorry_hire_id: string, type: string, kept_id: string, removed_ids: list<string> }>
     */
    public static function reconcileActiveDuplicates(?Connection $connection = null): array
    {
        if (! Schema::hasTable('broker_adjustments')) {
            return [];
        }

        $connection ??= DB::connection();
        $fixedTypes = self::fixedTypeValues();
        $diagnostics = [];

        $duplicateGroups = $connection->table('broker_adjustments')
            ->select('lorry_hire_id', 'type')
            ->whereNull('deleted_at')
            ->whereIn('type', $fixedTypes)
            ->groupBy('lorry_hire_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $affectedHireIds = [];

        foreach ($duplicateGroups as $group) {
            /** @var Collection<int, object{id: string, created_at: ?string, updated_at: ?string}> $rows */
            $rows = $connection->table('broker_adjustments')
                ->whereNull('deleted_at')
                ->where('lorry_hire_id', $group->lorry_hire_id)
                ->where('type', $group->type)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(['id', 'created_at', 'updated_at']);

            if ($rows->count() < 2) {
                continue;
            }

            $keeper = $rows->first();
            $removedIds = [];
            foreach ($rows as $row) {
                if ($row->id === $keeper->id) {
                    continue;
                }

                $connection->table('broker_adjustments')
                    ->where('id', $row->id)
                    ->update([
                        'deleted_at' => now(),
                        'updated_at' => now(),
                    ]);
                $removedIds[] = $row->id;
            }

            $affectedHireIds[] = (string) $group->lorry_hire_id;
            $diagnostics[] = [
                'lorry_hire_id' => (string) $group->lorry_hire_id,
                'type' => (string) $group->type,
                'kept_id' => (string) $keeper->id,
                'removed_ids' => $removedIds,
            ];
        }

        self::refreshHireFinancialsForHires(array_values(array_unique($affectedHireIds)));

        return $diagnostics;
    }

    /**
     * @param  list<string>  $hireIds
     */
    public static function refreshHireFinancialsForHires(array $hireIds): void
    {
        if ($hireIds === []) {
            return;
        }

        $calculationService = app(LorryHireCalculationService::class);

        LorryHire::query()
            ->whereIn('id', $hireIds)
            ->with(['adjustments', 'vouchers', 'bookBalanceEntries', 'customAmounts'])
            ->lazyById()
            ->each(function (LorryHire $hire) use ($calculationService): void {
                $summary = $calculationService->summarize($hire);
                $hire->forceFill([
                    'outstanding_amount' => $summary['outstanding_amount'],
                ])->save();
            });
    }

    public static function install(?Connection $connection = null): void
    {
        if (! Schema::hasTable('broker_adjustments')) {
            return;
        }

        $connection ??= DB::connection();
        self::dropLegacyIndexes($connection);

        if ($connection->getDriverName() === 'pgsql') {
            self::installPostgres($connection);

            return;
        }

        self::installMysql($connection);
    }

    public static function uninstall(?Connection $connection = null): void
    {
        if (! Schema::hasTable('broker_adjustments')) {
            return;
        }

        $connection ??= DB::connection();
        self::dropLegacyIndexes($connection);

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('DROP INDEX IF EXISTS '.self::POSTGRES_INDEX);

            return;
        }

        if (Schema::hasColumn('broker_adjustments', self::MYSQL_GENERATED_COLUMN)) {
            $connection->statement(
                'ALTER TABLE broker_adjustments DROP COLUMN '.self::MYSQL_GENERATED_COLUMN
            );
        }
    }

    private static function dropLegacyIndexes(Connection $connection): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('DROP INDEX IF EXISTS '.self::LEGACY_MYSQL_INDEX);
            $connection->statement('DROP INDEX IF EXISTS '.self::POSTGRES_INDEX);

            return;
        }

        foreach ([self::LEGACY_MYSQL_INDEX, self::MYSQL_INDEX] as $index) {
            try {
                $connection->statement('DROP INDEX '.$index.' ON broker_adjustments');
            } catch (QueryException) {
                // Index may not exist after a failed partial migration.
            }
        }
    }

    private static function installPostgres(Connection $connection): void
    {
        $types = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            self::fixedTypeValues(),
        ));

        $connection->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::POSTGRES_INDEX.' '
            .'ON broker_adjustments (lorry_hire_id, type) '
            .'WHERE deleted_at IS NULL AND type IN ('.$types.')'
        );
    }

    private static function installMysql(Connection $connection): void
    {
        if (! Schema::hasColumn('broker_adjustments', self::MYSQL_GENERATED_COLUMN)) {
            $connection->statement(
                'ALTER TABLE broker_adjustments '
                .'ADD COLUMN '.self::MYSQL_GENERATED_COLUMN.' VARCHAR(32) '
                .'AS (IF(deleted_at IS NULL, `type`, NULL)) STORED'
            );
        }

        $connection->statement(
            'CREATE UNIQUE INDEX '.self::MYSQL_INDEX.' '
            .'ON broker_adjustments (lorry_hire_id, '.self::MYSQL_GENERATED_COLUMN.')'
        );
    }

    /**
     * Detect whether the failed legacy MySQL unique index is still present.
     */
    public static function hasLegacyMysqlUnconditionalIndex(?Connection $connection = null): bool
    {
        $connection ??= DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        $row = $connection->selectOne(
            'SELECT 1 AS present FROM information_schema.statistics '
            .'WHERE table_schema = DATABASE() '
            .'AND table_name = ? '
            .'AND index_name = ? '
            .'LIMIT 1',
            ['broker_adjustments', self::LEGACY_MYSQL_INDEX],
        );

        return $row !== null;
    }
}
