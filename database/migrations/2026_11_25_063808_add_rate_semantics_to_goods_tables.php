<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rate_semantics:
 * - weight_kg: historical Nexus formulas (per_kg / per_actual_weight / per_charged_weight use KG × rate)
 * - weight_mt: PO Nov 2025 — Per Actual/Charged Weight mean MT × rate (same basis as Per MT)
 *
 * Existing rows are stamped weight_kg so stored freight_amount is never reinterpreted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lr_goods_rows', function (Blueprint $table): void {
            $table->string('rate_semantics', 16)->nullable()->after('freight_rate_type');
        });

        Schema::table('lorry_hire_goods_lines', function (Blueprint $table): void {
            $table->string('rate_semantics', 16)->nullable()->after('rate_type');
        });

        if (Schema::hasTable('lr_goods_rows')) {
            DB::table('lr_goods_rows')->whereNull('rate_semantics')->update([
                'rate_semantics' => 'weight_kg',
            ]);
        }

        if (Schema::hasTable('lorry_hire_goods_lines')) {
            DB::table('lorry_hire_goods_lines')->whereNull('rate_semantics')->update([
                'rate_semantics' => 'weight_kg',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('lr_goods_rows', function (Blueprint $table): void {
            $table->dropColumn('rate_semantics');
        });

        Schema::table('lorry_hire_goods_lines', function (Blueprint $table): void {
            $table->dropColumn('rate_semantics');
        });
    }
};
