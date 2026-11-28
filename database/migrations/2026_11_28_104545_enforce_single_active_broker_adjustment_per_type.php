<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('broker_adjustments')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        $duplicateGroups = DB::table('broker_adjustments')
            ->select('lorry_hire_id', 'type')
            ->whereNull('deleted_at')
            ->groupBy('lorry_hire_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $rows = DB::table('broker_adjustments')
                ->whereNull('deleted_at')
                ->where('lorry_hire_id', $group->lorry_hire_id)
                ->where('type', $group->type)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(['id', 'created_at']);

            $keeperId = $rows->first()?->id;
            foreach ($rows as $row) {
                if ($row->id === $keeperId) {
                    continue;
                }

                DB::table('broker_adjustments')
                    ->where('id', $row->id)
                    ->update([
                        'deleted_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS broker_adjustments_hire_type_active_unique '
                .'ON broker_adjustments (lorry_hire_id, type) WHERE deleted_at IS NULL'
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX broker_adjustments_hire_type_active_unique '
            .'ON broker_adjustments (lorry_hire_id, type)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('broker_adjustments')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS broker_adjustments_hire_type_active_unique');
        } else {
            DB::statement('DROP INDEX broker_adjustments_hire_type_active_unique');
        }
    }
};
