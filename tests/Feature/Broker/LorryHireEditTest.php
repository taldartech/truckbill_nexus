<?php

use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Auth\WebSessionService;
use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\LorryHire\LorryHireCancellationService;
use App\Domains\Broker\LorryHire\LorryHireRevisionService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\AccountingPostingLink;
use App\Models\AccountingVoucher;
use App\Models\CustomerWebSession;
use App\Models\LorryHire;
use App\Models\LorryHireCustomAmount;
use App\Models\LorryHireRevision;
use App\Models\LorryReceipt;
use App\Models\PartyRole;
use App\Support\Audit\AuditEntry;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    brokerGrantLorryHirePermissions();
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * Broker LR of 10 MT charged weight whose transporter pays 2% TDS (declaration not given).
 *
 * @param  array<string, mixed>  $ctx
 */
function editHireLr(array $ctx): LorryReceipt
{
    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Steel coils',
        'package_count' => 10,
        'actual_weight' => 10000,
        'charged_weight' => 10000,
        'freight_rate_type' => FreightRateType::PerChargedWeight->value,
        'freight_rate' => 1200,
    ]]);

    $role = PartyRole::query()
        ->where('party_id', $lr->intended_payee_party_id)
        ->where('role', PartyRoleType::Transporter->value)
        ->firstOrFail();
    $role->forceFill(['role_attributes' => [
        'pan' => 'BRKTR5678B',
        'pan_status' => 'active',
        'declaration' => false,
    ]])->save();

    return $lr;
}

/**
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $line
 */
function editHireCreate(array $ctx, LorryReceipt $lr, array $line = []): LorryHire
{
    return app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'hire_date' => now()->toDateString(),
        'goods_lines' => [array_merge([
            'lr_goods_row_id' => $lr->goodsRows()->firstOrFail()->id,
            'rate_type' => FreightRateType::PerChargedWeight->value,
            'rate' => '1000',
        ], $line)],
    ]);
}

/**
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function editHirePayload(LorryHire $hire, array $line = [], array $extra = []): array
{
    $hire->loadMissing(['goodsLines', 'customAmounts']);
    $existing = $hire->goodsLines->firstOrFail();

    return array_merge([
        'hire_date' => $hire->hire_date?->toDateString(),
        'remarks' => $hire->remarks,
        'edit_remark' => 'Transporter rate renegotiated',
        'goods_lines' => [array_merge([
            'lr_goods_row_id' => $existing->lr_goods_row_id,
            'rate_type' => $existing->rate_type->value,
            'rate' => $existing->rate,
        ], $line)],
        'custom_amounts' => $hire->customAmounts->map(fn (LorryHireCustomAmount $row): array => [
            'id' => $row->id,
            'description' => $row->description,
            'amount' => number_format((float) $row->amount, 2, '.', ''),
        ])->all(),
    ], $extra);
}

function actAsHireEditor(array $ctx, string $userKey = 'member'): void
{
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $ctx[$userKey]->id)->delete();
    test()->actingAs($ctx[$userKey]);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('positive custom amount increases net payable without changing freight or TDS basis', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = editHireLr($ctx);
    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'hire_date' => now()->toDateString(),
        'goods_lines' => [[
            'lr_goods_row_id' => $lr->goodsRows()->firstOrFail()->id,
            'rate_type' => FreightRateType::PerChargedWeight->value,
            'rate' => '1000',
        ]],
        'custom_amounts' => [[
            'description' => 'Loading surcharge',
            'amount' => '500',
        ]],
    ]);

    $line = $hire->goodsLines->firstOrFail();
    $summary = app(LorryHireCalculationService::class)->summarize($hire->fresh(['customAmounts', 'adjustments', 'vouchers', 'bookBalanceEntries']));

    expect($hire->freight_total)->toBe('10000.00')
        ->and($hire->tds_amount)->toBe('200.00')
        ->and($summary['net_payable'])->toBe('10300.00')
        ->and($line->freight_amount)->toBe('10000.00')
        ->and($hire->customAmounts)->toHaveCount(1)
        ->and($hire->tdsSnapshot?->freight_basis)->toBe('10000.00');
});

test('signed custom amount rows cannot be zero', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = editHireLr($ctx);

    expect(fn () => app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'hire_date' => now()->toDateString(),
        'goods_lines' => [[
            'lr_goods_row_id' => $lr->goodsRows()->firstOrFail()->id,
            'rate_type' => FreightRateType::FixedAmount->value,
            'rate' => '9000',
        ]],
        'custom_amounts' => [[
            'description' => 'Invalid',
            'amount' => '0',
        ]],
    ]))->toThrow(ValidationException::class);
});

test('editing rate and custom amount recalculates freight, tds and payable with a revision and audit', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = editHireCreate($ctx, editHireLr($ctx));
    $service = app(LorryHireRevisionService::class);

    expect($hire->freight_total)->toBe('10000.00')
        ->and($hire->tds_amount)->toBe('200.00');

    $service->revise($hire, $ctx['member'], editHirePayload($hire, ['rate' => '1100']));
    $hire->refresh();

    expect($hire->freight_total)->toBe('11000.00')
        ->and($hire->tds_amount)->toBe('220.00')
        ->and($hire->outstanding_amount)->toBe('10780.00')
        ->and($hire->tdsSnapshot?->fresh()?->freight_basis)->toBe('11000.00');

    $service->revise($hire, $ctx['member'], editHirePayload($hire, [], [
        'custom_amounts' => [[
            'description' => 'Detention agreed',
            'amount' => '800',
        ]],
    ]));
    $hire->refresh();

    expect($hire->freight_total)->toBe('11000.00')
        ->and($hire->tds_amount)->toBe('220.00')
        ->and(app(LorryHireCalculationService::class)->summarize($hire->fresh(['customAmounts', 'adjustments', 'vouchers', 'bookBalanceEntries']))['net_payable'])
        ->toBe('11580.00');

    $customRow = $hire->fresh(['customAmounts'])->customAmounts->firstOrFail();
    $service->revise($hire, $ctx['member'], editHirePayload($hire, [], [
        'custom_amounts' => [[
            'id' => $customRow->id,
            'description' => 'Detention agreed',
            'amount' => '500',
        ]],
    ]));
    $hire->refresh();

    expect($hire->freight_total)->toBe('11000.00')
        ->and($hire->customAmounts)->toHaveCount(1)
        ->and($hire->customAmounts->firstOrFail()->amount)->toBe('500.00');

    $revisions = LorryHireRevision::query()->where('lorry_hire_id', $hire->id)->get();
    $rateRevision = $revisions->first(fn (LorryHireRevision $revision): bool => $revision->before['freight_total'] === '10000.00');
    expect($revisions)->toHaveCount(3)
        ->and($rateRevision?->freight_delta)->toBe('1000.00')
        ->and($rateRevision?->tds_delta)->toBe('20.00')
        ->and($rateRevision?->remark)->toBe('Transporter rate renegotiated')
        ->and($rateRevision?->after['freight_total'])->toBe('11000.00');

    expect(AuditEntry::query()
        ->where('subject_type', LorryHire::class)
        ->where('subject_id', $hire->id)
        ->where('action', 'updated')
        ->count())->toBe(3);
});

test('edit is rejected without changes, without a remark, for cancelled hires and for unauthorized users', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = editHireCreate($ctx, editHireLr($ctx));
    $service = app(LorryHireRevisionService::class);

    expect(fn () => $service->revise($hire, $ctx['member'], editHirePayload($hire)))
        ->toThrow(ValidationException::class, 'No changes to save.')
        ->and(fn () => $service->revise($hire, $ctx['member'], editHirePayload($hire, ['rate' => '1100'], ['edit_remark' => ' '])))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->revise($hire, $ctx['viewer'], editHirePayload($hire, ['rate' => '1100'])))
        ->toThrow(BrokerAuthorizationException::class);

    app(LorryHireCancellationService::class)->cancel($hire, $ctx['member'], [
        'reason' => 'Wrong truck',
        'settle_outstanding' => false,
    ]);

    expect(fn () => $service->revise($hire->fresh(), $ctx['member'], editHirePayload($hire, ['rate' => '1100'])))
        ->toThrow(BrokerDomainException::class);
});

test('after partial payment edits may raise the payable but not cut it below what is paid or reserved', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    brokerGrantVoucherPermissions();
    $hire = editHireCreate($ctx, editHireLr($ctx));
    $vouchers = app(TransporterVoucherService::class);

    $voucher = $vouchers->createForLorryHire($hire, $ctx['member'], [
        'amount' => '8000.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
    ]);
    $vouchers->transitionStatus($voucher, $ctx['member'], VoucherStatus::Paid);
    $service = app(LorryHireRevisionService::class);

    expect(fn () => $service->revise($hire->fresh(), $ctx['member'], editHirePayload($hire, [], [
        'custom_amounts' => [['description' => 'Recovery', 'amount' => '-4000']],
    ])))->toThrow(ValidationException::class, 'below the amount already paid');

    $service->revise($hire->fresh(), $ctx['member'], editHirePayload($hire, ['rate' => '1050']));
    $hire->refresh();

    expect($hire->freight_total)->toBe('10500.00')
        ->and($hire->tds_amount)->toBe('210.00')
        ->and($hire->outstanding_amount)->toBe('2290.00');
});

test('tds stays frozen once the tds submission date for the bilty month has passed', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = editHireLr($ctx);
    $hire = editHireCreate($ctx, $lr);
    $lr->forceFill(['lr_date' => now()->subMonthsNoOverflow(2)->startOfMonth()])->save();

    $preview = app(LorryHireRevisionService::class)->preview($hire->fresh(), $ctx['member'], editHirePayload($hire, ['rate' => '1100']));

    expect($preview['tds_locked_reason'])->toContain('TDS submission date')
        ->and($preview['after']['freight_total'])->toBe('11000.00')
        ->and($preview['after']['tds_amount'])->toBe('200.00')
        ->and($preview['after']['net_payable'])->toBe('10800.00');
});

test('unloading weight keeps applying to the edited variable rate while tds uses loading weight', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    brokerGrantDeliveryAdjustmentPermissions();
    $hire = editHireCreate($ctx, editHireLr($ctx));
    app(BrokerAdjustmentService::class)->createUnloadingWeight($hire, $ctx['member'], ['new_weight' => '9000']);

    app(LorryHireRevisionService::class)->revise($hire->fresh(), $ctx['member'], editHirePayload($hire->fresh(), ['rate' => '1100']));
    $hire->refresh();

    expect($hire->freight_total)->toBe('9900.00')
        ->and($hire->tds_amount)->toBe('220.00');
});

test('edit posts a balanced delta journal when accounting is subscribed and cancellation reverses it', function () {
    $ctx = brokerWorkspace(['accounting']);
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = editHireCreate($ctx, editHireLr($ctx));

    $revision = app(LorryHireRevisionService::class)->revise($hire, $ctx['member'], editHirePayload($hire, ['rate' => '1100']));

    $link = AccountingPostingLink::query()
        ->where('source_type', LorryHireRevision::class)
        ->where('source_id', $revision->id)
        ->where('posting_purpose', 'hire_revised')
        ->firstOrFail();
    $journal = AccountingVoucher::query()->findOrFail($link->accounting_voucher_id);

    expect($journal->voucher_type)->toBe(AccountingVoucherType::Journal)
        ->and((string) $journal->total_debit)->toBe('1000.00')
        ->and((string) $journal->total_credit)->toBe('1000.00');

    $recognition = AccountingPostingLink::query()
        ->where('source_type', LorryHire::class)
        ->where('source_id', $hire->id)
        ->where('posting_purpose', 'hire_recognized')
        ->firstOrFail();
    expect((string) AccountingVoucher::query()->findOrFail($recognition->accounting_voucher_id)->total_debit)->toBe('10000.00');

    app(LorryHireCancellationService::class)->cancel($hire->fresh(), $ctx['member'], [
        'reason' => 'Booking dropped',
        'settle_outstanding' => false,
    ]);

    expect($link->fresh()->status->value)->toBe('reversed');
});

test('edit screen, preview and update work over http and the register and details expose edit', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = editHireCreate($ctx, editHireLr($ctx));
    actAsHireEditor($ctx);

    $this->get(route('branch.broker.lorry-hires.edit', [$hire]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('broker/lorry-hires/edit')
            ->where('hire.hire_number', $hire->hire_number)
            ->where('hire.goods_lines.0.rate', '1000')
            ->has('hire.custom_amounts', 0)
            ->where('payee.id', $hire->transporter_party_id)
            ->has('goodsRows', 1));

    $payload = editHirePayload($hire, [], [
        'custom_amounts' => [[
            'description' => 'Extra payable',
            'amount' => '400',
        ]],
    ]);

    actAsHireEditor($ctx);
    $this->postJson(route('branch.broker.lorry-hires.revision-preview', [$hire]), $payload)
        ->assertOk()
        ->assertJsonPath('changed', true)
        ->assertJsonPath('before.freight_total', '10000.00')
        ->assertJsonPath('after.freight_total', '10000.00')
        ->assertJsonPath('after.custom_amounts_total', '400.00')
        ->assertJsonPath('after.tds_amount', '200.00');

    actAsHireEditor($ctx);
    $this->put(route('branch.broker.lorry-hires.update', [$hire]), array_merge($payload, ['edit_remark' => '']))
        ->assertSessionHasErrors('edit_remark');

    actAsHireEditor($ctx);
    $this->put(route('branch.broker.lorry-hires.update', [$hire]), $payload)
        ->assertRedirect(route('branch.broker.lorry-hires.show', [$hire]))
        ->assertSessionHas('success', 'Lorry Hire updated.');

    actAsHireEditor($ctx);
    $this->get(route('branch.broker.lorry-hires.show', [$hire]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.can_edit', true)
            ->where('urls.edit', route('branch.broker.lorry-hires.edit', [$hire], absolute: false))
            ->where('lorryHire.custom_amounts.0.amount', '400.00')
            ->where('lorryHire.goods_lines.0.freight_amount', '10000.00')
            ->where('revisions.0.remark', 'Transporter rate renegotiated'));

    actAsHireEditor($ctx);
    $this->get(route('branch.broker.lorry-hires.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lorryHires.data.0.urls.edit', route('branch.broker.lorry-hires.edit', [$hire], absolute: false)));

    actAsHireEditor($ctx);
    $this->get(route('branch.lorry-receipts.show', [$hire->lorry_receipt_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('linkedLorryHire.edit_url', route('branch.broker.lorry-hires.edit', [$hire], absolute: false)));
});

test('users without lorry hire edit permission cannot open or submit the edit', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = editHireCreate($ctx, editHireLr($ctx));
    actAsHireEditor($ctx, 'viewer');

    $this->get(route('branch.broker.lorry-hires.edit', [$hire]))->assertForbidden();
    actAsHireEditor($ctx, 'viewer');
    $this->put(route('branch.broker.lorry-hires.update', [$hire]), editHirePayload($hire, ['rate' => '1100']))
        ->assertForbidden();

    expect($hire->fresh()->freight_total)->toBe('10000.00');
});
