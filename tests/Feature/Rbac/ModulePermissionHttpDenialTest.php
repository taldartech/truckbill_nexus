<?php

/**
 * Module permission HTTP denials must not mutate durable state.
 */

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\CustomerWebSession;
use App\Models\Enquiry;
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

function moduleHttpAs(User $user, array $ctx): void
{
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

function assignViewerOnly(array $ctx): User
{
    return $ctx['viewer'];
}

test('viewer without trip.create cannot create trip and trip count is unchanged', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);
    $before = Trip::query()->count();

    moduleHttpAs(assignViewerOnly($ctx), $ctx);

    $response = test()->post(route('branch.trips.store-from-lr', $lr), [
        'vehicle_id' => $ctx['vehicle']->id,
    ]);

    expect(in_array($response->status(), [403, 302], true))->toBeTrue()
        ->and(Trip::query()->count())->toBe($before);
});

test('viewer without enquiry.create cannot store enquiry', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    // Ensure Broker module for enquiries if required — workspace may be OwnFleet-only.
    tripActivatePaidModules($ctx['owner'], $ctx['company'], [
        SubscriptionModule::OwnFleet->value,
        SubscriptionModule::Broker->value,
    ]);

    $before = Enquiry::query()->count();
    moduleHttpAs($ctx['viewer'], $ctx);

    $response = test()->post(route('branch.enquiries.store'), [
        'source' => 'phone',
        'pickup_location' => 'Pune',
        'delivery_location' => 'Mumbai',
        'vehicle_quantity' => 1,
    ]);

    expect(in_array($response->status(), [403, 302, 422], true))->toBeTrue()
        ->and(Enquiry::query()->count())->toBe($before);
});

test('operator without lr.view is forbidden on LR index', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);
    $operator = User::factory()->customer()->create(['password' => Hash::make('op-secret')]);
    $membership = $workspace->addMembership($ctx['company'], $operator, 'Join op');
    $assignment = $workspace->assignBranch($membership, $ctx['branch'], 'HO');
    $rbac->assignCustomerBranchRole(
        $ctx['owner'],
        $membership,
        $ctx['branch'],
        $assignment,
        Role::query()
            ->where('slug', 'customer.operator')
            ->where('domain', PermissionDomain::Customer->value)
            ->firstOrFail(),
        'Operator without lr.view',
    );

    // Platform create/view only — no lr.view grant.
    expect(
        app(PermissionAuthorizer::class)->allowsCustomer(
            $operator,
            PermissionCodes::LR_VIEW,
            $ctx['company'],
            $ctx['branch'],
        ),
    )->toBeFalse();

    moduleHttpAs($operator, $ctx);

    $response = test()->get(route('branch.lorry-receipts.index'));
    expect(in_array($response->status(), [403, 302], true))->toBeTrue();
});
