<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function navigationWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function navigationLogin(User $user, string $password = 'owner-secret'): TestResponse
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

test('owner panel pages never import CompanySwitcher', function () {
    $ownerPages = collect(File::allFiles(resource_path('js/pages/owner')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.tsx'))
        ->values();

    expect($ownerPages)->not->toBeEmpty();

    foreach ($ownerPages as $file) {
        $contents = File::get($file->getPathname());
        expect($contents)
            ->not->toContain('CompanySwitcher')
            ->and($contents)->not->toContain('company-switcher');
    }
});

test('branch panel pages and sidebar never expose CompanySwitcher', function () {
    $branchPages = collect(File::allFiles(resource_path('js/pages/branch')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.tsx'))
        ->values();

    expect($branchPages)->not->toBeEmpty();

    foreach ($branchPages as $file) {
        $contents = File::get($file->getPathname());
        expect($contents)
            ->not->toContain('CompanySwitcher')
            ->and($contents)->not->toContain('company-switcher')
            ->and($contents)->not->toContain('Return to Management')
            ->and($contents)->not->toContain('Return to Owner Panel');
    }

    $sidebar = File::get(resource_path('js/components/app-sidebar.tsx'));
    expect($sidebar)
        ->not->toContain('CompanySwitcher')
        ->and($sidebar)->toContain('Return to Owner Portal')
        ->and($sidebar)->toContain('/branch/parties')
        ->and($sidebar)->toContain('/branch/vehicles')
        ->and($sidebar)->toContain('/branch/bank-cash')
        ->and($sidebar)->not->toContain("href: '/branch/masters'");

    $masterHeader = File::get(resource_path('js/components/masters/master-page-header.tsx'));
    expect($masterHeader)->not->toContain('CompanySwitcher');
});

test('branch inertia share exposes read-only company and branch names without switcher', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = navigationWorkspace()->createCompany($owner, 'Readonly Context Co', 'Pune');
    $branch = $company->branches()->firstOrFail();

    navigationLogin($owner);
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('company.name', 'Readonly Context Co')
            ->where('branch.name', 'Pune')
            ->where('workspace.active_company_name', 'Readonly Context Co')
            ->where('workspace.active_branch_name', 'Pune')
            ->missing('workspace.companies.0.create'));
});

test('owner global and company subscription routes clear workspace and open without switcher dependency', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = navigationWorkspace()->createCompany($owner, 'Nav Shell Co');

    navigationLogin($owner);

    foreach ([
        route('owner.home'),
        route('owner.companies.index'),
        route('owner.branches.all'),
        route('owner.users.all'),
        route('owner.subscriptions.index'),
        route('owner.audit.all'),
    ] as $url) {
        $this->get($url)->assertOk();
    }

    enterManagementCompany($company, route('owner.subscription'))->assertRedirect();
    $this->get(route('owner.subscription'))->assertOk();
    $this->get(route('owner.settings'))->assertOk();
});

test('branch member cannot open owner panel return target', function () {
    $owner = User::factory()->customer()->create();
    $company = navigationWorkspace()->createCompany($owner, 'Branch Only Host');
    $branch = $company->branches()->firstOrFail();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
        'email_verified_at' => now(),
    ]);
    $membership = navigationWorkspace()->addMembership($company, $member, 'Join');
    navigationWorkspace()->assignBranch($membership, $branch, 'Assign');

    navigationLogin($member, 'member-secret');

    $this->get(route('owner.home'))->assertForbidden();
});
