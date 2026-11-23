<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_adjustments', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable()->after('company_id');
            $table->decimal('previous_weight', 18, 3)->nullable()->after('weight');
            $table->decimal('new_weight', 18, 3)->nullable()->after('previous_weight');
            $table->decimal('quantity', 18, 3)->nullable()->after('new_weight');
            $table->decimal('rate', 18, 4)->nullable()->after('quantity');
            $table->timestampTz('occurred_at')->nullable()->after('reason');
        });

        DB::table('broker_adjustments')
            ->whereNull('branch_id')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $row): void {
                $branchId = DB::table('lorry_hires')
                    ->where('company_id', $row->company_id)
                    ->where('id', $row->lorry_hire_id)
                    ->value('branch_id');

                if ($branchId !== null) {
                    DB::table('broker_adjustments')
                        ->where('id', $row->id)
                        ->update(['branch_id' => $branchId]);
                }
            });

        Schema::table('broker_adjustments', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable(false)->change();
            $table->foreign(['company_id', 'branch_id'], 'broker_adjustments_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
        });

        Schema::table('broker_deliveries', function (Blueprint $table) {
            $table->index(['company_id', 'memo_number'], 'broker_deliveries_company_memo_idx');
            $table->index(['company_id', 'status'], 'broker_deliveries_company_status_idx');
            $table->index(['company_id', 'lorry_receipt_id'], 'broker_deliveries_company_lr_idx');
        });
    }

    public function down(): void
    {
        Schema::table('broker_deliveries', function (Blueprint $table) {
            $table->dropIndex('broker_deliveries_company_memo_idx');
            $table->dropIndex('broker_deliveries_company_status_idx');
            $table->dropIndex('broker_deliveries_company_lr_idx');
        });

        Schema::table('broker_adjustments', function (Blueprint $table) {
            $table->dropForeign('broker_adjustments_company_branch_fk');
            $table->dropColumn([
                'branch_id',
                'previous_weight',
                'new_weight',
                'quantity',
                'rate',
                'occurred_at',
            ]);
        });
    }
};
