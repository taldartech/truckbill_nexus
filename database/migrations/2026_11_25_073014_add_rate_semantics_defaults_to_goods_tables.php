<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * New goods rows default to weight_mt (PO-LGC-018). Existing rows stay weight_kg.
 * Does not rewrite freight_amount — only schema default for omitted inserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lr_goods_rows') && Schema::hasColumn('lr_goods_rows', 'rate_semantics')) {
            DB::table('lr_goods_rows')->whereNull('rate_semantics')->update([
                'rate_semantics' => 'weight_kg',
            ]);

            Schema::table('lr_goods_rows', function (Blueprint $table): void {
                $table->string('rate_semantics', 16)->default('weight_mt')->nullable(false)->change();
            });
        }

        if (Schema::hasTable('lorry_hire_goods_lines') && Schema::hasColumn('lorry_hire_goods_lines', 'rate_semantics')) {
            DB::table('lorry_hire_goods_lines')->whereNull('rate_semantics')->update([
                'rate_semantics' => 'weight_kg',
            ]);

            Schema::table('lorry_hire_goods_lines', function (Blueprint $table): void {
                $table->string('rate_semantics', 16)->default('weight_mt')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lr_goods_rows') && Schema::hasColumn('lr_goods_rows', 'rate_semantics')) {
            Schema::table('lr_goods_rows', function (Blueprint $table): void {
                $table->string('rate_semantics', 16)->nullable()->default(null)->change();
            });
        }

        if (Schema::hasTable('lorry_hire_goods_lines') && Schema::hasColumn('lorry_hire_goods_lines', 'rate_semantics')) {
            Schema::table('lorry_hire_goods_lines', function (Blueprint $table): void {
                $table->string('rate_semantics', 16)->nullable()->default(null)->change();
            });
        }
    }
};
