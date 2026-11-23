<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Driver-aware unique indexes that preserve soft-delete (and related) semantics.
 *
 * PostgreSQL: partial UNIQUE INDEX ... WHERE deleted_at IS NULL (and optional extras).
 * MySQL 8: VIRTUAL generated columns that are NULL when the row is inactive, plus a
 * UNIQUE INDEX on those keys (NULL keys do not conflict — multiple soft-deleted rows OK).
 *
 * Numbering scope (nullable branch_id):
 * PostgreSQL: expression unique on COALESCE(branch_id::text, '').
 * MySQL: generated scope_key via CONCAT + IFNULL(branch_id, '').
 */
final class SoftDeleteUniqueIndex
{
    /**
     * Soft-delete-aware unique index (active rows only).
     *
     * @param  list<string>  $columns
     * @param  string  $activePredicate  Default soft-delete gate; override when
     *                                   deactivated_at must also be null, etc.
     */
    public static function createActiveUniqueIndex(
        string $table,
        string $indexName,
        array $columns,
        ?string $extraWhere = null,
        string $activePredicate = 'deleted_at IS NULL',
    ): void {
        $where = $activePredicate;
        if ($extraWhere !== null && $extraWhere !== '') {
            $where .= ' AND '.$extraWhere;
        }

        self::createPartialUniqueIndex($table, $indexName, $columns, $where);
    }

    /**
     * Partial unique index with an arbitrary WHERE predicate (pgsql) or generated-key equivalent (mysql).
     *
     * @param  list<string>  $columns
     */
    public static function createPartialUniqueIndex(
        string $table,
        string $indexName,
        array $columns,
        string $where,
    ): void {
        self::assertColumns($columns);

        if (self::isPgsql()) {
            $cols = implode(', ', $columns);
            DB::statement("CREATE UNIQUE INDEX {$indexName} ON {$table} ({$cols}) WHERE {$where}");

            return;
        }

        if (self::isMysqlFamily()) {
            self::createMysqlGeneratedUniqueIndex($table, $indexName, $columns, $where);

            return;
        }

        throw self::unsupportedDriver();
    }

    /**
     * Non-unique partial index (pgsql WHERE) or full composite index (mysql — no partial indexes).
     *
     * @param  list<string>  $columns
     */
    public static function createPartialIndex(
        string $table,
        string $indexName,
        array $columns,
        string $where,
    ): void {
        self::assertColumns($columns);

        if (self::isPgsql()) {
            $cols = implode(', ', $columns);
            DB::statement("CREATE INDEX {$indexName} ON {$table} ({$cols}) WHERE {$where}");

            return;
        }

        if (self::isMysqlFamily()) {
            // MySQL has no partial indexes; approximate with a full composite index.
            $cols = implode(', ', array_map(
                static fn (string $column): string => self::quoteMysql($column),
                $columns,
            ));
            DB::statement('CREATE INDEX '.self::quoteMysql($indexName).' ON '.self::quoteMysql($table)." ({$cols})");

            return;
        }

        throw self::unsupportedDriver();
    }

    /**
     * Plain unique index (no soft-delete / partial predicate).
     *
     * @param  list<string>  $columns
     */
    public static function createUniqueIndex(string $table, string $indexName, array $columns): void
    {
        self::assertColumns($columns);

        if (self::isPgsql()) {
            $cols = implode(', ', $columns);
            DB::statement("CREATE UNIQUE INDEX {$indexName} ON {$table} ({$cols})");

            return;
        }

        if (self::isMysqlFamily()) {
            $cols = implode(', ', array_map(
                static fn (string $column): string => self::quoteMysql($column),
                $columns,
            ));
            DB::statement('CREATE UNIQUE INDEX '.self::quoteMysql($indexName).' ON '.self::quoteMysql($table)." ({$cols})");

            return;
        }

        throw self::unsupportedDriver();
    }

    /**
     * Unique scope for document_number_sequences where branch_id may be NULL.
     *
     * @param  list<string>  $fixedColumns  Columns that are always present (e.g. company_id, document_type, scope_type)
     */
    public static function createNullableScopeUniqueIndex(
        string $table,
        string $indexName,
        array $fixedColumns,
        string $nullableColumn,
        string $generatedColumnName = 'scope_key',
    ): void {
        self::assertColumns($fixedColumns);

        if (self::isPgsql()) {
            $fixed = implode(', ', $fixedColumns);
            DB::statement("
                CREATE UNIQUE INDEX {$indexName}
                ON {$table} (
                    {$fixed},
                    (COALESCE({$nullableColumn}::text, ''))
                )
            ");

            return;
        }

        if (self::isMysqlFamily()) {
            $parts = array_map(
                static fn (string $column): string => 'IFNULL(CAST('.self::quoteMysql($column).' AS CHAR), \'\')',
                [...$fixedColumns, $nullableColumn],
            );
            $expression = 'CONCAT_WS(0x1F, '.implode(', ', $parts).')';

            if (! Schema::hasColumn($table, $generatedColumnName)) {
                DB::statement(
                    'ALTER TABLE '.self::quoteMysql($table)
                    .' ADD COLUMN '.self::quoteMysql($generatedColumnName)
                    ." VARCHAR(512) GENERATED ALWAYS AS ({$expression}) VIRTUAL NULL"
                );
            }

            DB::statement(
                'CREATE UNIQUE INDEX '.self::quoteMysql($indexName)
                .' ON '.self::quoteMysql($table)
                .' ('.self::quoteMysql($generatedColumnName).')'
            );

            return;
        }

        throw self::unsupportedDriver();
    }

    public static function dropIndexIfExists(
        string $table,
        string $indexName,
        ?string $generatedColumnName = null,
    ): void {
        if (self::isPgsql()) {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");

            return;
        }

        if (self::isMysqlFamily()) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $indexName)) {
                DB::statement('DROP INDEX '.self::quoteMysql($indexName).' ON '.self::quoteMysql($table));
            }

            $candidates = array_values(array_unique(array_filter([
                $generatedColumnName,
                self::generatedColumnName($indexName),
                $indexName === 'document_number_sequences_scope_unique' ? 'scope_key' : null,
            ])));

            foreach ($candidates as $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::statement(
                        'ALTER TABLE '.self::quoteMysql($table)
                        .' DROP COLUMN '.self::quoteMysql($column)
                    );
                }
            }

            return;
        }

        throw self::unsupportedDriver();
    }

    /**
     * Drop a UNIQUE constraint (pgsql) or equivalently-named unique index (mysql).
     * Used when Laravel Schema::unique() created the name on fresh install.
     */
    public static function dropUniqueConstraintOrIndexIfExists(string $table, string $name): void
    {
        if (self::isPgsql()) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");

            return;
        }

        if (self::isMysqlFamily()) {
            self::dropIndexIfExists($table, $name);

            return;
        }

        throw self::unsupportedDriver();
    }

    public static function dropCheckConstraintIfExists(string $table, string $constraintName): void
    {
        if (self::isPgsql()) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraintName}");

            return;
        }

        if (self::isMysqlFamily()) {
            $exists = DB::table('information_schema.table_constraints')
                ->whereRaw('table_schema = DATABASE()')
                ->where('table_name', $table)
                ->where('constraint_name', $constraintName)
                ->where('constraint_type', 'CHECK')
                ->exists();

            if ($exists) {
                DB::statement('ALTER TABLE '.self::quoteMysql($table).' DROP CHECK '.self::quoteMysql($constraintName));
            }

            return;
        }

        throw self::unsupportedDriver();
    }

    public static function addCheckConstraint(string $table, string $constraintName, string $expression): void
    {
        if (self::isPgsql()) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} CHECK ({$expression})");

            return;
        }

        if (self::isMysqlFamily()) {
            DB::statement(
                'ALTER TABLE '.self::quoteMysql($table)
                .' ADD CONSTRAINT '.self::quoteMysql($constraintName)
                ." CHECK ({$expression})"
            );

            return;
        }

        throw self::unsupportedDriver();
    }

    /**
     * @param  string  $mysqlColumnDefinition  Full MySQL column type, e.g. "VARCHAR(32)"
     */
    public static function setColumnNotNull(string $table, string $column, string $mysqlColumnDefinition): void
    {
        if (self::isPgsql()) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");

            return;
        }

        if (self::isMysqlFamily()) {
            DB::statement(
                'ALTER TABLE '.self::quoteMysql($table)
                .' MODIFY '.self::quoteMysql($column)
                ." {$mysqlColumnDefinition} NOT NULL"
            );

            return;
        }

        throw self::unsupportedDriver();
    }

    public static function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }

    public static function isPgsql(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function isMysqlFamily(): bool
    {
        return in_array(self::driver(), ['mysql', 'mariadb'], true);
    }

    /**
     * @param  list<string>  $columns
     */
    private static function createMysqlGeneratedUniqueIndex(
        string $table,
        string $indexName,
        array $columns,
        string $where,
    ): void {
        $generatedColumn = self::generatedColumnName($indexName);

        if (count($columns) === 1) {
            // Prefer VARCHAR so UUID/string and integer keys share one MySQL path.
            $expression = 'IF(('.$where.'), CAST('.self::quoteMysql($columns[0]).' AS CHAR), NULL)';
        } else {
            $parts = array_map(
                static fn (string $column): string => 'IFNULL(CAST('.self::quoteMysql($column).' AS CHAR), \'\')',
                $columns,
            );
            $expression = 'IF(('.$where.'), CONCAT_WS(0x1F, '.implode(', ', $parts).'), NULL)';
        }

        // MySQL: type AS (expr) VIRTUAL — NULL/NOT NULL must follow VIRTUAL, not precede GENERATED.
        if (! Schema::hasColumn($table, $generatedColumn)) {
            DB::statement(
                'ALTER TABLE '.self::quoteMysql($table)
                .' ADD COLUMN '.self::quoteMysql($generatedColumn)
                ." VARCHAR(512) GENERATED ALWAYS AS ({$expression}) VIRTUAL NULL"
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::quoteMysql($indexName)
            .' ON '.self::quoteMysql($table)
            .' ('.self::quoteMysql($generatedColumn).')'
        );
    }

    private static function generatedColumnName(string $indexName): string
    {
        $suffix = '_ak';
        $max = 64;
        if (strlen($indexName) + strlen($suffix) <= $max) {
            return $indexName.$suffix;
        }

        // Keep uniqueness; stay within MySQL identifier length.
        return substr($indexName, 0, $max - strlen($suffix) - 8).'_'.substr(md5($indexName), 0, 7).$suffix;
    }

    private static function quoteMysql(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * @param  list<string>  $columns
     */
    private static function assertColumns(array $columns): void
    {
        if ($columns === []) {
            throw new RuntimeException('SoftDeleteUniqueIndex requires at least one column.');
        }

        foreach ($columns as $column) {
            if ($column === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
                throw new RuntimeException("Invalid index column [{$column}].");
            }
        }
    }

    private static function unsupportedDriver(): RuntimeException
    {
        return new RuntimeException('Unsupported database driver ['.self::driver().'] for SoftDeleteUniqueIndex.');
    }
}
