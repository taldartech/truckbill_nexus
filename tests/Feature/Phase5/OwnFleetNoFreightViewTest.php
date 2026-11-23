<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\CustomerWebSession;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function noFreightViewerForBranch(array $ctx): User
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $viewer = User::factory()->customer()->create(['password' => Hash::make('no-freight-secret')]);
    $membership = $workspace->addMembership($ctx['company'], $viewer, 'Join no freight');
    $assignment = $workspace->assignBranch($membership, $ctx['branch'], 'Assign no freight');
    $rbac->assignCustomerBranchRole(
        $ctx['owner'],
        $membership,
        $ctx['branch'],
        $assignment,
        Role::query()->where('slug', 'customer.viewer_no_freight')->where('domain', PermissionDomain::Customer->value)->firstOrFail(),
        'Grant no-freight viewer',
    );

    foreach ([
        'LR_VIEW',
        'TRIP_VIEW',
        'INVOICE_VIEW',
        'INVOICE_PRINT',
        'PAYMENT_VIEW',
    ] as $suffix) {
        tripGrant('customer.viewer_no_freight', constant('App\\Domains\\Rbac\\PermissionCodes::'.$suffix));
    }

    return $viewer;
}

function branchHttpAs(User $user, array $ctx): void
{
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('no-freight role receives redacted trip invoice and trip profit props', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    $invoice = app(TripInvoiceService::class)->createIndividual(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        $lr->fresh()->id,
        ['number_mode' => 'manual', 'manual_number' => 'NF-INV-1'],
    );

    $viewer = noFreightViewerForBranch($ctx);

    branchHttpAs($viewer, $ctx);
    $this->get(route('branch.trip-invoices.show', [$invoice]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.can_view_freight', false)
            ->where('invoice.invoice_total', null)
            ->where('invoice.base_freight_total', null)
            ->where('invoice.lines.0.base_freight', null));

    branchHttpAs($viewer, $ctx);
    $trip = Trip::query()->where('lorry_receipt_id', $lr->id)->firstOrFail();
    $this->get(route('branch.trips.show', [$trip]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.can_view_freight', false)
            ->where('trip.lr_freight_total', null)
            ->where('profit.trip_profit', null)
            ->where('profit.agreed_freight', null));

    branchHttpAs($viewer, $ctx);
    $this->get(route('branch.lorry-receipts.show', [$lr->fresh()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.can_view_freight', false)
            ->where('lorryReceipt.freight_total', null));

    branchHttpAs($viewer, $ctx);
    $this->get(route('branch.trip-invoices.print', [$invoice]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('invoice.invoice_total', null)
            ->where('invoice.base_freight_total', null));

    branchHttpAs($viewer, $ctx);
    $this->get(route('branch.payments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.can_view_freight', false)
            ->where('payments', fn ($rows) => collect($rows)->every(
                fn ($row) => ($row['amount_received'] ?? null) === null
                    && ($row['settlement_amount'] ?? null) === null,
            )));
});

test('admin member still receives freight amounts on trip invoice show', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    $invoice = app(TripInvoiceService::class)->createIndividual(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        $lr->fresh()->id,
        ['number_mode' => 'manual', 'manual_number' => 'NF-INV-2'],
    );

    branchHttpAs($ctx['member'], $ctx);
    $this->get(route('branch.trip-invoices.show', [$invoice]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.can_view_freight', true)
            ->where('invoice.invoice_total', fn ($value) => $value !== null && $value !== ''));
});
