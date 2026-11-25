<?php

namespace Database\Seeders;

use App\Models\TdsRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Idempotent reference TDS rules for transporter calculation.
 *
 * Invoked from {@see DatabaseSeeder} after GST rates. Safe to run standalone:
 * `php artisan db:seed --class=TdsReferenceRulesSeeder`
 *
 * Rules mirror TaxMasterTest priorities plus entity 2% for PAN 4th char C/F/A/B/J/L/T.
 */
class TdsReferenceRulesSeeder extends Seeder
{
    /**
     * @var list<array{
     *     code: string,
     *     name: string,
     *     priority: int,
     *     condition_logic: string,
     *     conditions: list<array{field: string, operator: string, value: mixed}>,
     *     rate_percent: string,
     *     description: string
     * }>
     */
    private const RULES = [
        [
            'code' => 'INVALID_PAN',
            'name' => 'Invalid PAN 20%',
            'priority' => 10,
            'condition_logic' => 'OR',
            'conditions' => [
                ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'invalid'],
                ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'inactive'],
            ],
            'rate_percent' => '20.000',
            'description' => 'Invalid or inactive PAN attracts 20% TDS.',
        ],
        [
            'code' => 'OWNER_DECLARATION_ZERO',
            'name' => 'Owner declaration <=10 vehicles',
            'priority' => 20,
            'condition_logic' => 'AND',
            'conditions' => [
                ['field' => 'declaration_type', 'operator' => 'eq', 'value' => 'owner'],
                ['field' => 'declaration_valid', 'operator' => 'truthy', 'value' => true],
                ['field' => 'goods_vehicle_count', 'operator' => 'lte', 'value' => 10],
            ],
            'rate_percent' => '0.000',
            'description' => 'Owner declaration with <=10 goods vehicles → 0% TDS.',
        ],
        [
            'code' => 'PAN_INDIVIDUAL_1',
            'name' => 'Active PAN individual 1%',
            'priority' => 30,
            'condition_logic' => 'AND',
            'conditions' => [
                ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'active'],
                ['field' => 'pan_fourth_char', 'operator' => 'in', 'value' => ['P', 'H']],
            ],
            'rate_percent' => '1.000',
            'description' => 'Active individual / HUF PAN (4th char P or H) → 1%.',
        ],
        [
            'code' => 'PAN_ENTITY_2',
            'name' => 'Active PAN entity 2%',
            'priority' => 40,
            'condition_logic' => 'AND',
            'conditions' => [
                ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'active'],
                ['field' => 'pan_fourth_char', 'operator' => 'in', 'value' => ['C', 'F', 'A', 'B', 'J', 'L', 'T']],
            ],
            'rate_percent' => '2.000',
            'description' => 'Active entity PAN (4th char C/F/A/B/J/L/T) → 2%.',
        ],
    ];

    public function run(): void
    {
        foreach (self::RULES as $rule) {
            $existing = TdsRule::query()->withTrashed()->where('code', $rule['code'])->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                $existing->fill([
                    'name' => $rule['name'],
                    'priority' => $rule['priority'],
                    'is_active' => true,
                    'condition_logic' => $rule['condition_logic'],
                    'conditions' => $rule['conditions'],
                    'rate_percent' => $rule['rate_percent'],
                    'description' => $rule['description'],
                ]);
                $existing->save();

                continue;
            }

            TdsRule::query()->create([
                'id' => (string) Str::uuid(),
                'code' => $rule['code'],
                'name' => $rule['name'],
                'priority' => $rule['priority'],
                'is_active' => true,
                'condition_logic' => $rule['condition_logic'],
                'conditions' => $rule['conditions'],
                'rate_percent' => $rule['rate_percent'],
                'description' => $rule['description'],
            ]);
        }
    }
}
