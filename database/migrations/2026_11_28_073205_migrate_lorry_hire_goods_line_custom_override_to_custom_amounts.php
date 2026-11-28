<?php

use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Models\LorryHireCustomAmount;
use App\Models\LorryHireGoodsLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lorry_hire_custom_amounts')
            || ! Schema::hasColumn('lorry_hire_goods_lines', 'custom_amount')) {
            return;
        }

        $calculations = app(LorryHireCalculationService::class);

        LorryHireGoodsLine::query()
            ->whereNotNull('custom_amount')
            ->with(['lorryHire', 'lrGoodsRow'])
            ->orderBy('created_at')
            ->each(function (LorryHireGoodsLine $line) use ($calculations): void {
                $override = number_format((float) $line->custom_amount, 2, '.', '');

                $calculated = $calculations->goodsLineFreight([
                    'rate_type' => $line->rate_type,
                    'rate' => (string) $line->rate,
                    'is_fixed_amount' => $line->is_fixed_amount && $line->rate_type->value === 'fixed_amount',
                    'package_count' => $line->lrGoodsRow?->package_count,
                    'actual_weight' => $line->loading_weight,
                    'charged_weight' => $line->loading_weight,
                    'rate_semantics' => $line->rate_semantics?->value,
                ]);

                $delta = bcsub($override, $calculated, 2);

                if (bccomp($delta, '0.00', 2) !== 0) {
                    LorryHireCustomAmount::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $line->company_id,
                        'lorry_hire_id' => $line->lorry_hire_id,
                        'description' => 'Migrated from per-line freight override',
                        'amount' => $delta,
                    ]);
                }

                $line->forceFill([
                    'freight_amount' => $calculated,
                    'custom_amount' => null,
                    'is_fixed_amount' => $line->rate_type->value === 'fixed_amount',
                ])->save();
            });

        Schema::table('lorry_hire_goods_lines', function (Blueprint $table): void {
            $table->dropColumn('custom_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lorry_hire_goods_lines')) {
            return;
        }

        if (! Schema::hasColumn('lorry_hire_goods_lines', 'custom_amount')) {
            Schema::table('lorry_hire_goods_lines', function (Blueprint $table): void {
                $table->decimal('custom_amount', 18, 2)->nullable()->after('freight_amount');
            });
        }
    }
};
