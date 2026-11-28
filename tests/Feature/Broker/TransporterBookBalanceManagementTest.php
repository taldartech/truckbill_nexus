<?php

use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Auth\WebSessionService;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Models\AccountingPostingLink;
use App\Models\AccountingVoucher;
use App\Models\BankCashAccount;
use App\Models\CustomerWebSession;
use App\Models\TransporterBookBalanceEntry;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
    brokerGrantVoucherPermissions();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

function actAsBookBalanceUser(array $ctx, bool $viewer = false): void
{
    $user = $viewer ? $ctx['viewer'] : $ctx['member'];
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('party master register and show expose book balance and modal actions for active transporters', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    ['transporter' => $transporter] = brokerCreateTransporterAndMarketVehicle($ctx);

    app(TransporterBookBalanceService::class)->recordTransaction($ctx['company'], $ctx['branch'], $ctx['member'], [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'advance',
        'amount' => '4500.00',
        'entry_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'reference_number' => 'ADV-PM-01',
        'remark' => 'Independent transporter advance',
    ]);

    actAsBookBalanceUser($ctx);
    $this->get(route('branch.parties.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/index')
            ->where('bookBalance.can_view', true)
            ->where('bookBalance.can_manage', true)
            ->where('parties.data.0.book_balance', '4500.00'));

    actAsBookBalanceUser($ctx);
    $this->get(route('branch.parties.show', $transporter))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/show')
            ->where('bookBalance.available_balance', '4500.00')
            ->where('bookBalance.can_manage', true)
            ->has('bookBalance.recent_entries', 1));
});

test('independent advance, opening balance, adjustment, and recovery work without lorry hire and generate chronological statement', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    ['transporter' => $transporter] = brokerCreateTransporterAndMarketVehicle($ctx);

    $bank = BankCashAccount::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'name' => 'Book Balance HDFC',
        'account_kind' => 'bank',
        'is_active' => true,
    ]);

    $day1 = CarbonImmutable::now()->subDays(10)->toDateString();
    $day2 = CarbonImmutable::now()->subDays(5)->toDateString();
    $day3 = CarbonImmutable::now()->subDays(3)->toDateString();
    $day4 = CarbonImmutable::now()->subDays(1)->toDateString();

    actAsBookBalanceUser($ctx);
    $this->post(route('branch.broker.transporter-balances.store'), [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'opening_balance',
        'amount' => '1000.00',
        'entry_date' => $day1,
        'reference_number' => 'OB-101',
        'remark' => 'Opening balance brought forward',
        'idempotency_key' => 'bb-ob-1',
    ])->assertRedirect();

    actAsBookBalanceUser($ctx);
    $this->post(route('branch.broker.transporter-balances.store'), [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'advance',
        'amount' => '5000.00',
        'entry_date' => $day2,
        'payment_mode' => 'bank',
        'bank_cash_account_id' => $bank->id,
        'reference_number' => 'UTR-5000',
        'remark' => 'Independent bank advance',
        'idempotency_key' => 'bb-adv-1',
    ])->assertRedirect();

    actAsBookBalanceUser($ctx);
    $this->post(route('branch.broker.transporter-balances.store'), [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'recovery',
        'amount' => '1500.00',
        'entry_date' => $day3,
        'payment_mode' => 'cash',
        'reference_number' => 'REC-1500',
        'remark' => 'Cash refund from transporter',
        'idempotency_key' => 'bb-rec-1',
    ])->assertRedirect();

    actAsBookBalanceUser($ctx);
    $this->post(route('branch.broker.transporter-balances.store'), [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'adjustment',
        'adjustment_direction' => 'deduct',
        'amount' => '500.00',
        'entry_date' => $day4,
        'reference_number' => 'ADJ-500',
        'remark' => 'Shortage adjustment',
        'idempotency_key' => 'bb-adj-1',
    ])->assertRedirect();

    $balances = app(TransporterBookBalanceService::class);
    expect($balances->availableBalance($ctx['company'], $ctx['branch'], $transporter->id))->toBe('4000.00');

    // Full statement check via HTTP + Inertia props (day2..day4 has opening balance 1000.00)
    actAsBookBalanceUser($ctx);
    $this->get(route('branch.broker.transporter-balances.index', [
        'transporter_party_id' => $transporter->id,
        'from_date' => $day2,
        'to_date' => $day4,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('broker/transporter-balances/index')
            ->where('statement.opening_balance', '1000.00')
            ->where('statement.total_debit', '5000.00')
            ->where('statement.total_credit', '2000.00')
            ->where('statement.closing_balance', '4000.00')
            ->where('statement.current_balance', '4000.00')
            ->where('statement.entries.0.debit', '5000.00')
            ->where('statement.entries.0.running_balance', '6000.00')
            ->where('statement.entries.1.credit', '1500.00')
            ->where('statement.entries.1.running_balance', '4500.00')
            ->where('statement.entries.2.credit', '500.00')
            ->where('statement.entries.2.running_balance', '4000.00'));

    // Without Accounting add-on subscribed, zero accounting vouchers should exist.
    expect(AccountingVoucher::query()->where('company_id', $ctx['company']->id)->count())->toBe(0);
});

test('recovery and deduction reject exceeding available balance, reject non-transporter, enforce rbac, and integrate with voucher adjust in advance', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $transporter = $hire->transporter;

    $customer = app(PartyService::class)->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: ['name' => 'Non Transporter Customer', 'contact_number' => '9812345678', 'state' => 'MH', 'city' => 'Mumbai', 'is_gst_applicable' => false],
        roles: [['role' => PartyRoleType::Customer->value]],
        remark: 'Customer only',
    );

    $service = app(TransporterBookBalanceService::class);
    $service->recordTransaction($ctx['company'], $ctx['branch'], $ctx['member'], [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'advance',
        'amount' => '2500.00',
        'entry_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'remark' => 'Seed balance',
    ]);

    // Exceeding available balance rejected cleanly
    actAsBookBalanceUser($ctx);
    $this->post(route('branch.broker.transporter-balances.store'), [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'recovery',
        'amount' => '2500.01',
        'entry_date' => now()->toDateString(),
        'payment_mode' => 'cash',
    ])->assertSessionHasErrors('amount');

    // Non-transporter party rejected
    expect(fn () => $service->recordTransaction($ctx['company'], $ctx['branch'], $ctx['member'], [
        'transporter_party_id' => $customer->id,
        'transaction_type' => 'advance',
        'amount' => '500.00',
        'entry_date' => now()->toDateString(),
        'payment_mode' => 'cash',
    ]))->toThrow(ValidationException::class);

    // Viewer without BROKER_BOOK_BALANCE_MANAGE receives 403
    actAsBookBalanceUser($ctx, viewer: true);
    $this->post(route('branch.broker.transporter-balances.store'), [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'advance',
        'amount' => '500.00',
        'entry_date' => now()->toDateString(),
        'payment_mode' => 'cash',
    ])->assertForbidden();

    // Voucher Payment Adjust in Advance consumes from the same Book Balance ledger
    app(TransporterVoucherService::class)->createForLorryHire($hire->fresh(), $ctx['member'], [
        'amount' => '1500.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::AdjustInAdvance->value,
    ]);

    expect($service->availableBalance($ctx['company'], $ctx['branch'], $transporter->id))->toBe('1000.00');

    // Subsequent recovery above remaining 1000.00 fails under transporter row lock
    expect(fn () => DB::transaction(fn () => $service->recordTransaction($ctx['company'], $ctx['branch'], $ctx['member'], [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'recovery',
        'amount' => '1200.00',
        'entry_date' => now()->toDateString(),
        'payment_mode' => 'cash',
    ])))->toThrow(Exception::class);

    expect($service->availableBalance($ctx['company'], $ctx['branch'], $transporter->id))->toBe('1000.00');
});

test('book balance transactions post balanced accounting vouchers automatically when accounting addon is subscribed', function () {
    $ctx = brokerWorkspace(['accounting']);
    tripActivate($ctx['company'], $ctx['branch']);
    ['transporter' => $transporter] = brokerCreateTransporterAndMarketVehicle($ctx);

    $bank = BankCashAccount::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'name' => 'Accounting Bank',
        'account_kind' => 'bank',
        'is_active' => true,
    ]);

    $service = app(TransporterBookBalanceService::class);
    $advance = $service->recordTransaction($ctx['company'], $ctx['branch'], $ctx['member'], [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'advance',
        'amount' => '3000.00',
        'entry_date' => now()->toDateString(),
        'payment_mode' => 'bank',
        'bank_cash_account_id' => $bank->id,
        'reference_number' => 'ACC-ADV-1',
        'remark' => 'Bank advance posting',
    ]);

    $recovery = $service->recordTransaction($ctx['company'], $ctx['branch'], $ctx['member'], [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'recovery',
        'amount' => '1000.00',
        'entry_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'remark' => 'Cash recovery posting',
    ]);

    $opening = $service->recordTransaction($ctx['company'], $ctx['branch'], $ctx['member'], [
        'transporter_party_id' => $transporter->id,
        'transaction_type' => 'opening_balance',
        'amount' => '500.00',
        'entry_date' => now()->subDays(2)->toDateString(),
        'remark' => 'Opening posting',
    ]);

    $advLink = AccountingPostingLink::query()
        ->where('company_id', $ctx['company']->id)
        ->where('source_type', TransporterBookBalanceEntry::class)
        ->where('source_id', $advance->id)
        ->first();
    $recLink = AccountingPostingLink::query()
        ->where('company_id', $ctx['company']->id)
        ->where('source_type', TransporterBookBalanceEntry::class)
        ->where('source_id', $recovery->id)
        ->first();
    $obLink = AccountingPostingLink::query()
        ->where('company_id', $ctx['company']->id)
        ->where('source_type', TransporterBookBalanceEntry::class)
        ->where('source_id', $opening->id)
        ->first();

    expect($advLink)->not->toBeNull()
        ->and($recLink)->not->toBeNull()
        ->and($obLink)->not->toBeNull();

    $advVoucher = AccountingVoucher::query()->findOrFail($advLink->accounting_voucher_id);
    $recVoucher = AccountingVoucher::query()->findOrFail($recLink->accounting_voucher_id);
    $obVoucher = AccountingVoucher::query()->findOrFail($obLink->accounting_voucher_id);

    expect($advVoucher->voucher_type)->toBe(AccountingVoucherType::Payment)
        ->and((string) $advVoucher->total_debit)->toBe('3000.00')
        ->and((string) $advVoucher->total_credit)->toBe('3000.00')
        ->and($recVoucher->voucher_type)->toBe(AccountingVoucherType::Receipt)
        ->and((string) $recVoucher->total_debit)->toBe('1000.00')
        ->and((string) $recVoucher->total_credit)->toBe('1000.00')
        ->and($obVoucher->voucher_type)->toBe(AccountingVoucherType::Journal)
        ->and((string) $obVoucher->total_debit)->toBe('500.00')
        ->and((string) $obVoucher->total_credit)->toBe('500.00');
});
