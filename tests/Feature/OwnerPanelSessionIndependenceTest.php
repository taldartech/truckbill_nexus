<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function sessionIndepWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function sessionIndepLogin(User $user, string $password = 'owner-secret'): TestResponse
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }

    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    return $response;
}

/**
 * @return array{
 *     owner: User,
 *     companyA: Company,
 *     companyB: Company,
 *     companyC: Company,
 *     foreign: Company
 * }
 */
function sessionIndepFixture(): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $other = User::factory()->customer()->create([
        'password' => Hash::make('other-secret'),
        'email_verified_at' => now(),
    ]);

    $companyA = sessionIndepWorkspace()->createCompany($owner, 'Active Alpha', 'HO Alpha');
    $companyB = sessionIndepWorkspace()->createCompany($owner, 'Deactivated Beta', 'HO Beta');
    $companyC = sessionIndepWorkspace()->createCompany($owner, 'Fresh Charlie', null);
    $foreign = sessionIndepWorkspace()->createCompany($other, 'Foreign Echo', 'HO Echo');

    $sa = User::factory()->superAdmin()->create();
    sessionIndepWorkspace()->deactivateCompany($sa, $companyB, 'Controlled deactivation fixture');

    return [
        'owner' => $owner,
        'companyA' => $companyA->fresh(),
        'companyB' => $companyB->fresh(),
        'companyC' => $companyC->fresh(),
        'foreign' => $foreign,
    ];
}

test('owner global panels work without company session', function () {
    ['owner' => $owner] = sessionIndepFixture();
    sessionIndepLogin($owner);

    expect(session(WorkspaceSession::COMPANY_KEY))->toBeNull();

    $this->get(route('owner.home'))->assertOk();
    $this->get(route('owner.companies.index'))->assertOk();
    $this->get(route('owner.branches.all'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/branches/all')
            ->where('links.create_branch', route('owner.branches.create.global')));
    $this->get(route('owner.users.all'))->assertOk();
    $this->get(route('owner.subscriptions.index'))->assertOk();
    $this->get(route('owner.audit.all'))->assertOk();
    $this->get(route('owner.branches.create.global'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/branches/create')
            ->where('mode', 'global'));

    expect(session(WorkspaceSession::COMPANY_KEY))->toBeNull()
        ->and(session(WorkspaceSession::BRANCH_KEY))->toBeNull();
});

test('stale deactivated company session does not block create branch for active company', function () {
    [
        'owner' => $owner,
        'companyA' => $companyA,
        'companyB' => $companyB,
        'companyC' => $companyC,
    ] = sessionIndepFixture();

    sessionIndepLogin($owner);

    // Stale deactivated Company B in WorkspaceSession (historical bug source).
    seedManagementSession($companyB);
    expect(session(WorkspaceSession::COMPANY_KEY))->toBe($companyB->id)
        ->and($companyB->isDeactivated())->toBeTrue()
        ->and($companyC->isActive())->toBeTrue();

    // Global create form must load without requiring session company.
    $this->get(route('owner.branches.create.global'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/branches/create')
            ->where('mode', 'global'));

    // Explicit Company C create + store — must not authorize against B.
    $this->get(route('owner.companies.branches.create', ['company' => $companyC]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('company.id', $companyC->id)
            ->where('canCreate', true));

    $this->post(route('owner.companies.branches.store', ['company' => $companyC]), [
        'name' => 'Charlie Depot',
        'remark' => 'First branch for newly created active company',
    ])->assertRedirect(route('owner.branches.all'));

    $branch = Branch::query()
        ->where('company_id', $companyC->id)
        ->where('name', 'Charlie Depot')
        ->first();

    expect($branch)->not->toBeNull()
        ->and($branch->company_id)->toBe($companyC->id);

    // Stale B session must remain untouched by administrative create.
    expect(session(WorkspaceSession::COMPANY_KEY))->toBe($companyB->id);

    // Creating for A also succeeds while B is still in session.
    $this->post(route('owner.companies.branches.store', ['company' => $companyA]), [
        'name' => 'Alpha Depot',
        'remark' => 'Create while stale B session present',
    ])->assertRedirect(route('owner.branches.all'));

    expect(Branch::query()
        ->where('company_id', $companyA->id)
        ->where('name', 'Alpha Depot')
        ->exists())->toBeTrue()
        ->and(session(WorkspaceSession::COMPANY_KEY))->toBe($companyB->id);
});

test('create branch against deactivated company is correctly rejected', function () {
    ['owner' => $owner, 'companyB' => $companyB] = sessionIndepFixture();
    sessionIndepLogin($owner);

    $this->get(route('owner.companies.branches.create', ['company' => $companyB]))
        ->assertForbidden();

    $this->post(route('owner.companies.branches.store', ['company' => $companyB]), [
        'name' => 'Should Fail',
        'remark' => 'Deactivated target',
    ])->assertForbidden();
});

test('create branch for another owners company is denied', function () {
    ['owner' => $owner, 'foreign' => $foreign] = sessionIndepFixture();
    sessionIndepLogin($owner);

    $this->get(route('owner.companies.branches.create', ['company' => $foreign]))
        ->assertForbidden();

    $this->post(route('owner.companies.branches.store', ['company' => $foreign]), [
        'name' => 'Hijack',
        'remark' => 'Cross-owner',
    ])->assertForbidden();
});

test('global create branch with company query preselects owned company without mutating session', function () {
    ['owner' => $owner, 'companyA' => $companyA, 'companyB' => $companyB] = sessionIndepFixture();
    sessionIndepLogin($owner);
    seedManagementSession($companyB);

    $this->get(route('owner.branches.create.global', ['company' => $companyA->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('company.id', $companyA->id)
            ->where('mode', 'global')
            ->where('canCreate', true));

    expect(session(WorkspaceSession::COMPANY_KEY))->toBe($companyB->id);
});

test('branch capacity exhaustion reports capacity error not deactivation', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = sessionIndepWorkspace()->createCompany($owner, 'Capacity Co', 'HO');
    sessionIndepWorkspace()->createBranch($company, 'Second', 'Uses remaining slot');
    $stale = sessionIndepWorkspace()->createCompany($owner, 'Stale Session Co', 'Stale HO');

    sessionIndepLogin($owner);
    seedManagementSession($stale);

    $this->from(route('owner.companies.branches.create', ['company' => $company]))
        ->post(route('owner.companies.branches.store', ['company' => $company]), [
            'name' => 'Third Excess',
            'remark' => 'Should hit capacity',
        ])
        ->assertSessionHasErrors('name');

    expect(Branch::query()->where('company_id', $company->id)->where('name', 'Third Excess')->exists())
        ->toBeFalse()
        ->and(session(WorkspaceSession::COMPANY_KEY))->toBe($stale->id);
});

test('return to owner panel clears company and branch session', function () {
    ['owner' => $owner, 'companyA' => $companyA] = sessionIndepFixture();
    $branch = $companyA->branches()->whereNull('deactivated_at')->firstOrFail();

    sessionIndepLogin($owner);
    enterBranchWorkspace($companyA, $branch)->assertRedirect();

    expect(session(WorkspaceSession::COMPANY_KEY))->toBe($companyA->id)
        ->and(session(WorkspaceSession::BRANCH_KEY))->toBe($branch->id);

    $this->post(route('workspace.context.leave-branch'))
        ->assertRedirect(route('owner.home'));

    expect(session(WorkspaceSession::COMPANY_KEY))->toBeNull()
        ->and(session(WorkspaceSession::BRANCH_KEY))->toBeNull();
});
