<?php

use App\Domains\Accounting\ChartOfAccountsBootstrapService;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Auth\WebSessionService;
use App\Domains\Rbac\PermissionCodes;
use App\Models\AccountingVoucher;
use App\Models\GlLedger;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * @return array{0: array<string, mixed>, 1: GlLedger, 2: GlLedger}
 */
function openingBalanceHttpContext(): array
{
    $ctx = tripWorkspace(['broker']);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VIEW);
    tripActivate($ctx['company'], $ctx['branch']);

    app(ChartOfAccountsBootstrapService::class)->ensureDefaults($ctx['company']);
    $cash = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Cash')->firstOrFail();
    $capital = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Capital')->firstOrFail();

    return [$ctx, $cash, $capital];
}

function openingBalanceHttpAuth(array $ctx): void
{
    test()->flushSession();
    test()->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

it('renders the opening balance create page', function (): void {
    [$ctx] = openingBalanceHttpContext();
    openingBalanceHttpAuth($ctx);

    $this->get(route('branch.accounting.opening-balances.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/opening-balances/create')
            ->has('ledgers'));
});

it('posts opening balances through the accounting HTTP endpoint', function (): void {
    [$ctx, $cash, $capital] = openingBalanceHttpContext();
    openingBalanceHttpAuth($ctx);

    $this->postJson(route('branch.accounting.opening-balances.store'), [
        'as_of_date' => now()->toDateString(),
        'cutover_key' => 'http-cutover',
        'lines' => [
            ['gl_ledger_id' => $cash->id, 'debit_amount' => '2500.00', 'credit_amount' => '0'],
            ['gl_ledger_id' => $capital->id, 'debit_amount' => '0', 'credit_amount' => '2500.00'],
        ],
    ])->assertRedirect();

    $voucher = AccountingVoucher::query()
        ->where('company_id', $ctx['company']->id)
        ->where('voucher_type', AccountingVoucherType::Opening->value)
        ->firstOrFail();

    expect((string) $voucher->total_debit)->toBe('2500.00');
});
