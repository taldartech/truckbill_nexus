<?php

use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Domains\Tax\Exceptions\TdsNoMatchException;
use App\Domains\Tax\GstRateService;
use App\Domains\Tax\TdsRuleEvaluator;
use App\Domains\Tax\TdsRuleService;
use App\Models\GstRate;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('gst rate versioning preserves historical snapshot and selection', function () {
    $admin = User::factory()->internal()->superAdmin()->create();
    $this->actingAs($admin);

    $service = app(GstRateService::class);
    $v1 = $service->createVersion($admin, [
        'rate_percent' => '18.000',
        'effective_from' => '2024-04-01',
    ]);

    $snapshot = $service->snapshot($v1);

    $v2 = $service->createVersion($admin, [
        'rate_percent' => '18.000',
        'effective_from' => '2025-04-01',
    ]);

    expect($v2->version)->toBe(2)
        ->and($v1->fresh()->is_active)->toBeFalse()
        ->and($v1->fresh()->effective_to?->toDateString())->toBe('2025-03-31');

    $selected = $service->selectForDate('18.000', '2025-06-01');
    expect($selected->id)->toBe($v2->id);

    // Historical snapshot remains immutable even after successor rate.
    expect(GstRate::query()->findOrFail($snapshot['gst_rate_id'])->rate_percent)
        ->toEqual('18.000')
        ->and($snapshot['version'])->toBe(1);
});

test('customer cannot mutate gst rates', function () {
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer);

    expect(fn () => app(GstRateService::class)->createVersion($customer, [
        'rate_percent' => '5',
        'effective_from' => '2025-01-01',
    ]))->toThrow(TaxDomainException::class);
});

test('tds rule priority and and/or evaluation', function () {
    $admin = User::factory()->internal()->superAdmin()->create();
    $this->actingAs($admin);

    $rules = app(TdsRuleService::class);

    $rules->create($admin, [
        'code' => 'INVALID_PAN',
        'name' => 'Inactive PAN 20%',
        'priority' => 10,
        'condition_logic' => 'AND',
        'conditions' => [
            ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'inactive'],
        ],
        'rate_percent' => '20.000',
    ]);

    $rules->create($admin, [
        'code' => 'OWNER_DECLARATION_ZERO',
        'name' => 'TDS declaration received <=10 vehicles',
        'priority' => 20,
        'condition_logic' => 'AND',
        'conditions' => [
            ['field' => 'declaration_valid', 'operator' => 'truthy', 'value' => true],
            ['field' => 'goods_vehicle_count', 'operator' => 'lte', 'value' => 10],
        ],
        'rate_percent' => '0.000',
    ]);

    $rules->create($admin, [
        'code' => 'PAN_INDIVIDUAL_1',
        'name' => 'Active PAN individual 1%',
        'priority' => 30,
        'condition_logic' => 'AND',
        'conditions' => [
            ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'active'],
            ['field' => 'pan_fourth_char', 'operator' => 'in', 'value' => ['P', 'H']],
        ],
        'rate_percent' => '1.000',
    ]);

    $evaluator = app(TdsRuleEvaluator::class);

    $invalid = $evaluator->evaluate(['pan_status' => 'inactive']);
    expect($invalid['rate_percent'])->toBe('20.000')
        ->and($invalid['snapshot']['code'])->toBe('INVALID_PAN');

    $zero = $evaluator->evaluate([
        'declaration_valid' => true,
        'goods_vehicle_count' => 8,
        'pan_status' => 'active',
        'pan_fourth_char' => 'P',
    ]);
    expect($zero['rate_percent'])->toBe('0.000');

    $one = $evaluator->evaluate([
        'pan_status' => 'active',
        'pan_fourth_char' => 'P',
    ]);
    expect($one['rate_percent'])->toBe('1.000');
});

test('tds rules reject declaration type conditions and legacy PAN statuses', function (array $condition, string $message) {
    $admin = User::factory()->internal()->superAdmin()->create();
    $this->actingAs($admin);

    expect(fn () => app(TdsRuleService::class)->create($admin, [
        'code' => 'LEGACY_RULE',
        'name' => 'Legacy rule',
        'priority' => 5,
        'condition_logic' => 'AND',
        'conditions' => [$condition],
        'rate_percent' => '1.000',
    ]))->toThrow(ValidationException::class, $message);
})->with([
    'declaration type' => [['field' => 'declaration_type', 'operator' => 'eq', 'value' => 'owner'], 'Unsupported TDS condition field: declaration_type.'],
    'legacy invalid PAN status' => [['field' => 'pan_status', 'operator' => 'eq', 'value' => 'invalid'], 'PAN status conditions must be Active or Inactive.'],
]);

test('inactive tds rules are skipped and no-match throws', function () {
    $admin = User::factory()->internal()->superAdmin()->create();
    $this->actingAs($admin);

    $service = app(TdsRuleService::class);
    $rule = $service->create($admin, [
        'code' => 'ONLY_RULE',
        'name' => 'Only rule',
        'priority' => 1,
        'condition_logic' => 'AND',
        'conditions' => [
            ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'active'],
        ],
        'rate_percent' => '2.000',
        'is_active' => true,
    ]);

    $service->update($admin, $rule, ['is_active' => false], 'Deactivate rule');

    expect(fn () => app(TdsRuleEvaluator::class)->evaluate(['pan_status' => 'active']))
        ->toThrow(TdsNoMatchException::class);

    expect(fn () => app(TdsRuleEvaluator::class)->evaluate(['pan_status' => 'unknown']))
        ->toThrow(TdsNoMatchException::class);
});

test('customer cannot mutate tds rules', function () {
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer);

    expect(fn () => app(TdsRuleService::class)->create($customer, [
        'code' => 'X',
        'name' => 'X',
        'priority' => 1,
        'conditions' => [['field' => 'a', 'operator' => 'eq', 'value' => 'b']],
        'rate_percent' => '1',
    ]))->toThrow(TaxDomainException::class);
});

test('gst rate can be created from rate percent only without operator code or name', function () {
    $admin = User::factory()->internal()->superAdmin()->create();
    $this->actingAs($admin);

    $rate = app(GstRateService::class)->createVersion($admin, [
        'rate_percent' => '7.5',
    ], 'Operator create by percent');

    expect($rate->displayLabel())->toBe('7.5%')
        ->and((string) $rate->rate_percent)->toBe('7.500')
        ->and($rate->is_active)->toBeTrue()
        ->and($rate->effective_from)->not->toBeNull();
});

test('tds admin evaluate endpoint returns matching rule without writing financial data', function () {
    $admin = User::factory()->internal()->superAdmin()->create([
        'password' => Hash::make('admin-secret'),
    ]);
    (new TdsReferenceRulesSeeder)->run();

    $this->actingAs($admin)
        ->post(route('admin.tds-rules.evaluate'), [
            'pan_status' => 'inactive',
        ])
        ->assertRedirect();

    $preview = session('tds_preview');
    expect($preview)->toBeArray()
        ->and($preview['matched'] ?? false)->toBeTrue()
        ->and($preview['rate_percent'])->toBe('20.000');

    $this->actingAs($admin)
        ->post(route('admin.tds-rules.evaluate'), [
            'pan_status' => 'active',
            'pan_fourth_char' => 'Z',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('evaluate');
});
