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
        route('owner.subscription', ['company' => $company]),
        route('owner.settings', ['company' => $company]),
    ] as $url) {
        $this->get($url)->assertOk();
    }
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
