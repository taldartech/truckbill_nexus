<?php

use App\Domains\Accounting\Enums\AccountingPeriodStatus;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\OwnFleet\Trip\TripExpenseService;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\AccountingPeriod;
use App\Models\AccountingPostingLink;
use App\Models\AccountingVoucher;
use App\Models\BrokerInvoice;
use App\Models\LorryHire;
use App\Models\OwnerCatalogueItem;
use App\Models\TransporterVoucher;
use App\Models\TripExpense;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../../Support/AccountingHttpHelpers.php';

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

function dayBookPostedVoucher(string $sourceType, string $sourceId, string $purpose): AccountingVoucher
{
    $link = AccountingPostingLink::query()
        ->where('source_type', $sourceType)
        ->where('source_id', $sourceId)
        ->where('posting_purpose', $purpose)
        ->firstOrFail();

    return AccountingVoucher::query()->with('lines')->findOrFail($link->accounting_voucher_id);
}

/**
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function dayBookVoucherIds(array $ctx, array $query = []): array
{
    accountingActAs($ctx, $ctx['member']);
    $ids = [];

    test()->get(route('branch.accounting.vouchers.index', $query))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$ids): void {
            $page->component('accounting/vouchers/index');
            $ids = array_column($page->toArray()['props']['vouchers']['data'], 'id');
        });

    sort($ids);

    return $ids;
}

test('operational source events post balanced vouchers that the day book lists with branch, type and date filters', function () {
    $ctx = tripWorkspace([SubscriptionModule::Broker->value, SubscriptionModule::OwnFleet->value], ['accounting']);
    brokerGrantInvoicePermissions();
    brokerGrantDeliveryAdjustmentPermissions();
    brokerGrantVoucherPermissions();
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VIEW);

    $hire = brokerCreateLorryHire($ctx);
    $lr = brokerDeliverLr($hire->lorryReceipt()->firstOrFail(), $ctx, 'MEMO-DAYBOOK-1');

    $invoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$lr->id],
        'billing_customer_party_id' => $lr->billing_customer_party_id,
        'invoice_date' => now()->toDateString(),
    ]);
    $receipt = app(BrokerInvoiceService::class)->recordPayment($invoice, $ctx['member'], [
        'amount_received' => $invoice->invoice_total,
        'payment_mode' => 'cash',
        'payment_date' => now()->toDateString(),
    ]);

    $transporterVoucher = app(TransporterVoucherService::class)->createForLorryHire($hire, $ctx['member'], [
        'amount' => '1000.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
        'voucher_date' => now()->toDateString(),
    ]);
    $transporterVoucher = app(TransporterVoucherService::class)->transitionStatus($transporterVoucher, $ctx['member'], VoucherStatus::Paid);

    tripActivate($ctx['company'], $ctx['branch']);
    $toll = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'catalogue_type' => OwnerCatalogueType::ExpenseCategory->value,
        'name' => 'Toll',
        'code' => 'TOLL',
        'is_active' => true,
    ]);
    $expense = app(TripExpenseService::class)->add(tripCreatePendingTrip($ctx), $ctx['member'], [
        'expense_category_id' => $toll->id,
        'expense_date' => now()->toDateString(),
        'amount' => '500',
    ]);

    $otherBranch = app(CompanyWorkspaceService::class)->createBranch($ctx['company'], 'North Branch');
    $otherBranchHire = brokerCreateLorryHire([...$ctx, 'branch' => $otherBranch, 'member' => $ctx['owner']]);

    $posted = [
        'hire' => dayBookPostedVoucher(LorryHire::class, $hire->id, 'hire_recognized'),
        'invoice' => dayBookPostedVoucher(BrokerInvoice::class, $invoice->id, 'invoice_issued'),
        'receipt' => dayBookPostedVoucher($receipt::class, $receipt->id, 'invoice_payment'),
        'transporter_payment' => dayBookPostedVoucher(TransporterVoucher::class, $transporterVoucher->id, 'transporter_voucher_paid'),
        'trip_expense' => dayBookPostedVoucher(TripExpense::class, $expense->id, 'trip_expense'),
    ];
    $otherBranchVoucher = dayBookPostedVoucher(LorryHire::class, $otherBranchHire->id, 'hire_recognized');

    expect($posted['hire']->voucher_type)->toBe(AccountingVoucherType::Purchase)
        ->and($posted['invoice']->voucher_type)->toBe(AccountingVoucherType::Sales)
        ->and($posted['receipt']->voucher_type)->toBe(AccountingVoucherType::Receipt)
        ->and($posted['transporter_payment']->voucher_type)->toBe(AccountingVoucherType::Payment)
        ->and($posted['trip_expense']->voucher_type)->toBe(AccountingVoucherType::Payment)
        ->and($otherBranchVoucher->branch_id)->toBe($otherBranch->id);

    foreach ([...$posted, 'other_branch' => $otherBranchVoucher] as $label => $voucher) {
        $lineDebit = $voucher->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->debit_amount, 2), '0.00');
        $lineCredit = $voucher->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->credit_amount, 2), '0.00');

        expect((string) $voucher->total_debit)->toBe((string) $voucher->total_credit, "{$label} header balances")
            ->and($lineDebit)->toBe($lineCredit, "{$label} lines balance")
            ->and($lineDebit)->toBe((string) $voucher->total_debit, "{$label} lines match header")
            ->and(bccomp($lineDebit, '0', 2))->toBe(1, "{$label} is non-zero");
    }

    $branchVoucherIds = collect($posted)->pluck('id')->sort()->values()->all();
    $today = now()->toDateString();

    expect(dayBookVoucherIds($ctx))->toBe($branchVoucherIds)
        ->and(dayBookVoucherIds($ctx))->not->toContain($otherBranchVoucher->id)
        ->and(dayBookVoucherIds($ctx, ['voucher_type' => 'receipt']))->toBe([$posted['receipt']->id])
        ->and(dayBookVoucherIds($ctx, ['from_date' => $today, 'to_date' => $today]))->toBe($branchVoucherIds)
        ->and(dayBookVoucherIds($ctx, ['from_date' => now()->addDay()->toDateString()]))->toBe([])
        ->and(dayBookVoucherIds($ctx, ['to_date' => now()->subDay()->toDateString()]))->toBe([]);

    $currentYear = AccountingPeriod::query()
        ->where('company_id', $ctx['company']->id)
        ->whereDate('starts_on', '<=', $today)
        ->whereDate('ends_on', '>=', $today)
        ->firstOrFail();

    expect(dayBookVoucherIds($ctx, ['financial_year_id' => $currentYear->id]))->toBe($branchVoucherIds);
});

test('day book rejects inverted date ranges and financial years from another company', function () {
    $ctx = tripWorkspace([SubscriptionModule::Broker->value], ['accounting']);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VIEW);
    $foreignCompany = app(CompanyWorkspaceService::class)->createCompany(User::factory()->customer()->create(), 'Foreign Co');
    $foreignYear = AccountingPeriod::query()->create([
        'company_id' => $foreignCompany->id,
        'label' => '2026-27',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
        'status' => AccountingPeriodStatus::Open,
    ]);

    accountingActAs($ctx, $ctx['member']);
    $this->get(route('branch.accounting.vouchers.index', [
        'from_date' => '2026-11-10',
        'to_date' => '2026-11-01',
    ]))->assertSessionHasErrors('to_date');

    accountingActAs($ctx, $ctx['member']);
    $this->get(route('branch.accounting.vouchers.index', [
        'financial_year_id' => $foreignYear->id,
    ]))->assertSessionHasErrors('financial_year_id');
});
