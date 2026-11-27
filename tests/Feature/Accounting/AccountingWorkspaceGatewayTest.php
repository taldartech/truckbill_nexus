<?php

use App\Domains\Rbac\PermissionCodes;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/../../Support/AccountingHttpHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * @param  list<array{key: string, links: list<array{key: string, href: string}>}>  $sections
 * @return array<string, list<string>>
 */
function accountingGatewayLinkKeys(array $sections): array
{
    return collect($sections)
        ->mapWithKeys(fn (array $section): array => [
            $section['key'] => array_column($section['links'], 'key'),
        ])
        ->all();
}

it('groups every authorized accounting screen for a full accounting role', function (): void {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    foreach ([
        PermissionCodes::BRANCH_ACCOUNTING_VIEW,
        PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE,
        PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW,
    ] as $code) {
        tripGrant('customer.admin', $code);
    }
    accountingActAs($ctx, $ctx['member']);

    $this->get(route('branch.accounting.workspace'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounting/workspace')
            ->where('permissions.can_create', true)
            ->where('urls.create_voucher', route('branch.accounting.vouchers.create', absolute: false))
            ->where('sections', function ($sections): bool {
                $keys = accountingGatewayLinkKeys(collect($sections)->toArray());

                expect(array_keys($keys))->toBe(['transactions', 'masters', 'reports', 'compliance'])
                    ->and($keys['transactions'])->toBe([
                        'day_book',
                        'voucher_contra',
                        'voucher_payment',
                        'voucher_receipt',
                        'voucher_journal',
                        'voucher_sales',
                        'voucher_purchase',
                        'voucher_credit_note',
                        'voucher_debit_note',
                        'fund_transfer',
                    ])
                    ->and($keys['masters'])->toBe(['ledgers', 'opening_balances'])
                    ->and($keys['reports'])->toBe([
                        'trial_balance',
                        'profit_and_loss',
                        'balance_sheet',
                        'cash_book',
                        'bank_book',
                        'outstanding',
                        'ledger_statements',
                    ])
                    ->and($keys['compliance'])->toBe(['tax_summary', 'reconciliation', 'activation']);

                $payment = collect($sections[0]['links'])->firstWhere('key', 'voucher_payment');
                expect($payment['href'])->toBe(route('branch.accounting.vouchers.create', ['voucher_type' => 'payment'], absolute: false));

                return true;
            }));
});

it('shows only view-level screens to a role without voucher or report permission', function (): void {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    tripGrant('customer.viewer', PermissionCodes::BRANCH_ACCOUNTING_VIEW);
    accountingActAs($ctx, $ctx['viewer']);

    $this->get(route('branch.accounting.workspace'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounting/workspace')
            ->where('permissions.can_create', false)
            ->where('urls.create_voucher', null)
            ->where('sections', function ($sections): bool {
                expect(accountingGatewayLinkKeys(collect($sections)->toArray()))->toBe([
                    'transactions' => ['day_book'],
                    'masters' => ['ledgers'],
                    'compliance' => ['activation'],
                ]);

                return true;
            }));

    accountingActAs($ctx, $ctx['viewer']);
    $this->get(route('branch.accounting.vouchers.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('permissions.can_create', false));
});

it('denies the gateway when the company lacks the accounting add-on', function (): void {
    $ctx = tripWorkspace(['broker']);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VIEW);
    accountingActAs($ctx, $ctx['member']);

    $this->get(route('branch.accounting.workspace'))->assertForbidden();
});

it('denies the gateway to an entitled company member without accounting view permission', function (): void {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    accountingActAs($ctx, $ctx['viewer']);

    $this->get(route('branch.accounting.workspace'))->assertForbidden();
});

it('preselects the voucher type requested from the gateway', function (): void {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VIEW);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);
    accountingActAs($ctx, $ctx['member']);

    $this->get(route('branch.accounting.vouchers.create', ['voucher_type' => 'receipt']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('defaultVoucherType', 'receipt'));

    accountingActAs($ctx, $ctx['member']);
    $this->get(route('branch.accounting.vouchers.create', ['voucher_type' => 'not-a-type']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('defaultVoucherType', 'journal'));
});
