<?php

namespace Database\Seeders;

use App\Models\GstRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Central GST Rate Master — standard India GST slabs used by company/branch tax config.
 *
 * Idempotent on (code, version). Does not invent speculative product-specific rates.
 * Does not deactivate or rewrite existing customized rate rows.
 */
class GstRateSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, rate_percent: string}>
     */
    private const RATES = [
        ['code' => 'GST0', 'name' => 'GST 0%', 'rate_percent' => '0.000'],
        ['code' => 'GST5', 'name' => 'GST 5%', 'rate_percent' => '5.000'],
        ['code' => 'GST12', 'name' => 'GST 12%', 'rate_percent' => '12.000'],
        ['code' => 'GST18', 'name' => 'GST 18%', 'rate_percent' => '18.000'],
        ['code' => 'GST28', 'name' => 'GST 28%', 'rate_percent' => '28.000'],
    ];

    public function run(): void
    {
        $effectiveFrom = '2017-07-01';

        foreach (self::RATES as $rate) {
            GstRate::query()->firstOrCreate(
                [
                    'code' => $rate['code'],
                    'version' => 1,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'name' => $rate['name'],
                    'rate_percent' => $rate['rate_percent'],
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
