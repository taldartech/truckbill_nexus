<?php

use App\Domains\Broker\Adjustment\BrokerAdjustmentActiveUniqueness;
use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Models\BrokerAdjustment;
use App\Models\LorryHire;
use App\Models\LorryHireCustomAmount;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';

beforeEach(function () {
    BrokerAdjustmentActiveUniqueness::uninstall();
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    brokerGrantLorryHirePermissions();
    brokerGrantDeliveryAdjustmentPermissions();

    if (! Schema::hasTable('broker_adjustments')) {
        test()->markTestSkipped('broker_adjustments table unavailable.');
    }
});

afterEach(function () {
    BrokerAdjustmentActiveUniqueness::uninstall();
    TenantContextHolder::clear();
});

function createDeliveredHireWithAdjustments(array $ctx): LorryHire
{
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    brokerDeliverLr($hire->lorryReceipt, $ctx, 'MEMO-MIG-'.Str::random(4));

    return $hire->fresh(['adjustments', 'customAmounts', 'vouchers']);
}

test('install allows one active fixed adjustment while preserving soft-deleted history', function () {
    $ctx = brokerWorkspace();
    $hire = createDeliveredHireWithAdjustments($ctx);

    $olderId = (string) Str::uuid();
    DB::table('broker_adjustments')->insert([
        'id' => $olderId,
        'company_id' => $hire->company_id,
        'branch_id' => $hire->branch_id,
        'lorry_hire_id' => $hire->id,
        'type' => BrokerAdjustmentType::MiscExpense->value,
        'amount' => '50.00',
        'created_by_user_id' => $ctx['member']->id,
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
        'deleted_at' => now()->subHour(),
    ]);

    DB::table('broker_adjustments')->insert([
        'id' => (string) Str::uuid(),
        'company_id' => $hire->company_id,
        'branch_id' => $hire->branch_id,
        'lorry_hire_id' => $hire->id,
        'type' => BrokerAdjustmentType::MiscExpense->value,
        'amount' => '75.00',
        'created_by_user_id' => $ctx['member']->id,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
        'deleted_at' => null,
    ]);

    BrokerAdjustmentActiveUniqueness::install();

    expect(
        BrokerAdjustment::withTrashed()
            ->where('lorry_hire_id', $hire->id)
            ->where('type', BrokerAdjustmentType::MiscExpense)
            ->count(),
    )->toBe(2);

    expect(function () use ($hire, $ctx): void {
        DB::table('broker_adjustments')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $hire->company_id,
            'branch_id' => $hire->branch_id,
            'lorry_hire_id' => $hire->id,
            'type' => BrokerAdjustmentType::MiscExpense->value,
            'amount' => '25.00',
            'created_by_user_id' => $ctx['member']->id,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    })->toThrow(QueryException::class);
});

test('reconcile active duplicates keeps the latest row and soft-deletes superseded rows', function () {
    $ctx = brokerWorkspace();
    $hire = createDeliveredHireWithAdjustments($ctx);

    $firstId = (string) Str::uuid();
    $secondId = (string) Str::uuid();
    $base = [
        'company_id' => $hire->company_id,
        'branch_id' => $hire->branch_id,
        'lorry_hire_id' => $hire->id,
        'type' => BrokerAdjustmentType::MiscExpense->value,
        'created_by_user_id' => $ctx['member']->id,
        'deleted_at' => null,
    ];

    DB::table('broker_adjustments')->insert([
        ...$base,
        'id' => $firstId,
        'amount' => '90.00',
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);

    DB::table('broker_adjustments')->insert([
        ...$base,
        'id' => $secondId,
        'amount' => '120.00',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $report = BrokerAdjustmentActiveUniqueness::reconcileActiveDuplicates();
    expect($report)->toHaveCount(1)
        ->and($report[0]['kept_id'])->toBe($secondId)
        ->and($report[0]['removed_ids'])->toBe([$firstId]);

    BrokerAdjustmentActiveUniqueness::install();

    $snapshot = app(BrokerAdjustmentService::class)->snapshotFixedAdjustments($hire->fresh());
    expect($snapshot['misc_expense'])->toBe('120.00');

    expect(
        BrokerAdjustment::withTrashed()
            ->where('id', $firstId)
            ->whereNotNull('deleted_at')
            ->exists(),
    )->toBeTrue();
});

test('custom amount particulars remain independent of fixed adjustment uniqueness', function () {
    $ctx = brokerWorkspace();
    $hire = createDeliveredHireWithAdjustments($ctx);

    LorryHireCustomAmount::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $hire->company_id,
        'branch_id' => $hire->branch_id,
        'lorry_hire_id' => $hire->id,
        'description' => 'Loading charges',
        'amount' => '200.00',
        'created_by_user_id' => $ctx['member']->id,
    ]);

    LorryHireCustomAmount::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $hire->company_id,
        'branch_id' => $hire->branch_id,
        'lorry_hire_id' => $hire->id,
        'description' => 'Halting charges',
        'amount' => '-50.00',
        'created_by_user_id' => $ctx['member']->id,
    ]);

    BrokerAdjustmentActiveUniqueness::install();

    app(BrokerAdjustmentService::class)->syncFixedAdjustments($hire->fresh(), $ctx['member'], [
        'misc_expense' => '80.00',
        'detention' => '10.00',
    ]);

    expect(LorryHireCustomAmount::query()->where('lorry_hire_id', $hire->id)->count())->toBe(2)
        ->and(
            BrokerAdjustment::query()
                ->where('lorry_hire_id', $hire->id)
                ->whereNull('deleted_at')
                ->where('type', BrokerAdjustmentType::MiscExpense)
                ->count(),
        )->toBe(1);
});

test('different fixed types on the same hire are allowed after install', function () {
    $ctx = brokerWorkspace();
    $hire = createDeliveredHireWithAdjustments($ctx);

    BrokerAdjustmentActiveUniqueness::install();

    $service = app(BrokerAdjustmentService::class);
    $service->syncFixedAdjustments($hire->fresh(), $ctx['member'], [
        'detention' => '25.00',
        'misc_expense' => '30.00',
    ]);

    expect(
        BrokerAdjustment::query()
            ->where('lorry_hire_id', $hire->id)
            ->whereNull('deleted_at')
            ->count(),
    )->toBe(2);
});

test('failed legacy mysql unconditional index can be repaired', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped('MySQL-only legacy index repair.');
    }

    $ctx = brokerWorkspace();
    $hire = createDeliveredHireWithAdjustments($ctx);

    DB::table('broker_adjustments')->insert([
        'id' => (string) Str::uuid(),
        'company_id' => $hire->company_id,
        'branch_id' => $hire->branch_id,
        'lorry_hire_id' => $hire->id,
        'type' => BrokerAdjustmentType::Detention->value,
        'amount' => '15.00',
        'created_by_user_id' => $ctx['member']->id,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => null,
    ]);

    DB::statement(
        'CREATE UNIQUE INDEX '.BrokerAdjustmentActiveUniqueness::LEGACY_MYSQL_INDEX
        .' ON broker_adjustments (lorry_hire_id, type)'
    );

    expect(BrokerAdjustmentActiveUniqueness::hasLegacyMysqlUnconditionalIndex())->toBeTrue();

    BrokerAdjustmentActiveUniqueness::reconcileActiveDuplicates();
    BrokerAdjustmentActiveUniqueness::install();

    expect(BrokerAdjustmentActiveUniqueness::hasLegacyMysqlUnconditionalIndex())->toBeFalse()
        ->and(Schema::hasColumn('broker_adjustments', BrokerAdjustmentActiveUniqueness::MYSQL_GENERATED_COLUMN))->toBeTrue();

    $historicalId = (string) Str::uuid();
    DB::table('broker_adjustments')->insert([
        'id' => $historicalId,
        'company_id' => $hire->company_id,
        'branch_id' => $hire->branch_id,
        'lorry_hire_id' => $hire->id,
        'type' => BrokerAdjustmentType::Detention->value,
        'amount' => '10.00',
        'created_by_user_id' => $ctx['member']->id,
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
        'deleted_at' => now()->subHour(),
    ]);

    expect(BrokerAdjustment::withTrashed()->where('id', $historicalId)->exists())->toBeTrue();
});
