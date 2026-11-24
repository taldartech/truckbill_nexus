<?php

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LinkedBilty\LinkedBiltyGroupService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Models\LinkedBiltyGroup;
use App\Models\LinkedBiltyGroupMember;
use App\Models\TransporterVoucher;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditEntry;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    brokerGrantLorryHirePermissions();
    brokerGrantVoucherPermissions();
    brokerGrantLinkedBiltyPermissions();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('linked bilty group requires at least two broker lorry receipts', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $market = brokerCreateTransporterAndMarketVehicle($ctx);
    $lr = brokerCreateBrokerLrOnVehicle($ctx, $market['vehicle']);

    $service = app(LinkedBiltyGroupService::class);

    expect(fn () => $service->createGroup($ctx['member'], $ctx['company'], $ctx['branch'], [
        'vehicle_id' => $market['vehicle']->id,
        'lorry_receipt_ids' => [$lr->id],
    ]))->toThrow(ValidationException::class);
});

test('creates linked bilty group with number and member lorry receipt ids', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $market = brokerCreateTransporterAndMarketVehicle($ctx);
    $vehicle = $market['vehicle'];

    $lrOne = brokerCreateBrokerLrOnVehicle($ctx, $vehicle, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Linked A',
        'package_count' => 10,
        'actual_weight' => 100,
        'charged_weight' => 100,
        'freight_rate_type' => FreightRateType::FixedAmount->value,
        'freight_rate' => 6000,
    ]]);
    $lrTwo = brokerCreateBrokerLrOnVehicle($ctx, $vehicle, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Linked B',
        'package_count' => 10,
        'actual_weight' => 100,
        'charged_weight' => 100,
        'freight_rate_type' => FreightRateType::FixedAmount->value,
        'freight_rate' => 4000,
    ]]);

    $group = app(LinkedBiltyGroupService::class)->createGroup($ctx['member'], $ctx['company'], $ctx['branch'], [
        'vehicle_id' => $vehicle->id,
        'group_date' => now()->toDateString(),
        'lorry_receipt_ids' => [$lrOne->id, $lrTwo->id],
    ]);

    expect($group->group_number)->not->toBe('')
        ->and($group->members)->toHaveCount(2);

    $memberReceiptIds = $group->members->pluck('lorry_receipt_id')->sort()->values()->all();
    expect($memberReceiptIds)->toEqual(collect([$lrOne->id, $lrTwo->id])->sort()->values()->all());
});

test('proportional group payment creates separate vouchers with rounding', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $market = brokerCreateTransporterAndMarketVehicle($ctx);
    $vehicle = $market['vehicle'];

    $lrOne = brokerCreateBrokerLrOnVehicle($ctx, $vehicle, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Pay A',
        'package_count' => 10,
        'actual_weight' => 100,
        'charged_weight' => 100,
        'freight_rate_type' => FreightRateType::FixedAmount->value,
        'freight_rate' => 6000,
    ]]);
    $lrTwo = brokerCreateBrokerLrOnVehicle($ctx, $vehicle, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Pay B',
        'package_count' => 10,
        'actual_weight' => 100,
        'charged_weight' => 100,
        'freight_rate_type' => FreightRateType::FixedAmount->value,
        'freight_rate' => 4000,
    ]]);

    $hireOne = app(LorryHireService::class)->createFromLorryReceipt($lrOne, $ctx['member'], [
        'transporter_party_id' => $lrOne->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrOne),
    ]);
    $hireTwo = app(LorryHireService::class)->createFromLorryReceipt($lrTwo, $ctx['member'], [
        'transporter_party_id' => $lrTwo->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrTwo),
    ]);

    $group = app(LinkedBiltyGroupService::class)->createGroup($ctx['member'], $ctx['company'], $ctx['branch'], [
        'vehicle_id' => $vehicle->id,
        'lorry_receipt_ids' => [$lrOne->id, $lrTwo->id],
    ]);

    LinkedBiltyGroupMember::query()
        ->where('linked_bilty_group_id', $group->id)
        ->where('lorry_receipt_id', $lrOne->id)
        ->update(['lorry_hire_id' => $hireOne->id]);
    LinkedBiltyGroupMember::query()
        ->where('linked_bilty_group_id', $group->id)
        ->where('lorry_receipt_id', $lrTwo->id)
        ->update(['lorry_hire_id' => $hireTwo->id]);

    $preview = app(LinkedBiltyGroupService::class)->previewGroupPayment(
        $group->fresh('members'),
        '2500.00',
        VoucherPaymentType::Advance,
        VoucherPaymentMode::Cash,
    );

    $allocated = collect($preview['lines'])->pluck('allocated_amount')->map(fn (string $amount): float => (float) $amount)->sort()->values()->all();
    expect($allocated)->toEqual([1000.0, 1500.0]);

    $vouchers = app(LinkedBiltyGroupService::class)->payGroup($group->fresh('members'), $ctx['member'], [
        'amount' => '2500.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
        'remark' => 'Linked group pay',
        'idempotency_key' => 'linked-pay-'.Str::uuid(),
    ]);

    expect($vouchers)->toHaveCount(2);

    $amounts = collect($vouchers)->pluck('amount')->sort()->values()->all();
    expect($amounts)->toEqual(['1000.00', '1500.00']);

    foreach ($vouchers as $voucher) {
        expect($voucher->linked_bilty_group_id)->toBe($group->id);
    }

    $members = LinkedBiltyGroupMember::query()
        ->where('linked_bilty_group_id', $group->id)
        ->get()
        ->pluck('share_amount')
        ->sort()
        ->values()
        ->all();
    expect($members)->toEqual(['1000.00', '1500.00']);
});

test('group payment rejects over-allocation', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $lr = $hire->lorryReceipt;
    $vehicleId = $lr->vehicle_id;

    $lrTwo = brokerCreateBrokerLrOnVehicle($ctx, $lr->vehicle, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Small',
        'package_count' => 5,
        'actual_weight' => 50,
        'charged_weight' => 50,
        'freight_rate_type' => FreightRateType::FixedAmount->value,
        'freight_rate' => 2000,
    ]]);
    $hireTwo = app(LorryHireService::class)->createFromLorryReceipt($lrTwo, $ctx['member'], [
        'transporter_party_id' => $lrTwo->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrTwo),
    ]);

    $group = app(LinkedBiltyGroupService::class)->createGroup($ctx['member'], $ctx['company'], $ctx['branch'], [
        'vehicle_id' => $vehicleId,
        'lorry_receipt_ids' => [$lr->id, $lrTwo->id],
    ]);

    LinkedBiltyGroupMember::query()->where('lorry_receipt_id', $lr->id)->update(['lorry_hire_id' => $hire->id]);
    LinkedBiltyGroupMember::query()->where('lorry_receipt_id', $lrTwo->id)->update(['lorry_hire_id' => $hireTwo->id]);

    expect(fn () => app(LinkedBiltyGroupService::class)->payGroup($group->fresh('members'), $ctx['member'], [
        'amount' => '999999.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
    ]))->toThrow(BrokerDomainException::class);
});

test('unauthorized linked bilty create is denied', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $market = brokerCreateTransporterAndMarketVehicle($ctx);
    $lrOne = brokerCreateBrokerLrOnVehicle($ctx, $market['vehicle']);
    $lrTwo = brokerCreateBrokerLrOnVehicle($ctx, $market['vehicle']);

    expect(fn () => app(LinkedBiltyGroupService::class)->createGroup($ctx['viewer'], $ctx['company'], $ctx['branch'], [
        'vehicle_id' => $market['vehicle']->id,
        'lorry_receipt_ids' => [$lrOne->id, $lrTwo->id],
    ]))->toThrow(BrokerAuthorizationException::class);
});

test('idempotent group payment returns cached vouchers', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $market = brokerCreateTransporterAndMarketVehicle($ctx);
    $vehicle = $market['vehicle'];
    $lrOne = brokerCreateBrokerLrOnVehicle($ctx, $vehicle);
    $lrTwo = brokerCreateBrokerLrOnVehicle($ctx, $vehicle);
    $hireOne = app(LorryHireService::class)->createFromLorryReceipt($lrOne, $ctx['member'], [
        'transporter_party_id' => $lrOne->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrOne),
    ]);
    $hireTwo = app(LorryHireService::class)->createFromLorryReceipt($lrTwo, $ctx['member'], [
        'transporter_party_id' => $lrTwo->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrTwo),
    ]);

    $group = app(LinkedBiltyGroupService::class)->createGroup($ctx['member'], $ctx['company'], $ctx['branch'], [
        'vehicle_id' => $vehicle->id,
        'lorry_receipt_ids' => [$lrOne->id, $lrTwo->id],
    ]);

    LinkedBiltyGroupMember::query()->where('lorry_receipt_id', $lrOne->id)->update(['lorry_hire_id' => $hireOne->id]);
    LinkedBiltyGroupMember::query()->where('lorry_receipt_id', $lrTwo->id)->update(['lorry_hire_id' => $hireTwo->id]);

    $service = app(LinkedBiltyGroupService::class);
    $payload = [
        'amount' => '100.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
        'idempotency_key' => 'idem-linked-bilty-001',
    ];

    $first = $service->payGroup($group->fresh('members'), $ctx['member'], $payload);
    $second = $service->payGroup($group->fresh('members'), $ctx['member'], $payload);

    expect(collect($second)->pluck('id')->sort()->values()->all())
        ->toEqual(collect($first)->pluck('id')->sort()->values()->all());

    expect(TransporterVoucher::query()->where('linked_bilty_group_id', $group->id)->count())
        ->toBe(count($first));
});

test('linked bilty group create is audited', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $market = brokerCreateTransporterAndMarketVehicle($ctx);
    $lrOne = brokerCreateBrokerLrOnVehicle($ctx, $market['vehicle']);
    $lrTwo = brokerCreateBrokerLrOnVehicle($ctx, $market['vehicle']);

    $group = app(LinkedBiltyGroupService::class)->createGroup($ctx['member'], $ctx['company'], $ctx['branch'], [
        'vehicle_id' => $market['vehicle']->id,
        'lorry_receipt_ids' => [$lrOne->id, $lrTwo->id],
    ]);

    expect(AuditEntry::query()
        ->where('subject_type', LinkedBiltyGroup::class)
        ->where('subject_id', $group->id)
        ->where('action', AuditAction::Created->value)
        ->exists())->toBeTrue();
});
