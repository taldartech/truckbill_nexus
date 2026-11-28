<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Models\CustomerWebSession;
use App\Models\TransporterVoucher;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    brokerGrantVoucherPermissions();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

function actAsVoucherOperator(array $ctx): void
{
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $ctx['member']->id)->delete();
    test()->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('standalone voucher create and bulk pages are removed in favour of the payment modal', function () {
    expect(Route::has('branch.broker.vouchers.create'))->toBeFalse()
        ->and(Route::has('branch.broker.vouchers.bulk.create'))->toBeFalse()
        ->and(Route::has('branch.broker.vouchers.bulk.store'))->toBeFalse()
        ->and(Route::has('branch.broker.vouchers.payment-context'))->toBeTrue();
});

test('payment context returns hire payable facts and the available book balance', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    actAsVoucherOperator($ctx);

    $this->getJson(route('branch.broker.vouchers.payment-context', ['hire_ids' => [$hire->id]]))
        ->assertOk()
        ->assertJsonPath('hires.0.id', $hire->id)
        ->assertJsonPath('hires.0.remaining_payable', (string) $hire->fresh()->outstanding_amount)
        ->assertJsonPath('book_balance', '0.00')
        ->assertJsonPath('payee.name', $hire->transporter->name)
        ->assertJsonStructure(['payment_type_options', 'payment_mode_options', 'bank_cash_accounts', 'previous_payments', 'today']);
});

test('voucher payment modal posts redirect back and record the voucher', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    actAsVoucherOperator($ctx);
    $register = route('branch.broker.lorry-hires.index', ['status' => 'active', 'page' => 2]);

    $this->from($register)
        ->post(route('branch.broker.vouchers.store'), [
            'lines' => [['lorry_hire_id' => $hire->id, 'amount' => '1000.00']],
            'payment_type' => 'advance',
            'payment_mode' => 'cash',
            'voucher_date' => now()->toDateString(),
        ])
        ->assertRedirect($register)
        ->assertSessionHas('success', 'Voucher payment recorded.');

    expect(TransporterVoucher::query()->where('lorry_hire_id', $hire->id)->count())->toBe(1);
});

test('adjust in advance above the book balance returns the row error and records nothing', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    actAsVoucherOperator($ctx);

    $this->post(route('branch.broker.vouchers.store'), [
        'lines' => [['lorry_hire_id' => $hire->id, 'amount' => '500.00']],
        'payment_type' => 'advance',
        'payment_mode' => 'adjust_in_advance',
        'voucher_date' => now()->toDateString(),
    ])->assertSessionHasErrors([
        'lines.0.amount' => 'Adjustment amount cannot exceed the available Book Balance of ₹0.',
    ]);

    expect(TransporterVoucher::query()->where('lorry_hire_id', $hire->id)->count())->toBe(0);
});

test('paid to pump requires the pump name', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    actAsVoucherOperator($ctx);

    $this->post(route('branch.broker.vouchers.store'), [
        'lines' => [['lorry_hire_id' => $hire->id, 'amount' => '500.00']],
        'payment_type' => 'advance',
        'payment_mode' => 'paid_to_pump',
        'voucher_date' => now()->toDateString(),
    ])->assertSessionHasErrors(['pump_reference' => 'Enter the Pump Name for Paid to Pump.']);
});

test('voucher index links payments to the good hires register', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    actAsVoucherOperator($ctx);

    $this->get(route('branch.broker.vouchers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('broker/vouchers/index')
            ->where('urls.lorry_hires', route('branch.broker.lorry-hires.index', absolute: false))
            ->missing('urls.create')
            ->missing('urls.bulk'));
});

test('voucher store rejects blank, zero, or missing row amounts without silently filling outstanding', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);

    actAsVoucherOperator($ctx);
    $this->post(route('branch.broker.vouchers.store'), [
        'lines' => [['lorry_hire_id' => $hire->id, 'amount' => '']],
        'payment_type' => 'advance',
        'payment_mode' => 'cash',
        'voucher_date' => now()->toDateString(),
    ])->assertSessionHasErrors('lines.0.amount');

    actAsVoucherOperator($ctx);
    $this->post(route('branch.broker.vouchers.store'), [
        'lines' => [['lorry_hire_id' => $hire->id, 'amount' => '0']],
        'payment_type' => 'advance',
        'payment_mode' => 'cash',
        'voucher_date' => now()->toDateString(),
    ])->assertSessionHasErrors('lines.0.amount');

    expect(TransporterVoucher::query()->where('lorry_hire_id', $hire->id)->count())->toBe(0);
});

test('bulk voucher store rejects partial blank rows and creates zero vouchers', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hireOne = brokerCreateLorryHire($ctx);
    $lrTwo = brokerCreateBrokerLrOnVehicle($ctx, $hireOne->vehicle);
    $hireTwo = app(LorryHireService::class)->createFromLorryReceipt(
        $lrTwo,
        $ctx['member'],
        brokerHirePayloadFromLr($lrTwo),
    );

    actAsVoucherOperator($ctx);
    $this->post(route('branch.broker.vouchers.store'), [
        'lines' => [
            ['lorry_hire_id' => $hireOne->id, 'amount' => '500.00'],
            ['lorry_hire_id' => $hireTwo->id, 'amount' => ''],
        ],
        'payment_type' => 'advance',
        'payment_mode' => 'cash',
        'voucher_date' => now()->toDateString(),
    ])->assertSessionHasErrors('lines.1.amount');

    expect(TransporterVoucher::query()->whereIn('lorry_hire_id', [$hireOne->id, $hireTwo->id])->count())->toBe(0);
});

test('book balance store rejects empty or non-positive amounts', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    ['transporter' => $transporter] = brokerCreateTransporterAndMarketVehicle($ctx);

    actAsVoucherOperator($ctx);
    $this->post(route('branch.broker.transporter-balances.store'), [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'advance',
        'amount' => '',
        'entry_date' => now()->toDateString(),
        'payment_mode' => 'cash',
    ])->assertSessionHasErrors('amount');
});
