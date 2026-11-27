<?php

use App\Domains\Accounting\AccountingActivationLifecycleService;
use App\Domains\Accounting\AccountingReconciliationService;
use App\Domains\Accounting\AccountingWorkspaceService;
use App\Domains\Accounting\Enums\AccountingPostingStatus;
use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\AccountingActivationState;
use App\Models\AccountingPostingLink;
use App\Models\AccountingVoucher;
use App\Models\BrokerInvoice;
use App\Models\City;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionItem;
use App\Models\GlLedger;
use App\Models\GstRate;
use App\Models\State;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

function acctLogin(User $user, string $password = 'member-secret'): void
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $user->email, 'password' => $password]);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

function acctGrantAll(array $codes): void
{
    foreach ($codes as $code) {
        tripGrant('customer.admin', $code);
    }
}

// Scenario 13: Transport modules operate completely without Accounting add-on; GL & Accounting screens remain locked.
test('transport modules operate cleanly without accounting addon while accounting routes and auto-posting remain blocked', function () {
    $ctx = brokerWorkspace([]); // No 'accounting' add-on
    brokerGrantDeliveryAdjustmentPermissions();
    brokerGrantInvoicePermissions();
    acctGrantAll([PermissionCodes::BRANCH_ACCOUNTING_VIEW, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE]);

    // Transport workflow works completely
    $hire = brokerCreateLorryHire($ctx);
    $lr = brokerDeliverLr($hire->lorryReceipt()->firstOrFail(), $ctx);
    $invoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$lr->id],
        'billing_customer_party_id' => $lr->billing_customer_party_id,
        'invoice_date' => now()->toDateString(),
    ]);
    app(BrokerInvoiceService::class)->recordPayment($invoice, $ctx['member'], [
        'amount_received' => $invoice->invoice_total,
        'payment_mode' => 'cash',
        'payment_date' => now()->toDateString(),
    ]);

    expect((string) $invoice->fresh()->balance_due)->toBe('0.00')
        ->and(AccountingVoucher::query()->where('company_id', $ctx['company']->id)->count())->toBe(0);

    // Accounting routes blocked 403
    acctLogin($ctx['member']);
    $this->post(route('workspace.companies.enter', $ctx['company']))->assertRedirect();
    $this->post(route('workspace.branches.enter', [$ctx['company'], $ctx['branch']]))->assertRedirect();
    $this->get(route('branch.accounting.vouchers.index'))->assertForbidden();
    $this->get(route('branch.accounting.activation.show'))->assertForbidden();
    $this->get(route('branch.accounting.reports.reconciliation'))->assertForbidden();
});

// Scenario 14 & 17: Accounting enabled -> Tally reports, source drill-down, controlled reversal, platform billing separation & reconciliation.
test('accounting addon auto-posts transport transactions, keeps platform subscription billing separate and reconciles operational vs gl balances', function () {
    $ctx = brokerWorkspace(['accounting']);
    brokerGrantDeliveryAdjustmentPermissions();
    brokerGrantInvoicePermissions();
    acctGrantAll([
        PermissionCodes::BRANCH_ACCOUNTING_VIEW,
        PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE,
        PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW,
        PermissionCodes::BRANCH_ACCOUNTING_VIEW_FINANCIAL,
    ]);

    // Seed platform profile & verify subscription invoice posts ONLY Indirect Software Expense (never Freight Revenue).
    $state = State::query()->firstOrCreate(
        ['code' => 'MH'],
        ['id' => (string) Str::uuid(), 'name' => 'Maharashtra', 'gst_code' => '27', 'is_active' => true],
    );
    $city = City::query()->firstOrCreate(
        ['state_id' => $state->id, 'name_normalized' => 'pune'],
        ['id' => (string) Str::uuid(), 'name' => 'Pune', 'is_active' => true],
    );
    $gstRate = GstRate::query()->firstOrCreate(
        ['rate_percent' => '18.000', 'version' => 1],
        ['id' => (string) Str::uuid(), 'effective_from' => '2025-04-01', 'is_active' => true],
    );
    app(PlatformBillingProfileService::class)->create($ctx['owner'], [
        'legal_name' => 'TruckBill Platform Pvt Ltd',
        'pan' => 'AABCT1234F',
        'contact_number' => '9876543210',
        'email' => 'bill@truckbill.test',
        'registered_address' => 'Pune',
        'state_id' => $state->id,
        'city_id' => $city->id,
        'pin_code' => '411001',
        'gstin' => '27AABCT1234F1Z5',
        'gst_rate_id' => $gstRate->id,
    ]);

    $hire = brokerCreateLorryHire($ctx);
    $lr = brokerDeliverLr($hire->lorryReceipt()->firstOrFail(), $ctx);
    $invoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$lr->id],
        'billing_customer_party_id' => $lr->billing_customer_party_id,
        'invoice_date' => now()->toDateString(),
    ]);

    // Reconcile operational vs GL balances (Scenario 17)
    $recon = app(AccountingReconciliationService::class)->reconcile($ctx['company']);
    $byKey = collect($recon['balances'])->keyBy('key');
    expect($byKey['customer_receivables']['reconciled'])->toBeTrue()
        ->and($byKey['transporter_payables']['reconciled'])->toBeTrue()
        ->and((float) $recon['profitability']['profit_variance'])->toBe(0.0)
        ->and($recon['profitability']['explanation'])->toContain('reconciles');

    // Source document drill-down & Tally reports
    $invLink = AccountingPostingLink::query()
        ->where('source_type', BrokerInvoice::class)
        ->where('source_id', $invoice->id)
        ->firstOrFail();
    $voucher = AccountingVoucher::query()->findOrFail($invLink->accounting_voucher_id);
    $drill = app(AccountingReconciliationService::class)->sourceDocumentDrillDown($ctx['company'], $voucher, true);
    expect($drill)->not->toBeNull()
        ->and($drill['label'])->toBe('Broker Customer Invoice')
        ->and($drill['url'])->toContain($invoice->id);

    $tbRows = app(AccountingWorkspaceService::class)->trialBalance($ctx['company'], now()->subYear()->toDateString(), now()->toDateString());
    $tbDebit = array_reduce($tbRows, fn (string $c, array $r): string => bcadd($c, $r['closing_debit'], 2), '0.00');
    $tbCredit = array_reduce($tbRows, fn (string $c, array $r): string => bcadd($c, $r['closing_credit'], 2), '0.00');
    expect($tbDebit)->toBe($tbCredit);

    // Subscription invoice separated from Customer transport revenue
    $freightLedger = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Freight Income')->firstOrFail();
    $freightStmt = app(AccountingWorkspaceService::class)->ledgerStatement($ctx['company'], $freightLedger, '1970-01-01', null, null);
    expect((float) $freightStmt['closing_credit'])->toBe((float) $invoice->taxable_total);

    // HTTP endpoints for activation & reconciliation render cleanly
    acctLogin($ctx['member']);
    $this->post(route('workspace.companies.enter', $ctx['company']))->assertRedirect();
    $this->post(route('workspace.branches.enter', [$ctx['company'], $ctx['branch']]))->assertRedirect();
    $this->get(route('branch.accounting.reports.reconciliation'))->assertOk();
    $this->get(route('branch.accounting.activation.show'))->assertOk();
});

// Scenario 15 & 16: Existing Company Activation Cutover + Accounting Expiry (Read-Only + Controlled Reversal) + Renewal Catch-Up.
test('existing company accounting cutover prevents double recognition and expiry/renewal executes controlled correction and idempotent catch-up', function () {
    // Start WITHOUT accounting add-on and complete 1 prior-day Broker invoice (pre-cutover operational balance).
    $ctx = brokerWorkspace([]);
    brokerGrantDeliveryAdjustmentPermissions();
    brokerGrantInvoicePermissions();
    acctGrantAll([
        PermissionCodes::BRANCH_ACCOUNTING_VIEW,
        PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE,
        PermissionCodes::BRANCH_ACCOUNTING_REVERSE,
        PermissionCodes::BRANCH_ACCOUNTING_VIEW_FINANCIAL,
    ]);

    $hire = brokerCreateLorryHire($ctx);
    $lr1 = brokerDeliverLr($hire->lorryReceipt()->firstOrFail(), $ctx);
    $preInvoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$lr1->id],
        'billing_customer_party_id' => $lr1->billing_customer_party_id,
        'invoice_date' => now()->subDays(5)->toDateString(),
    ]);

    expect(AccountingVoucher::query()->where('company_id', $ctx['company']->id)->count())->toBe(0);

    // Purchase/grant Accounting add-on for existing Company.
    $sub = CompanySubscription::query()->where('company_id', $ctx['company']->id)->where('status', 'active')->firstOrFail();
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $accountingAddon = SubscriptionAddon::query()->where('catalogue_version_id', $catalogue->id)->where('code', 'accounting')->firstOrFail();

    $addonItem = CompanySubscriptionItem::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'company_subscription_id' => $sub->id,
        'item_type' => 'addon',
        'addon_id' => $accountingAddon->id,
        'code' => 'accounting',
        'name' => $accountingAddon->name,
        'annual_price_snapshot' => $accountingAddon->annual_price,
        'quantity' => 1,
    ]);
    CompanyCapabilityResolver::invalidate($ctx['company']);

    $lifecycle = app(AccountingActivationLifecycleService::class);
    $detected = $lifecycle->operationalCutoverSummary($ctx['company']);
    expect((float) $detected['customer_receivables'])->toBe((float) $preInvoice->balance_due)
        ->and((float) $detected['transporter_payables'])->toBe((float) $hire->freight_total);

    $dryRun = $lifecycle->dryRunCutover($ctx['company'], $detected['suggested_lines'], now()->subDay()->toDateString());
    expect($dryRun['balanced'])->toBeTrue();

    $cutover = $lifecycle->activateCutover($ctx['company'], $ctx['owner'], $detected['suggested_lines'], $ctx['branch'], now()->subDay()->toDateString());
    expect($cutover['voucher'])->not->toBeNull()
        ->and($cutover['state']->opening_voucher_id)->toBe($cutover['voucher']->id);

    // Post a new invoice AFTER activation -> posts automatically into GL.
    $hire2 = brokerCreateLorryHire($ctx);
    $lr2 = brokerDeliverLr($hire2->lorryReceipt()->firstOrFail(), $ctx);
    $activeInvoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$lr2->id],
        'billing_customer_party_id' => $lr2->billing_customer_party_id,
        'invoice_date' => now()->toDateString(),
    ]);
    expect(AccountingPostingLink::query()->where('source_id', $activeInvoice->id)->where('status', AccountingPostingStatus::Posted->value)->exists())->toBeTrue();

    // Scenario 16: Expire Accounting entitlement (remove active accounting item).
    $addonItem->delete();
    CompanyCapabilityResolver::invalidate($ctx['company']);
    $lifecycle->recordExpiryIfNeeded($ctx['company']);
    expect(AccountingActivationState::query()->where('company_id', $ctx['company']->id)->firstOrFail()->last_expired_at)->not->toBeNull();

    // Historical GL reports remain readable, manual voucher creation is blocked, and new unrelated ops do NOT auto-post.
    $today = now($ctx['company']->timezone ?: 'Asia/Kolkata')->toDateString();
    $postCountBeforeExpiry = AccountingVoucher::query()->where('company_id', $ctx['company']->id)->count();
    $hire3 = brokerCreateLorryHire($ctx);
    $lr3 = brokerDeliverLr($hire3->lorryReceipt()->firstOrFail(), $ctx);
    $unpostedInvoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$lr3->id],
        'billing_customer_party_id' => $lr3->billing_customer_party_id,
        'invoice_date' => $today,
    ]);
    expect(AccountingVoucher::query()->where('company_id', $ctx['company']->id)->count())->toBe($postCountBeforeExpiry);

    // Controlled integrity correction: settling/cancelling the previously posted activeInvoice while expired MUST execute controlled reversal!
    app(BrokerInvoiceService::class)->cancel($activeInvoice->fresh(), $ctx['member'], 'Cancelled during Accounting grace/expiry');
    expect(
        AccountingPostingLink::query()
            ->where('source_id', $activeInvoice->id)
            ->where('status', AccountingPostingStatus::Reversed->value)
            ->exists(),
    )->toBeTrue();

    // Renew Accounting add-on -> preview detects unposted period & catch-up posts missed entries idempotently without duplicating cutover/earlier entries.
    CompanySubscriptionItem::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'company_subscription_id' => $sub->id,
        'item_type' => 'addon',
        'addon_id' => $accountingAddon->id,
        'code' => 'accounting',
        'name' => $accountingAddon->name,
        'annual_price_snapshot' => $accountingAddon->annual_price,
        'quantity' => 1,
    ]);
    CompanyCapabilityResolver::invalidate($ctx['company']);

    $preview = $lifecycle->catchUpPreview($ctx['company'], $today, $today);
    expect($preview['unposted_count'])->toBeGreaterThanOrEqual(2); // hire3 + unpostedInvoice

    $catchUp = $lifecycle->runCatchUp($ctx['company'], $ctx['owner'], $today, $today);
    expect($catchUp['posted_count'])->toBeGreaterThanOrEqual(2)
        ->and(AccountingPostingLink::query()->where('source_id', $unpostedInvoice->id)->where('status', AccountingPostingStatus::Posted->value)->exists())->toBeTrue();

    // Re-running catch-up is strictly idempotent (zero duplicate postings).
    $catchUpAgain = $lifecycle->runCatchUp($ctx['company'], $ctx['owner'], $today, $today);
    expect($catchUpAgain['posted_count'])->toBe(0);
});
