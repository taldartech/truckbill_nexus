<?php

use App\Models\BranchRoleAssignment;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../Support/BrokerLorryHireHelpers.php';

beforeEach(function (): void {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
});

afterEach(function (): void {
    TenantContextHolder::clear();
});

test('company owner without LR_DELETE role grant still receives pending bilty destroy action', function (): void {
    $ctx = brokerWorkspace();
    $lr = brokerCreateBrokerLr($ctx);

    BranchRoleAssignment::query()
        ->where('company_id', $ctx['company']->id)
        ->whereHas('membership', fn ($q) => $q->where('user_id', $ctx['owner']->id))
        ->update(['deactivated_at' => now()]);

    Cache::flush();

    $this->actingAs($ctx['owner'])
        ->withSession([
            'workspace.company_id' => $ctx['company']->id,
            'workspace.branch_id' => $ctx['branch']->id,
        ])
        ->get(route('branch.lorry-receipts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.can_delete', true)
            ->where(
                'lorryReceipts.data.0.destroy_url',
                route('branch.lorry-receipts.destroy', [$lr], absolute: false),
            )
        );
});
