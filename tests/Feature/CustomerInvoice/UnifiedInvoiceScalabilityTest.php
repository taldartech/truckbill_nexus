<?php

use App\Domains\CustomerInvoice\UnifiedCustomerInvoiceReadFacade;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Party;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/InvoiceFacadeTestHelpers.php';

beforeEach(function () {
    (new RbacSeeder)->run();
    tripGrant('customer.admin', PermissionCodes::INVOICE_VIEW);
    tripGrant('customer.admin', PermissionCodes::BROKER_INVOICE_VIEW);
});

test('unified facade requires postgresql for six hundred plus six hundred scalability', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

test('own fleet search paginates beyond five hundred seeded rows', function () {
    $facade = app(UnifiedCustomerInvoiceReadFacade::class);
    ['owner' => $owner, 'member' => $member, 'company' => $company, 'branch' => $branch] = invoiceFacadeWorkspace();

    $customer = Party::factory()->create([
        'company_id' => $company->id,
        'name' => 'Scale Customer OwnFleet',
    ]);

    $totalSeeded = 601;
    seedLeanOwnFleetTripInvoices($company->id, $branch->id, $customer->id, $owner->id, $totalSeeded);

    $perPage = 15;
    $lastPage = (int) ceil($totalSeeded / $perPage);

    $firstPage = $facade->search($member, $company, $branch, [
        'source' => 'own_fleet',
        'per_page' => $perPage,
        'page' => 1,
    ], true);

    $deepPage = $facade->search($member, $company, $branch, [
        'source' => 'own_fleet',
        'per_page' => $perPage,
        'page' => $lastPage,
    ], true);

    expect($firstPage)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($firstPage->total())->toBe($totalSeeded)
        ->and($deepPage->currentPage())->toBe($lastPage)
        ->and($deepPage->count())->toBe($totalSeeded - ($lastPage - 1) * $perPage)
        ->and($firstPage->items()[0]['invoice_number'])->toBe('UF-INV-00601');
});

test('broker search paginates beyond five hundred seeded rows', function () {
    $facade = app(UnifiedCustomerInvoiceReadFacade::class);
    ['owner' => $owner, 'member' => $member, 'company' => $company, 'branch' => $branch] = invoiceFacadeWorkspace();

    $customer = Party::factory()->create([
        'company_id' => $company->id,
        'name' => 'Scale Customer Broker',
    ]);

    $totalSeeded = 601;
    seedLeanBrokerInvoices($company->id, $branch->id, $customer->id, $owner->id, $totalSeeded);

    $perPage = 20;
    $targetPage = 31;

    $page = $facade->search($member, $company, $branch, [
        'source' => 'broker',
        'per_page' => $perPage,
        'page' => $targetPage,
    ], true);

    $needle = $facade->search($member, $company, $branch, [
        'source' => 'broker',
        'per_page' => 5,
        'page' => 1,
        'q' => 'br-inv-00601',
    ], true);

    expect($page->total())->toBe($totalSeeded)
        ->and($page->currentPage())->toBe($targetPage)
        ->and($needle->total())->toBe(1)
        ->and($needle->items()[0]['invoice_number'])->toBe('BR-INV-00601');
});

test('mixed source unified list totals exceed one thousand combined invoices', function () {
    $facade = app(UnifiedCustomerInvoiceReadFacade::class);
    ['owner' => $owner, 'member' => $member, 'company' => $company, 'branch' => $branch] = invoiceFacadeWorkspace();

    $customer = Party::factory()->create([
        'company_id' => $company->id,
        'name' => 'Scale Customer Mixed',
    ]);

    seedLeanOwnFleetTripInvoices($company->id, $branch->id, $customer->id, $owner->id, 601, 'OF-MIX-');
    seedLeanBrokerInvoices($company->id, $branch->id, $customer->id, $owner->id, 601, 'BR-MIX-');

    $page = $facade->search($member, $company, $branch, [
        'per_page' => 25,
        'page' => 45,
    ], true);

    expect($page->total())->toBe(1202)
        ->and($page->lastPage())->toBeGreaterThanOrEqual(49);
});
