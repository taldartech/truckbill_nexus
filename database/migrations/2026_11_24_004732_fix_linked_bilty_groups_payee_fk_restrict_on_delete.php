<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrective migration for environments that already applied
 * `linked_bilty_groups_company_payee_fk` with ON DELETE SET NULL
 * (MySQL errno 1830 on fresh migrate; PostgreSQL accepted SET NULL).
 *
 * Fresh installs use restrictOnDelete in the original header-columns migration.
 * This migration is idempotent: drop if present, then recreate with restrict.
 */
return new class extends Migration
{
    private const FK = 'linked_bilty_groups_company_payee_fk';

    public function up(): void
    {
        if (! Schema::hasTable('linked_bilty_groups')) {
            return;
        }

        if (! Schema::hasColumn('linked_bilty_groups', 'payee_party_id')) {
            return;
        }

        if ($this->foreignKeyExists(self::FK)) {
            Schema::table('linked_bilty_groups', function (Blueprint $table): void {
                $table->dropForeign(self::FK);
            });
        }

        Schema::table('linked_bilty_groups', function (Blueprint $table): void {
            $table->foreign(['company_id', 'payee_party_id'], self::FK)
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Do not restore SET NULL — it is invalid on MySQL for this composite FK shape.
    }

    private function foreignKeyExists(string $name): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'select 1 as present from pg_constraint where conname = ? limit 1',
                [$name],
            );

            return $row !== null;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $database = Schema::getConnection()->getDatabaseName();
            $row = DB::selectOne(
                'select 1 as present from information_schema.table_constraints
                 where constraint_schema = ? and table_name = ? and constraint_name = ? and constraint_type = ?
                 limit 1',
                [$database, 'linked_bilty_groups', $name, 'FOREIGN KEY'],
            );

            return $row !== null;
        }

        return true;
    }
};
