<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Identity\Exceptions\LastSuperAdminProtectedException;
use App\Domains\Identity\IdentityLifecycleService;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function deactWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function deactAccess(): CompanyAccessService
{
    return app(CompanyAccessService::class);
}

function deactIdentities(): IdentityLifecycleService
{
    return app(IdentityLifecycleService::class);
}

function deactOwnedCompany(): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = deactWorkspace()->createCompany($owner, 'Lifecycle Co');
    $branch = $company->branches()->where('name', 'Head Office')->firstOrFail();

    return compact('owner', 'company', 'branch');
}

function withInternalAuditContext(callable $callback): mixed
{
    TenantContextHolder::inject(new TenantContext(
        companyId: null,
        branchId: null,
        ownerId: null,
        isInternal: true,
    ));

    try {
        return $callback();
    } finally {
        TenantContextHolder::clear();
    }
}

function loginAs(User $user, string $password = 'password'): void
{
    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

test('active company allows owner and member branch panel access', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = deactOwnedCompany();
    $member = User::factory()->customer()->create();
    $membership = deactWorkspace()->addMembership($company, $member, 'Join');
    deactWorkspace()->assignBranch($membership, $branch, 'Assign');

    expect(deactAccess()->canAccessBranchPanel($owner, $company, $branch))->toBeTrue()
        ->and(deactAccess()->canAccessBranchPanel($member, $company, $branch))->toBeTrue()
        ->and(deactAccess()->ownerPanelScope($owner, $company))->toBe(CompanyAccessService::OWNER_PANEL_FULL);
});

test('deactivated company blocks branch panel but retains limited owner panel scope', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = deactOwnedCompany();
    $member = User::factory()->customer()->create();
    $membership = deactWorkspace()->addMembership($company, $member, 'Join');
    deactWorkspace()->assignBranch($membership, $branch, 'Assign');
    $sa = User::factory()->superAdmin()->create();

    deactWorkspace()->deactivateCompany($sa, $company, 'Subscription paused');

    expect(deactAccess()->canAccessBranchPanel($owner->fresh(), $company->fresh()))->toBeFalse()
        ->and(deactAccess()->canAccessBranchPanel($member->fresh(), $company->fresh()))->toBeFalse()
        ->and(deactAccess()->canPerformBranchPanelOperation($owner->fresh(), $company->fresh(), $branch))->toBeFalse()
        ->and(deactAccess()->canAccessOwnerPanel($owner->fresh(), $company->fresh()))->toBeTrue()
        ->and(deactAccess()->ownerPanelScope($owner->fresh(), $company->fresh()))->toBe(CompanyAccessService::OWNER_PANEL_ADMIN_BILLING_ONLY)
        ->and(deactAccess()->canAccessOwnerPanel($member->fresh(), $company->fresh()))->toBeFalse()
        ->and(deactAccess()->ownerPanelScope($member->fresh(), $company->fresh()))->toBeNull()
        ->and($owner->fresh()->isActive())->toBeTrue();
});

test('company reactivation restores branch panel for authorized users', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = deactOwnedCompany();
    $member = User::factory()->customer()->create();
    $membership = deactWorkspace()->addMembership($company, $member, 'Join');
    deactWorkspace()->assignBranch($membership, $branch, 'Assign');
    $sa = User::factory()->superAdmin()->create();

    deactWorkspace()->deactivateCompany($sa, $company, 'Pause');
    $reactivated = deactWorkspace()->reactivateCompany($sa, $company->fresh(), 'Resume operations');

    expect($reactivated->isActive())->toBeTrue()
        ->and(deactAccess()->canAccessBranchPanel($owner->fresh(), $reactivated, $branch->fresh()))->toBeTrue()
        ->and(deactAccess()->canAccessBranchPanel($member->fresh(), $reactivated, $branch->fresh()))->toBeTrue()
        ->and(deactAccess()->ownerPanelScope($owner->fresh(), $reactivated))->toBe(CompanyAccessService::OWNER_PANEL_FULL)
        ->and(AuditEntry::query()
            ->where('subject_id', (string) $reactivated->id)
            ->pluck('remark')->all())->toContain('Resume operations');
});

test('unauthorized actors cannot deactivate or reactivate companies', function () {
    ['company' => $company] = deactOwnedCompany();
    $customer = User::factory()->customer()->create();
    $internal = User::factory()->internal()->create();

    expect(fn () => deactWorkspace()->deactivateCompany($customer, $company, 'Nope'))
        ->toThrow(CompanyDomainException::class);

    expect(fn () => deactWorkspace()->deactivateCompany($internal, $company, 'Nope'))
        ->toThrow(CompanyDomainException::class);

    $sa = User::factory()->superAdmin()->create();
    deactWorkspace()->deactivateCompany($sa, $company, 'Pause');

    expect(fn () => deactWorkspace()->reactivateCompany($customer, $company->fresh(), 'Nope'))
        ->toThrow(CompanyDomainException::class);

    $this->actingAs($customer)
        ->post(route('admin.companies.deactivate', $company), ['remark' => 'Hack'])
        ->assertRedirect();

    expect($company->fresh()->isDeactivated())->toBeTrue();
});

test('spoofed company_id in request body is rejected', function () {
    ['company' => $company] = deactOwnedCompany();
    $sa = User::factory()->superAdmin()->create();

    $this->actingAs($sa)
        ->post(route('admin.companies.deactivate', $company), [
            'remark' => 'Should fail closed',
            'company_id' => $company->id,
        ])
        ->assertForbidden();
});

test('owner identity deactivation blocks login owner panel and revokes sessions via version', function () {
    ['owner' => $owner, 'company' => $company] = deactOwnedCompany();
    $sa = User::factory()->superAdmin()->create();

    loginAs($owner, 'owner-secret');
    $versionBefore = (int) $owner->fresh()->web_session_version;
    expect(session(WebSessionService::SESSION_VERSION_KEY))->toBe($versionBefore);

    withInternalAuditContext(fn () => deactIdentities()->deactivate($owner, 'Owner offboarded'));

    expect($owner->fresh()->isDeactivated())->toBeTrue()
        ->and((int) $owner->fresh()->web_session_version)->toBe($versionBefore + 1)
        ->and(deactAccess()->canAccessOwnerPanel($owner->fresh(), $company))->toBeFalse()
        ->and(fn () => deactAccess()->resolveCompanyContext($owner->fresh(), $company))
        ->toThrow(CompanyAccessException::class);

    // Stale stamp fails closed (driver-agnostic; does not require sessions table).
    expect(config('session.driver'))->toBe('array');
    session([WebSessionService::SESSION_VERSION_KEY => $versionBefore]);
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    $this->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'owner-secret',
    ]);
    $this->assertGuest();
});

test('company deactivation does not deactivate owner identity or other companies', function () {
    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $companyA = deactWorkspace()->createCompany($owner, 'Company A');
    $companyB = deactWorkspace()->createCompany($owner, 'Company B');
    $sa = User::factory()->superAdmin()->create();

    deactWorkspace()->deactivateCompany($sa, $companyA, 'Pause A only');

    expect($owner->fresh()->isActive())->toBeTrue()
        ->and($companyA->fresh()->isDeactivated())->toBeTrue()
        ->and($companyB->fresh()->isActive())->toBeTrue()
        ->and(deactAccess()->canAccessOwnerPanel($owner->fresh(), $companyA->fresh()))->toBeTrue()
        ->and(deactAccess()->ownerPanelScope($owner->fresh(), $companyA->fresh()))->toBe(CompanyAccessService::OWNER_PANEL_ADMIN_BILLING_ONLY)
        ->and(deactAccess()->canAccessBranchPanel($owner->fresh(), $companyB->fresh()))->toBeTrue()
        ->and(deactAccess()->ownerPanelScope($owner->fresh(), $companyB->fresh()))->toBe(CompanyAccessService::OWNER_PANEL_FULL);
});

test('identity reactivation restores login eligibility without inventing memberships', function () {
    ['owner' => $owner, 'company' => $company] = deactOwnedCompany();

    withInternalAuditContext(function () use ($owner): void {
        deactIdentities()->deactivate($owner, 'Suspend');
        deactIdentities()->reactivate($owner->fresh(), 'Reinstate identity');
    });

    expect($owner->fresh()->isActive())->toBeTrue()
        ->and(deactAccess()->canAccessOwnerPanel($owner->fresh(), $company))->toBeTrue()
        ->and(AuditEntry::query()->where('remark', 'Reinstate identity')->exists())->toBeTrue();

    loginAs($owner->fresh(), 'owner-secret');
    $this->get(route('dashboard'))->assertOk();
});

test('last active super admin cannot be deactivated or demoted', function () {
    $only = User::factory()->superAdmin()->create();

    withInternalAuditContext(function () use ($only): void {
        expect(fn () => deactIdentities()->deactivate($only, 'Attempt'))
            ->toThrow(LastSuperAdminProtectedException::class);

        expect(fn () => deactIdentities()->revokeSuperAdminPrivilege($only, 'Attempt demote'))
            ->toThrow(LastSuperAdminProtectedException::class);
    });

    $second = User::factory()->superAdmin()->create();

    withInternalAuditContext(function () use ($only): void {
        deactIdentities()->deactivate($only, 'Second SA exists');
    });

    expect($only->fresh()->isDeactivated())->toBeTrue()
        ->and($second->fresh()->isSuperAdmin())->toBeTrue();

    withInternalAuditContext(function () use ($second): void {
        expect(fn () => deactIdentities()->deactivate($second, 'Would leave zero'))
            ->toThrow(LastSuperAdminProtectedException::class);
    });
});

test('non super admin cannot deactivate identities via http', function () {
    $target = User::factory()->customer()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->post(route('admin.identities.deactivate', $target), ['remark' => 'Nope'])
        ->assertForbidden();

    expect($target->fresh()->isDeactivated())->toBeFalse();
});

test('http company deactivate and reactivate by super admin', function () {
    ['company' => $company] = deactOwnedCompany();
    $sa = User::factory()->superAdmin()->create();

    $this->actingAs($sa)
        ->post(route('admin.companies.deactivate', $company), ['remark' => 'HTTP pause'])
        ->assertRedirect();

    expect($company->fresh()->isDeactivated())->toBeTrue();

    $this->actingAs($sa)
        ->post(route('admin.companies.reactivate', $company), ['remark' => 'HTTP resume'])
        ->assertRedirect();

    expect($company->fresh()->isActive())->toBeTrue();
});
