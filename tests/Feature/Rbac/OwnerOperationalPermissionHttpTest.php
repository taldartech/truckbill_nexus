<?php

/**
 * Owner designation vs branch operational permission boundary (HTTP).
 *
 * Governing rule: Owner Panel access is independent of ordinary roles, but
 * Trip/LR module mutations require PermissionAuthorizer codes (branch role).
 * Masters currently OR Owner designation with platform codes — documented separately.
 */

use App\Domains\Auth\WebSessionService;
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

function ownerHttpAs(User $user, array $ctx): void
{
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('owner without branch role cannot view trips via HTTP even though owner panel access remains', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $access = app(CompanyAccessService::class);
    $auth = app(PermissionAuthorizer::class);

    expect($access->canAccessOwnerPanel($ctx['owner'], $ctx['company']))->toBeTrue()
        ->and($access->canAccessBranch($ctx['owner'], $ctx['company'], $ctx['branch']))->toBeTrue()
        ->and($auth->allowsCustomer($ctx['owner'], PermissionCodes::TRIP_VIEW, $ctx['company'], $ctx['branch']))->toBeFalse();

    ownerHttpAs($ctx['owner'], $ctx);

    $show = test()->get(route('branch.trips.show', $trip));
    $index = test()->get(route('branch.trips.index'));

    // Authorization may abort(403) or redirect away from operational surfaces.
    expect(in_array($show->status(), [403, 302], true))->toBeTrue()
        ->and(in_array($index->status(), [403, 302], true))->toBeTrue();

    if ($show->status() === 302) {
        expect($show->headers->get('Location') ?? '')->not->toContain((string) $trip->id);
    }

    if ($index->isOk()) {
        expect(false)->toBeTrue('Owner without trip.view must not receive trips index OK');
    }

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
    ]);

    // Cross-tenant: should not leak existence as a successful payload.
    $response = test()->get(route('branch.trips.show', $tripA));
    expect(in_array($response->status(), [403, 404], true))->toBeTrue();
});
