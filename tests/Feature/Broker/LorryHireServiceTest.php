<?php

use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionItem;
use App\Models\LorryHire;
use App\Models\LorryHireTdsSnapshot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('phase6 broker lorry hire permissions register without blanket grants', function () {
    $codes = collect(Permission::query()->where('code', 'like', 'broker.lorry_hire.%')->pluck('code'));

    expect($codes)->toContain(PermissionCodes::BROKER_LORRY_HIRE_VIEW)
        ->and($codes)->toContain(PermissionCodes::BROKER_LORRY_HIRE_CREATE);

    $admin = Role::query()->where('slug', 'customer.admin')->firstOrFail();
    $granted = RolePermission::query()
        ->where('role_id', $admin->id)
        ->whereIn('permission_id', Permission::query()->where('code', 'like', 'broker.%')->pluck('id'))
        ->exists();

    expect($granted)->toBeFalse();
});

test('broker lr with market vehicle creates pending lorry hire with freight and tds snapshot', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);
    $service = app(LorryHireService::class);

    $hire = $service->createFromLorryReceipt($lr, $ctx['member'], [
        'transporter_party_id' => $lr->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]);

    expect($hire->status)->toBe(LorryHireStatus::Pending)
        ->and($hire->lorry_receipt_id)->toBe($lr->id)
        ->and($hire->freight_total)->toBe('7500.00')
        ->and($hire->tds_amount)->toBe('0.00');

    $snapshot = LorryHireTdsSnapshot::query()->where('lorry_hire_id', $hire->id)->first();

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->freight_basis)->toBe('7500.00')
        ->and($snapshot->rule_code)->toBe('OWNER_DECLARATION_ZERO')
        ->and($snapshot->tds_amount)->toBe('0.00')
        ->and($hire->goodsLines)->toHaveCount(1);
});

test('variable rate freight uses loading weight times rate', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Steel',
        'package_count' => 1,
        'actual_weight' => 2000,
        'charged_weight' => 2000,
        'freight_rate_type' => FreightRateType::PerKg->value,
        'freight_rate' => 2.5,
    ]]);

    $payload = brokerGoodsPayloadFromLr($lr, [[
        'rate_type' => FreightRateType::PerKg->value,
        'rate' => '2.5',
        'is_fixed_amount' => false,
    ]]);

    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'transporter_party_id' => $lr->vehicle?->owner_party_id,
        'goods_lines' => $payload,
    ]);

    expect($hire->freight_total)->toBe('5000.00');
});

test('ownfleet lr cannot create lorry hire', function () {
    $ctx = tripWorkspace([SubscriptionModule::Broker->value, SubscriptionModule::OwnFleet->value]);
    brokerGrantLorryHirePermissions();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);

    expect(fn () => app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'transporter_party_id' => $lr->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]))->toThrow(ValidationException::class);
});

test('own fleet vehicle lr cannot create lorry hire when workflow is broker but vehicle is own', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);
    $lr->vehicle_id = $ctx['vehicle']->id;
    $lr->workflow_context = LrWorkflowContext::Broker;
    $lr->save();

    expect(fn () => app(LorryHireService::class)->createFromLorryReceipt($lr->fresh(), $ctx['member'], [
        'transporter_party_id' => $lr->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]))->toThrow(ValidationException::class);
});

test('duplicate active lorry hire for same lr is rejected', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);
    $service = app(LorryHireService::class);
    $goods = brokerHirePayloadFromLr($lr);

    $service->createFromLorryReceipt($lr, $ctx['member'], $goods);

    expect(fn () => $service->createFromLorryReceipt($lr, $ctx['member'], $goods))
        ->toThrow(BrokerConflictException::class);
});

test('concurrent duplicate lorry hire insert is blocked', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);
    $goods = brokerHirePayloadFromLr($lr);

    DB::transaction(function () use ($ctx, $lr, $goods): void {
        app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], $goods);

        expect(fn () => app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], $goods))
            ->toThrow(BrokerConflictException::class);
    });

    expect(LorryHire::query()->where('lorry_receipt_id', $lr->id)->whereNull('deleted_at')->count())->toBe(1);
});

test('missing broker entitlement denies lorry hire creation', function () {
    $ctx = tripWorkspace([
        SubscriptionModule::OwnFleet->value,
        SubscriptionModule::Broker->value,
    ]);
    brokerGrantLorryHirePermissions();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);

    $subscription = CompanySubscription::query()
        ->where('company_id', $ctx['company']->id)
        ->where('status', 'active')
        ->whereNull('deleted_at')
        ->firstOrFail();

    CompanySubscriptionItem::query()
        ->where('company_subscription_id', $subscription->id)
        ->where('module', SubscriptionModule::Broker->value)
        ->delete();

    Cache::flush();
    tripActivate($ctx['company'], $ctx['branch']);

    expect(app(CoreModuleEntitlementResolver::class)->isEntitled($ctx['company'], SubscriptionModule::Broker))->toBeFalse();

    expect(fn () => app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'transporter_party_id' => $lr->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]))->toThrow(BrokerDomainException::class);
});

test('viewer without create permission cannot create lorry hire', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);

    expect(fn () => app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['viewer'], [
        'transporter_party_id' => $lr->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]))->toThrow(BrokerAuthorizationException::class);
});
