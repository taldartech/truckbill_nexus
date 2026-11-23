<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function sessionRoutingLogin(User $user, string $password = 'owner-secret'): void
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
}

test('management home uses /management and requires ownership', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    app(CompanyWorkspaceService::class)->createCompany($owner, 'Session Route Co');

    sessionRoutingLogin($owner);

    $this->get(route('owner.home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('owner/home'));

    expect(url('/management'))->toEndWith('/management');
    expect(route('owner.home'))->toEndWith('/management');
});

test('company-scoped management routes require session company context', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Context Co');

    sessionRoutingLogin($owner);

    $this->get(route('owner.settings'))
        ->assertRedirect(route('owner.companies.index'));

    enterManagementCompany($company, internalRoute('owner.settings'))
        ->assertRedirect(internalRoute('owner.settings'));

    $this->get(route('owner.settings'))->assertOk();
    $this->get(route('owner.subscription'))->assertOk();
});

test('company context accepts absolute same-origin redirect and rejects external targets', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Redirect Co');

    sessionRoutingLogin($owner);

    enterManagementCompany($company, route('owner.settings'))
        ->assertRedirect(internalRoute('owner.settings'));

    $this->post(route('workspace.context.company', ['company' => $company]), [
        'redirect' => 'https://evil.example/phish',
    ])->assertSessionHasErrors('redirect');

    $this->post(route('workspace.context.company', ['company' => $company]), [
        'redirect' => '//evil.example/phish',
    ])->assertSessionHasErrors('redirect');
});

test('branch routes resolve from session and reject missing context', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Branch Session Co');
    $branch = $company->branches()->firstOrFail();

    sessionRoutingLogin($owner);

    $this->get(route('branch.dashboard'))
        ->assertRedirect(route('workspace.landing'));

    enterBranchWorkspace($company, $branch)->assertRedirect(route('branch.dashboard'));

    $this->get(route('branch.dashboard'))->assertOk();
    $this->get(route('branch.masters.index'))->assertOk();
    $this->get(route('branch.parties.index'))->assertOk();
});

test('branch context switch denied for foreign company', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $other = User::factory()->customer()->create();
    $workspace = app(CompanyWorkspaceService::class);
    $workspace->createCompany($owner, 'Mine Co');
    $foreign = $workspace->createCompany($other, 'Foreign Co');
    $foreignBranch = $foreign->branches()->firstOrFail();

    sessionRoutingLogin($owner);

    $this->post(route('workspace.context.branch', [
        'company' => $foreign,
        'branch' => $foreignBranch,
    ]))->assertForbidden();
});

test('stale workspace context version rejects mutations', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Version Co');
    $branch = $company->branches()->firstOrFail();

    sessionRoutingLogin($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $versionBefore = (int) session(WorkspaceSession::VERSION_KEY, 0);
    // Simulate another tab bumping context.
    $this->post(route('workspace.context.branch', [
        'company' => $company,
        'branch' => $branch,
    ]), [
        'redirect' => internalRoute('branch.dashboard'),
    ])->assertRedirect();

    $this->withHeaders(['X-Workspace-Context-Version' => (string) $versionBefore])
        ->post(route('branch.parties.store'), [
            'name' => 'Stale Party',
        ])
        ->assertStatus(409);
});

test('legacy owner and branch uuid urls do not silently switch context', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Legacy Co');
    $branch = $company->branches()->firstOrFail();

    sessionRoutingLogin($owner);

    $this->get('/owner')->assertRedirect('/management');
    $this->get('/owner/companies')->assertRedirect('/management/companies');
    $this->get("/owner/companies/{$company->id}/settings")
        ->assertRedirect('/management/companies');

    $this->get("/branch/companies/{$company->id}/branches/{$branch->id}")
        ->assertRedirect(route('workspace.landing'));
});

test('internal users cannot access management routes', function () {
    $internal = User::factory()->internal()->create([
        'password' => Hash::make('internal-secret'),
        'email_verified_at' => now(),
    ]);
    sessionRoutingLogin($internal, 'internal-secret');

    $this->get(route('owner.home'))->assertForbidden();
    $this->get(route('owner.companies.index'))->assertForbidden();
});
