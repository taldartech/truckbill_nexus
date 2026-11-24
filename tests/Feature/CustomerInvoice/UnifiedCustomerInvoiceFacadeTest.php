<?php

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/InvoiceFacadeTestHelpers.php';

use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\CustomerInvoice\UnifiedCustomerInvoiceReadFacade;
use App\Domains\Rbac\PermissionCodes;
use App\Models\LorryReceipt;
use App\Models\Party;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    (new RbacSeeder)->run();
    tripGrant('customer.admin', PermissionCodes::INVOICE_VIEW);
    tripGrant('customer.admin', PermissionCodes::BROKER_INVOICE_VIEW);
});

test('resolveSingleSource rejects mixed own fleet and broker receipts', function () {
    $facade = app(UnifiedCustomerInvoiceReadFacade::class);
    ['company' => $company, 'branch' => $branch, 'owner' => $owner] = invoiceFacadeWorkspace();

    $ownFleet = LorryReceipt::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'workflow_context' => LrWorkflowContext::OwnFleet->value,
        'created_by_user_id' => $owner->id,
    ]);

    $broker = LorryReceipt::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'workflow_context' => LrWorkflowContext::Broker->value,
        'created_by_user_id' => $owner->id,
    ]);

    $facade->resolveSingleSource($company, $branch, [$ownFleet->id, $broker->id]);
})->throws(ValidationException::class);

test('unified invoice index is reachable for customer admin member', function () {
    ['member' => $member, 'company' => $company, 'branch' => $branch] = invoiceFacadeWorkspace();

    invoiceFacadeLogin($member, 'member-secret');
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->get(route('branch.invoices.index'))->assertOk();
});

test('unified invoice index applies source filter in inertia props', function () {
    ['member' => $member, 'company' => $company, 'branch' => $branch] = invoiceFacadeWorkspace();

    invoiceFacadeLogin($member, 'member-secret');
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->get(route('branch.invoices.index', ['source' => 'own_fleet']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/invoices/index')
            ->where('filters.source', 'own_fleet'));
});

test('search with own fleet source paginates beyond former five hundred row cap', function () {
    $facade = app(UnifiedCustomerInvoiceReadFacade::class);
    ['owner' => $owner, 'member' => $member, 'company' => $company, 'branch' => $branch] = invoiceFacadeWorkspace();

    $customer = Party::factory()->create([
        'company_id' => $company->id,
        'name' => 'Pagination Customer',
    ]);

    $totalSeeded = 30;
    seedLeanOwnFleetTripInvoices(
        $company->id,
        $branch->id,
        $customer->id,
        $owner->id,
        $totalSeeded,
    );

    $perPage = 10;
    $page = $facade->search(
        $member,
        $company,
        $branch,
        [
            'source' => 'own_fleet',
            'per_page' => $perPage,
            'page' => 1,
        ],
        true,
    );

    expect($page)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($page->total())->toBe($totalSeeded)
        ->and($page->perPage())->toBe($perPage)
        ->and($page->count())->toBe($perPage);
});

test('search applies portable case insensitive invoice number filter via facade', function () {
    $facade = app(UnifiedCustomerInvoiceReadFacade::class);
    ['owner' => $owner, 'member' => $member, 'company' => $company, 'branch' => $branch] = invoiceFacadeWorkspace();

    $customer = Party::factory()->create([
        'company_id' => $company->id,
        'name' => 'Search Customer',
    ]);

    seedLeanOwnFleetTripInvoice($company->id, $branch->id, $customer->id, $owner->id, 'Case-Match-ABC-001');
    seedLeanOwnFleetTripInvoice($company->id, $branch->id, $customer->id, $owner->id, 'Other-Number-002');

    $page = $facade->search(
        $member,
        $company,
        $branch,
        [
            'source' => 'own_fleet',
            'q' => 'case-match-abc',
            'per_page' => 15,
            'page' => 1,
        ],
        true,
    );

    expect($page->total())->toBe(1)
        ->and($page->items()[0]['invoice_number'])->toBe('Case-Match-ABC-001');
});
