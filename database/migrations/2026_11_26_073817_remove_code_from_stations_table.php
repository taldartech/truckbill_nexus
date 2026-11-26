<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stations are identified by stable UUID; code is removed from master data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE stations DROP CONSTRAINT IF EXISTS stations_owner_user_id_code_unique');
        } else {
            Schema::table('stations', function (Blueprint $table): void {
                if (Schema::hasColumn('stations', 'code')) {
                    $table->dropUnique(['owner_user_id', 'code']);
                }
            });
        }

        Schema::table('stations', function (Blueprint $table): void {
            if (Schema::hasColumn('stations', 'code')) {
                $table->dropColumn('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            if (! Schema::hasColumn('stations', 'code')) {
                $table->string('code', 32)->nullable()->after('owner_user_id');
            }
        });

        Schema::table('stations', function (Blueprint $table): void {
            $table->unique(['owner_user_id', 'code']);
        });
    }
};
