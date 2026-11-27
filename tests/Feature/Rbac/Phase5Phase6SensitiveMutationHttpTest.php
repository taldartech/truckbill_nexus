<?php

/**
 * Phase 5 (OwnFleet) and Phase 6 (Broker) RBAC certification — forged HTTP mutations.
 *
 * Complements ModulePermissionHttpDenialTest, OwnerOperationalPermissionHttpTest,
 * SubscriptionRoleCombinationTest, OwnFleetNoFreightViewTest, and Broker service suites.
 *
 * Invariants:
 * - Server-side denials must not mutate durable state.
 * - Verified Company Owner receives full Customer-domain operational permissions
 *   for subscribed modules; unsubscribed modules and cross-company mutations remain denied.
 * - Ordinary users require both role permissions and company subscription entitlements.
 * - No-Freight personas: financial amounts absent from Inertia props (server redaction).
 */

use App\Domains\Company\CompanyAccessService;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\Rbac\PermissionCodes;
use App\Models\LorryHire;
use App\Models\TransporterVoucher;
use App\Models\Trip;
use App\Models\TripInvoice;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../../Support/RbacPersonaHelpers.php';

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

test('owner designation allows subscribed branch mutations without branch role while unsubscribed and cross-tenant writes stay denied', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $otherOwner = User::factory()->customer()->create();

    expect(app(CompanyAccessService::class)->canViewBranchOperationsPanel(
        $ctx['owner'],
        $ctx['company'],
        $ctx['branch'],
    ))->toBeTrue();

    rbacHttpAs($ctx['owner'], $ctx);
    test()->get(route('branch.dashboard'))->assertOk();

    // Subscribed OwnFleet mutation succeeds for Owner without an ordinary branch role.
    rbacHttpAs($ctx['owner'], $ctx);
    test()->post(route('branch.trips.cancel', $trip), [
        'cancellation_remark' => 'Owner operational cancel',
    ])->assertRedirect();

    expect($trip->fresh()->status)->toBe(TripStatus::Cancelled);

    // Cross-company Owner/user cannot cancel another company's trip.
    $pending = tripCreatePendingTrip($ctx);
    test()->actingAs($otherOwner);
    test()->post(route('branch.trips.cancel', $pending), [
        'cancellation_remark' => 'Cross-tenant forged cancel',
    ])->assertForbidden();
    expect($pending->fresh()->status)->toBe(TripStatus::Pending);
});

test('branch operator without module create cannot forge ownfleet trip from lr', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);
    $operator = rbacBranchPersona($ctx, 'customer.operator', [
        PermissionCodes::LR_VIEW,
        PermissionCodes::TRIP_VIEW,
    ]);

    $before = Trip::query()->count();
    rbacHttpAs($operator, $ctx);
    rbacExpectHttpDenied(test()->post(route('branch.trips.store-from-lr', $lr), [
        'vehicle_id' => $ctx['vehicle']->id,
    ]));

    expect(Trip::query()->count())->toBe($before);
});

test('viewer forged trip cancel and individual invoice create leave durable state unchanged', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    $pending = tripCreatePendingTrip($ctx);
    $pendingStatus = $pending->fresh()->status;
    $invoiceBefore = TripInvoice::query()->count();

    rbacHttpAs($ctx['viewer'], $ctx);
    rbacExpectHttpDenied(test()->post(route('branch.trips.cancel', $pending), [
        'cancellation_remark' => 'Forged viewer cancel',
    ]));
    expect($pending->fresh()->status)->toBe($pendingStatus);

    rbacHttpAs($ctx['viewer'], $ctx);
    $invoiceResponse = test()->post(route('branch.trip-invoices.store-individual'), [
        'lorry_receipt_id' => $lr->fresh()->id,
        'number_mode' => 'manual',
        'manual_number' => 'FORGED-INV',
    ]);

    expect(TripInvoice::query()->count())->toBe($invoiceBefore);
    $invoiceResponse->assertForbidden();
});

test('custom role with trip view only cannot forge dispatch', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $dispatchOnlyViewer = rbacCustomBranchPersona(
        $ctx,
        'customer.cert_trip_view_only',
        'Cert trip view only',
        [PermissionCodes::TRIP_VIEW],
    );

    $beforeStatus = $trip->fresh()->status;
    rbacHttpAs($dispatchOnlyViewer, $ctx);
    rbacExpectHttpDenied(test()->post(route('branch.trips.dispatch', $trip), [
        'starting_odometer' => 100,
    ]));

    expect($trip->fresh()->status)->toBe($beforeStatus)
        ->and($trip->fresh()->status)->toBe(TripStatus::Pending);
});

test('branch admin may dispatch when entitled', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    rbacHttpAs($ctx['member'], $ctx);
    test()->post(route('branch.trips.dispatch', $trip), [
        'starting_odometer' => 50,
    ])->assertRedirect();

    expect($trip->fresh()->status)->toBe(TripStatus::InProgress);
});

test('viewer and operator forged broker lorry hire and voucher writes are denied', function () {
    brokerGrantLorryHirePermissions();
    tripGrant('customer.viewer', PermissionCodes::BROKER_VOUCHER_VIEW);

    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);
    $hire = brokerCreateLorryHire($ctx);

    $hireBeforeStatus = $hire->fresh()->status;
    $hireCount = LorryHire::query()->count();
    $voucherCount = TransporterVoucher::query()->count();

    $operator = rbacBranchPersona($ctx, 'customer.operator', [
        PermissionCodes::LR_VIEW,
        PermissionCodes::BROKER_LORRY_HIRE_VIEW,
    ]);

    rbacHttpAs($ctx['viewer'], $ctx);
    rbacExpectHttpDenied(test()->post(route('branch.broker.lorry-hires.store-from-lr', $lr), [
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]));
    expect(LorryHire::query()->count())->toBe($hireCount);

    rbacHttpAs($ctx['viewer'], $ctx);
    rbacExpectHttpDenied(test()->post(route('branch.broker.lorry-hires.cancel', $hire), [
        'reason' => 'Forged viewer cancel',
        'settle_outstanding' => false,
    ]));
    expect($hire->fresh()->status)->toBe($hireBeforeStatus);

    rbacHttpAs($operator, $ctx);
    $voucherResponse = test()->post(route('branch.broker.vouchers.store'), [
        'lorry_hire_id' => $hire->id,
        'amount' => '100.00',
        'payment_type' => 'advance',
        'payment_mode' => 'cash',
    ]);
    expect(in_array($voucherResponse->status(), [403, 302, 422], true))->toBeTrue()
        ->and(TransporterVoucher::query()->count())->toBe($voucherCount);
});

test('owner without branch role can create subscribed broker lorry hire while unsubscribed ownfleet stays denied', function () {
    brokerGrantLorryHirePermissions();

    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);

    $before = LorryHire::query()->count();
    rbacHttpAs($ctx['owner'], $ctx);
    test()->post(route('branch.broker.lorry-hires.store-from-lr', $lr), [
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ])->assertRedirect();

    expect(LorryHire::query()->count())->toBe($before + 1);

    // Unsubscribed OwnFleet trip creation remains blocked even for the Owner.
    rbacHttpAs($ctx['owner'], $ctx);
    rbacExpectHttpDenied(test()->post(route('branch.trips.store-from-lr', $lr), [
        'vehicle_id' => $ctx['vehicle']->id,
    ]));
});

test('no-freight viewer and operator receive null broker lorry hire freight on show', function () {
    brokerGrantLorryHirePermissions();
    foreach ([
        PermissionCodes::BROKER_LORRY_HIRE_VIEW,
    ] as $code) {
        tripGrant('customer.viewer_no_freight', $code);
        tripGrant('customer.operator_no_freight', $code);
    }

    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);

    $nfViewer = rbacBranchPersona($ctx, 'customer.viewer_no_freight');
    $nfOperator = rbacBranchPersona($ctx, 'customer.operator_no_freight');

    rbacHttpAs($nfViewer, $ctx);
    test()->get(route('branch.broker.lorry-hires.show', $hire))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.can_view_freight', false)
            ->where('lorryHire.freight_total', null));

    rbacHttpAs($nfOperator, $ctx);
    test()->get(route('branch.broker.lorry-hires.show', $hire))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.can_view_freight', false)
            ->where('lorryHire.freight_total', null));
});
