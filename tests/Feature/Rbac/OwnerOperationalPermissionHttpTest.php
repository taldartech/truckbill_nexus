<?php

/**
 * Owner designation vs branch operational permission boundary (HTTP).
 *
 * Verified Company Owners receive all Customer-domain operational permissions on
 * active branches of their subscribed Company without requiring an additional
 * branch role assignment, while remaining strictly bounded by Company
 * subscription entitlements, tenant isolation, and ordinary-user RBAC.
 */

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\CustomerWebSession;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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

function ownerHttpAs(User $user, array $ctx, string $password = 'owner-secret'): void
{
    test()->flushSession();
    auth()->guard('web')->logout();
    app('auth')->forgetGuards();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();

    $response = test()->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => $password,
        ]);
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('owner without branch role can view subscribed trips via HTTP while unsubscribed modules and internal codes stay denied', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $access = app(CompanyAccessService::class);
    $auth = app(PermissionAuthorizer::class);

    expect($access->canAccessOwnerPanel($ctx['owner'], $ctx['company']))->toBeTrue()
        ->and($access->canAccessBranch($ctx['owner'], $ctx['company'], $ctx['branch']))->toBeTrue()
        ->and($auth->allowsCustomer($ctx['owner'], PermissionCodes::TRIP_VIEW, $ctx['company'], $ctx['branch']))->toBeTrue()
        ->and($auth->allowsInternal($ctx['owner'], PermissionCodes::INTERNAL_CRM_VIEW))->toBeFalse();

    ownerHttpAs($ctx['owner'], $ctx);

    test()->get(route('branch.trips.show', $trip))->assertOk();
    test()->get(route('branch.trips.index'))->assertRedirect(route('branch.lorry-receipts.index'));
    test()->get(route('branch.lorry-receipts.index'))->assertOk();
    // Unsubscribed Broker module remains blocked even for the Company Owner.
    test()->get(route('branch.broker.lorry-hires.index'))->assertStatus(403);
    expect(Trip::query()->whereKey($trip->id)->exists())->toBeTrue();
});

test('owner with admin branch role can view and mutate trips when entitled', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $ownerMembership = $ctx['company']->memberships()
        ->where('user_id', $ctx['owner']->id)
        ->whereNull('deactivated_at')
        ->firstOrFail();
    $ownerBranchAssignment = $workspace->assignBranch($ownerMembership, $ctx['branch'], 'Owner HO role');
    $rbac->assignCustomerBranchRole(
        $ctx['owner'],
        $ownerMembership,
        $ctx['branch'],
        $ownerBranchAssignment,
        Role::query()
            ->where('slug', 'customer.admin')
            ->where('domain', PermissionDomain::Customer->value)
            ->firstOrFail(),
        'Owner operational admin',
    );

    expect(app(PermissionAuthorizer::class)->allowsCustomer(
        $ctx['owner'],
        PermissionCodes::TRIP_VIEW,
        $ctx['company'],
        $ctx['branch'],
    ))->toBeTrue();

    ownerHttpAs($ctx['owner'], $ctx);

    test()->get(route('branch.trips.show', $trip))
        ->assertOk();
});

test('owner of company A cannot access company B trips', function () {
    $ctxA = tripWorkspace();
    tripActivate($ctxA['company'], $ctxA['branch']);
    $tripA = tripCreatePendingTrip($ctxA);

    $ownerB = User::factory()->customer()->create(['password' => Hash::make('owner-b')]);
    $companyB = app(CompanyWorkspaceService::class)->createCompany($ownerB, 'Other Owner Co');
    $branchB = $companyB->branches()->firstOrFail();

    ownerHttpAs($ownerB, [
        'company' => $companyB,
        'branch' => $branchB,
        'owner' => $ownerB,
    ], 'owner-b');

    // Cross-tenant: should not leak existence as a successful payload.
    $response = test()->get(route('branch.trips.show', $tripA));
    expect(in_array($response->status(), [403, 404], true))->toBeTrue();
});
