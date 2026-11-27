<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Models\BankCashAccount;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

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

test('lorry hire index exposes voucher payment selection for outstanding active hires', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.broker.lorry-hires.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('broker/lorry-hires/index')
            ->where('permissions.can_create_voucher', true)
            ->where('permissions.can_bulk_voucher', true)
            ->where('urls.payment_context', route('branch.broker.vouchers.payment-context', absolute: false))
            ->where('urls.voucher_store', route('branch.broker.vouchers.store', absolute: false))
            ->where('lorryHires.data.0.id', $hire->id)
            ->where('lorryHires.data.0.payable', true));
});

test('bank payout export streams csv for pending bank vouchers', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);

    $account = BankCashAccount::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'account_kind' => 'bank',
        'name' => 'Payout Bank',
        'bank_name' => 'HDFC',
        'account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
        'is_active' => true,
    ]);

    $voucher = app(TransporterVoucherService::class)->createForLorryHire($hire, $ctx['member'], [
        'amount' => '1500.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Bank->value,
        'bank_cash_account_id' => $account->id,
        'remark' => 'NEFT batch test',
    ]);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $response = $this->get(route('branch.broker.vouchers.export-payout'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    expect($csv)->toContain('Voucher No')
        ->and($csv)->toContain($voucher->voucher_number)
        ->and($csv)->toContain('123456789012')
        ->and($csv)->toContain('1500.00')
        ->and($csv)->toContain('NEFT batch test');
});
