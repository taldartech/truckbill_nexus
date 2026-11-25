<?php

namespace Database\Seeders;

use App\Models\PaymentMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Centrally managed payment modes (PRD §8 / Rules §5).
 *
 * Idempotent: unique on `code`. Fresh installs may already contain these rows
 * from the Phase 3 Batch B migration — this seeder only fills gaps.
 */
class PaymentModeSeeder extends Seeder
{
    /**
     * @var list<array{name: string, code: string, sort_order: int}>
     */
    private const MODES = [
        ['name' => 'Cash', 'code' => 'cash', 'sort_order' => 10],
        ['name' => 'Bank Transfer', 'code' => 'bank_transfer', 'sort_order' => 20],
        ['name' => 'UPI', 'code' => 'upi', 'sort_order' => 30],
        ['name' => 'Cheque', 'code' => 'cheque', 'sort_order' => 40],
        ['name' => 'NEFT', 'code' => 'neft', 'sort_order' => 50],
        ['name' => 'RTGS', 'code' => 'rtgs', 'sort_order' => 60],
        ['name' => 'IMPS', 'code' => 'imps', 'sort_order' => 70],
        ['name' => 'Other', 'code' => 'other', 'sort_order' => 80],
    ];

    public function run(): void
    {
        foreach (self::MODES as $mode) {
            PaymentMode::query()->firstOrCreate(
                ['code' => $mode['code']],
                [
                    'id' => (string) Str::uuid(),
                    'name' => $mode['name'],
                    'is_active' => true,
                    'sort_order' => $mode['sort_order'],
                ],
            );
        }
    }
}
