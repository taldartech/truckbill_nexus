<?php

use App\Domains\Accounting\GlLedgerService;
use App\Domains\Auth\WebSessionService;
use App\Domains\Rbac\PermissionCodes;
use App\Models\AccountGroup;
use App\Models\GlLedger;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

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

test('gl ledger service creates ledger under account group', function () {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);

    $service = app(GlLedgerService::class);
    $groups = $service->groupOptions($ctx['company']);
    expect($groups)->not->toBeEmpty();

    $group = AccountGroup::query()->whereKey($groups[0]['id'])->firstOrFail();
    $ledger = $service->create($ctx['company'], $ctx['member'], [
        'name' => 'Quick Add Ledger',
        'account_group_id' => $group->id,
    ]);

    expect($ledger->name)->toBe('Quick Add Ledger')
        ->and($ledger->account_group_id)->toBe($group->id)
        ->and($ledger->is_system)->toBeFalse();

    expect(fn () => $service->create($ctx['company'], $ctx['member'], [
        'name' => 'Quick Add Ledger',
        'account_group_id' => $group->id,
    ]))->toThrow(ValidationException::class);
});

test('ledger quick create endpoint returns created ledger json', function () {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VIEW);
    tripActivate($ctx['company'], $ctx['branch']);

    app(GlLedgerService::class)->groupOptions($ctx['company']);
    $group = AccountGroup::query()->where('company_id', $ctx['company']->id)->firstOrFail();

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->postJson(route('branch.accounting.ledgers.quick-store'), [
        'name' => 'Office Furniture',
        'account_group_id' => $group->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Office Furniture');

    expect(
        GlLedger::query()
            ->where('company_id', $ctx['company']->id)
            ->where('name', 'Office Furniture')
            ->exists(),
    )->toBeTrue();
});
