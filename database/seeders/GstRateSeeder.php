<?php

namespace Database\Seeders;

use App\Models\GstRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Central GST Rate Master — standard India GST slabs.
 * Idempotent on (rate_percent, version). No synthetic code/name values.
 */
class GstRateSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const RATE_PERCENTS = [
        '0.000',
        '5.000',
        '12.000',
        '18.000',
        '28.000',
    ];

    public function run(): void
    {
        $effectiveFrom = '2017-07-01';

        foreach (self::RATE_PERCENTS as $ratePercent) {
            GstRate::query()->firstOrCreate(
                [
                    'rate_percent' => $ratePercent,
                    'version' => 1,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
