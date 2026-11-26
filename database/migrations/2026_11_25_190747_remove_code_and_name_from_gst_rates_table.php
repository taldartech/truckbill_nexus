<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove obsolete GST Master code/name columns.
 * Version lineage groups by rate_percent (unique rate_percent + version).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE gst_rates DROP CONSTRAINT IF EXISTS gst_rates_code_version_unique');
            DB::statement('
                DELETE FROM gst_rates a
                USING gst_rates b
                WHERE a.ctid < b.ctid
                  AND a.rate_percent = b.rate_percent
                  AND a.version = b.version
                  AND a.deleted_at IS NULL
                  AND b.deleted_at IS NULL
            ');
        } elseif ($driver === 'mysql') {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexes = $sm->getIndexes('gst_rates');
            $hasCodeVersionUnique = false;
            foreach ($indexes as $index) {
                if ($index['unique'] === true
                    && $index['columns'] === ['code', 'version']) {
                    $hasCodeVersionUnique = true;
                    break;
                }
            }
            if ($hasCodeVersionUnique) {
                Schema::table('gst_rates', function (Blueprint $table): void {
                    $table->dropUnique(['code', 'version']);
                });
            }
        }

        Schema::table('gst_rates', function (Blueprint $table): void {
            if (Schema::hasColumn('gst_rates', 'code')) {
                $table->dropColumn('code');
            }
            if (Schema::hasColumn('gst_rates', 'name')) {
                $table->dropColumn('name');
            }
        });

        Schema::table('gst_rates', function (Blueprint $table): void {
            $table->unique(['rate_percent', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('gst_rates', function (Blueprint $table): void {
            $table->dropUnique(['rate_percent', 'version']);
            $table->string('code', 32)->nullable()->after('id');
            $table->string('name', 128)->nullable()->after('code');
        });
    }
};
