<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\WorkspaceNavigationService;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function nav(): WorkspaceNavigationService
{
    return app(WorkspaceNavigationService::class);
}

function workspaceSvc(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

test('mru owned company is persisted server-side and cleared when inaccessible', function () {
    $owner = User::factory()->customer()->create(['password' => Hash::make('secret')]);
    workspaceSvc()->createCompany($owner, 'Alpha Co');
    $companyB = workspaceSvc()->createCompany($owner, 'Beta Co');

    expect(nav()->resolveMruOwnedCompany($owner))->toBeNull();

    nav()->rememberOwnedCompany($owner, $companyB);
    $owner->refresh();

    expect($owner->mru_owned_company_id)->toBe($companyB->id)
        ->and(nav()->resolveMruOwnedCompany($owner)?->id)->toBe($companyB->id);

    $other = User::factory()->customer()->create();
    Company::withoutEvents(function () use ($companyB, $other): void {
        $companyB->forceFill(['owner_user_id' => $other->id])->save();
    });

    expect(nav()->resolveMruOwnedCompany($owner->fresh()))->toBeNull()
        ->and($owner->fresh()->mru_owned_company_id)->toBeNull();
});

test('accessible companies distinguish owner and member relationships', function () {
    $user = User::factory()->customer()->create();
    workspaceSvc()->createCompany($user, 'Owned Co');

    $otherOwner = User::factory()->customer()->create();
    $memberCompany = workspaceSvc()->createCompany($otherOwner, 'Member Co');
    $branch = $memberCompany->branches()->where('name', 'Head Office')->firstOrFail();
    $membership = workspaceSvc()->addMembership($memberCompany, $user, 'Join');
    workspaceSvc()->assignBranch($membership, $branch, 'Assign HO');

    $rows = nav()->accessibleCompanies($user);

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('name', 'Owned Co')['relationship'])->toBe('Owner')
        ->and($rows->firstWhere('name', 'Member Co')['relationship'])->toBe('Member');
});

test('member without branch assignment is not listed as accessible company', function () {
    $user = User::factory()->customer()->create();
    $otherOwner = User::factory()->customer()->create();
    $memberCompany = workspaceSvc()->createCompany($otherOwner, 'Member Co');
    workspaceSvc()->addMembership($memberCompany, $user, 'Join without branch');

    expect(nav()->accessibleCompanies($user))->toHaveCount(0);
});
