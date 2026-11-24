<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function persistOwnerWorkspace(): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Persist Co', 'HO');
    $branch = $company->branches()->where('name', 'HO')->firstOrFail();

    return compact('owner', 'company', 'branch');
}

function persistLogin(User $owner): void
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }

    $response = test()->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'owner-secret',
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

test('owner home and management list GETs do not clear active branch session (prefetch regression)', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = persistOwnerWorkspace();
    persistLogin($owner);

    enterBranchWorkspace($company, $branch)->assertRedirect();
    expect(session(WorkspaceSession::BRANCH_KEY))->toBe($branch->id);

    // Simulate Inertia prefetch of Owner logo / Return link targets.
    $this->get(route('owner.home'))->assertOk();
    $this->get(route('owner.companies.index'))->assertOk();
    $this->get(route('owner.branches.all'))->assertOk();

    expect(session(WorkspaceSession::COMPANY_KEY))->toBe($company->id)
        ->and(session(WorkspaceSession::BRANCH_KEY))->toBe($branch->id);

    $dashboard = $this->get(route('branch.dashboard'));
    expect([
        'status' => $dashboard->status(),
        'session_company' => session(WorkspaceSession::COMPANY_KEY),
        'session_branch' => session(WorkspaceSession::BRANCH_KEY),
        'auth' => auth()->id(),
        'exception' => $dashboard->exception?->getMessage(),
    ])->toMatchArray([
        'status' => 200,
        'session_company' => $company->id,
        'session_branch' => $branch->id,
        'auth' => $owner->id,
        'exception' => null,
    ]);

    $dashboard->assertInertia(fn ($page) => $page
        ->component('branch/dashboard')
        ->where('workspace.active_branch_id', $branch->id));
});

test('branch context survives permission cache flush within a valid session', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = persistOwnerWorkspace();
    persistLogin($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    Cache::flush();

    $this->get(route('branch.dashboard'))->assertOk();
    expect(auth()->check())->toBeTrue()
        ->and(session(WorkspaceSession::BRANCH_KEY))->toBe($branch->id);

    Cache::flush();

    $this->get(route('branch.dashboard'))->assertOk();
    expect(session(WorkspaceSession::BRANCH_KEY))->toBe($branch->id);
});

test('explicit leave-branch clears branch and lands on owner home', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = persistOwnerWorkspace();
    persistLogin($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->post(route('workspace.context.leave-branch'))
        ->assertRedirect(route('owner.home'));

    expect(session(WorkspaceSession::BRANCH_KEY))->toBeNull()
        ->and(session(WorkspaceSession::COMPANY_KEY))->toBe($company->id);

    $this->get(route('branch.dashboard'))
        ->assertRedirect(route('workspace.landing'));
});

test('company-scoped management GET does not wipe branch session', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = persistOwnerWorkspace();
    persistLogin($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->get(route('owner.settings'))->assertOk();

    expect(session(WorkspaceSession::BRANCH_KEY))->toBe($branch->id);

    $this->get(route('branch.dashboard'))->assertOk();
});
