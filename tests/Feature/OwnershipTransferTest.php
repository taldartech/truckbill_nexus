<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Company\OwnershipTransferService;
use App\Domains\Identity\Exceptions\IdentityHardDeleteProhibitedException;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\OwnershipTransfer;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function transferService(): OwnershipTransferService
{
    return app(OwnershipTransferService::class);
}

function transferWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function transferAccess(): CompanyAccessService
{
    return app(CompanyAccessService::class);
}

/**
 * @return array{owner: User, company: Company, branch: Branch}
 */
function transferOwnedCompany(): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = transferWorkspace()->createCompany($owner, 'Transfer Co');
    $branch = $company->branches()->where('name', 'Head Office')->firstOrFail();

    return compact('owner', 'company', 'branch');
}

function internalAdminActor(): User
{
    $sa = User::factory()->superAdmin()->create();
    $adminRole = Role::query()
        ->where('domain', PermissionDomain::Internal->value)
        ->where('slug', 'internal.admin')
        ->firstOrFail();

    return app(RoleAssignmentService::class)->createInternalUser($sa, [
        'name' => 'Internal Admin',
        'email' => 'internal-admin-transfer@example.com',
        'password' => 'password',
    ], $adminRole);
}

function transferLoginAs(User $user, string $password = 'password'): void
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

test('super admin can transfer ownership', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = transferOwnedCompany();
    $newOwner = User::factory()->customer()->create(['password' => Hash::make('new-secret')]);
    transferWorkspace()->addMembership($company, $newOwner, 'Join as member');
    transferWorkspace()->assignBranch(
        CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $newOwner->id)->firstOrFail(),
        $branch,
        'Assign branch',
    );
    $sa = User::factory()->superAdmin()->create();

    $history = transferService()->transfer($sa, $company, $newOwner, 'PO-approved ownership transfer');

    expect($history)->toBeInstanceOf(OwnershipTransfer::class)
        ->and($company->fresh()->owner_user_id)->toBe($newOwner->id)
        ->and(transferAccess()->isCompanyOwner($newOwner->fresh(), $company->fresh()))->toBeTrue()
        ->and(transferAccess()->isCompanyOwner($owner->fresh(), $company->fresh()))->toBeFalse()
        ->and(transferAccess()->canAccessOwnerPanel($newOwner->fresh(), $company->fresh()))->toBeTrue()
        ->and(transferAccess()->canAccessOwnerPanel($owner->fresh(), $company->fresh()))->toBeFalse();
});

test('internal admin can transfer ownership via http', function () {
    ['owner' => $owner, 'company' => $company] = transferOwnedCompany();
    $newOwner = User::factory()->customer()->create();
    transferWorkspace()->addMembership($company, $newOwner, 'Join');
    $admin = internalAdminActor();

    $this->actingAs($admin)
        ->post(route('admin.companies.ownership-transfers.store', $company), [
            'target_user_id' => $newOwner->id,
            'remark' => 'Admin-initiated transfer',
        ])
        ->assertRedirect();

    expect($company->fresh()->owner_user_id)->toBe($newOwner->id)
        ->and($owner->fresh()->trashed())->toBeTrue();
});

test('ordinary internal users customer admin and owner cannot transfer', function () {
    ['owner' => $owner, 'company' => $company] = transferOwnedCompany();
    $newOwner = User::factory()->customer()->create();
    transferWorkspace()->addMembership($company, $newOwner, 'Join');
    $internal = User::factory()->internal()->create();
    $customerAdmin = User::factory()->customer()->create();

    expect(fn () => transferService()->transfer($internal, $company, $newOwner, 'Nope'))
        ->toThrow(CompanyDomainException::class);

    expect(fn () => transferService()->transfer($owner, $company, $newOwner, 'Nope'))
        ->toThrow(CompanyDomainException::class);

    expect(fn () => transferService()->transfer($customerAdmin, $company, $newOwner, 'Nope'))
        ->toThrow(CompanyDomainException::class);

    $this->actingAs($owner)
        ->post(route('admin.companies.ownership-transfers.store', $company), [
            'target_user_id' => $newOwner->id,
            'remark' => 'Owner self-transfer',
        ])
        ->assertRedirect();

    expect($company->fresh()->owner_user_id)->toBe($owner->id);
});

test('internal identity cannot become company owner', function () {
    ['company' => $company] = transferOwnedCompany();
    $sa = User::factory()->superAdmin()->create();
    $internal = User::factory()->internal()->create();

    expect(fn () => transferService()->transfer($sa, $company, $internal, 'Internal as owner'))
        ->toThrow(CompanyDomainException::class);

    expect($company->fresh()->owner_user_id)->not->toBe($internal->id)
        ->and(OwnershipTransfer::query()->count())->toBe(0);
});

test('deactivated soft-deleted and current owner targets are rejected', function () {
    ['owner' => $owner, 'company' => $company] = transferOwnedCompany();
    $sa = User::factory()->superAdmin()->create();

    expect(fn () => transferService()->transfer($sa, $company, $owner, 'Same owner'))
        ->toThrow(CompanyDomainException::class);

    $deactivated = User::factory()->customer()->create(['deactivated_at' => now()]);
    expect(fn () => transferService()->transfer($sa, $company, $deactivated, 'Deactivated target'))
        ->toThrow(CompanyDomainException::class);

    $softDeleted = User::factory()->customer()->create();
    TenantContextHolder::inject(new TenantContext(
        companyId: null,
        branchId: null,
        ownerId: null,
        isInternal: true,
    ));
    $softDeleted->delete();
    TenantContextHolder::clear();

    expect(fn () => transferService()->transfer($sa, $company, $softDeleted, 'Soft-deleted target'))
        ->toThrow(CompanyDomainException::class);

    expect(OwnershipTransfer::query()->count())->toBe(0)
        ->and($company->fresh()->owner_user_id)->toBe($owner->id);
});

test('spoofed tenant identifiers are rejected and unauthorized company stays unchanged', function () {
    ['owner' => $owner, 'company' => $company] = transferOwnedCompany();
    $newOwner = User::factory()->customer()->create();
    transferWorkspace()->addMembership($company, $newOwner, 'Join');
    $sa = User::factory()->superAdmin()->create();

    $this->actingAs($sa)
        ->post(route('admin.companies.ownership-transfers.store', $company), [
            'target_user_id' => $newOwner->id,
            'remark' => 'Should fail closed',
            'company_id' => $company->id,
        ])
        ->assertForbidden();

    $this->actingAs($sa)
        ->post(route('admin.companies.ownership-transfers.store', $company), [
            'target_user_id' => $newOwner->id,
            'remark' => 'Should fail closed',
            'owner_id' => $newOwner->id,
        ])
        ->assertForbidden();

    expect($company->fresh()->owner_user_id)->toBe($owner->id);
});

test('successful transfer enforces one owner removes ordinary membership and preserves peers', function () {
    ['owner' => $previous, 'company' => $company, 'branch' => $branch] = transferOwnedCompany();
    $futureBranch = transferWorkspace()->createBranch($company, 'Warehouse', 'Add warehouse branch');
    $newOwner = User::factory()->customer()->create();
    $peer = User::factory()->customer()->create();

    $newMembership = transferWorkspace()->addMembership($company, $newOwner, 'Join new');
    transferWorkspace()->assignBranch($newMembership, $branch, 'Assign new');
    $peerMembership = transferWorkspace()->addMembership($company, $peer, 'Join peer');
    transferWorkspace()->assignBranch($peerMembership, $branch, 'Assign peer');

    $otherCompany = transferWorkspace()->createCompany($previous, 'Other Co');
    $newOwnerOther = transferWorkspace()->createCompany($newOwner, 'New Owner Other Co');

    $sa = User::factory()->superAdmin()->create();
    $nameBefore = $company->name;
    $deactivatedBefore = $company->deactivated_at;

    transferService()->transfer($sa, $company, $newOwner, 'Transfer with peer preservation');

    $company = $company->fresh();

    expect(Company::query()->whereKey($company->id)->where('owner_user_id', $newOwner->id)->count())->toBe(1)
        ->and($company->owner_user_id)->toBe($newOwner->id)
        ->and($company->name)->toBe($nameBefore)
        ->and($company->deactivated_at)->toBe($deactivatedBefore)
        ->and(transferAccess()->activeMembership($newOwner->fresh(), $company))->toBeNull()
        ->and(transferAccess()->isCompanyOwner($newOwner->fresh(), $company))->toBeTrue()
        ->and(transferAccess()->canAccessBranchPanel($newOwner->fresh(), $company, $branch))->toBeTrue()
        ->and(transferAccess()->canAccessBranchPanel($newOwner->fresh(), $company, $futureBranch))->toBeTrue()
        ->and(transferAccess()->canAccessBranchPanel($previous->fresh(), $company, $branch))->toBeFalse()
        ->and(transferAccess()->canAccessOwnerPanel($previous->fresh(), $company))->toBeFalse()
        ->and(transferAccess()->activeMembership($peer->fresh(), $company))->not->toBeNull()
        ->and(transferAccess()->canAccessBranchPanel($peer->fresh(), $company, $branch))->toBeTrue()
        ->and(transferAccess()->isCompanyOwner($previous->fresh(), $otherCompany->fresh()))->toBeTrue()
        ->and(transferAccess()->isCompanyOwner($newOwner->fresh(), $newOwnerOther->fresh()))->toBeTrue()
        ->and($previous->fresh()->trashed())->toBeFalse()
        ->and($previous->fresh()->isActive())->toBeTrue();
});

test('new owner sessions are invalidated via web_session_version without sessions table dependency', function () {
    ['owner' => $previous, 'company' => $company] = transferOwnedCompany();
    $newOwner = User::factory()->customer()->create(['password' => Hash::make('new-secret')]);
    transferWorkspace()->addMembership($company, $newOwner, 'Join');
    $otherCompany = transferWorkspace()->createCompany($previous, 'Keep Access Co');
    $sa = User::factory()->superAdmin()->create();

    transferLoginAs($newOwner, 'new-secret');
    $newVersionBefore = (int) $newOwner->fresh()->web_session_version;
    expect(session(WebSessionService::SESSION_VERSION_KEY))->toBe($newVersionBefore);
    expect(config('session.driver'))->toBe('array');

    transferService()->transfer($sa, $company, $newOwner, 'Invalidate new owner sessions');

    expect((int) $newOwner->fresh()->web_session_version)->toBe($newVersionBefore + 1);

    // Stale stamp fails closed (driver-agnostic; no sessions table enumeration).
    session([WebSessionService::SESSION_VERSION_KEY => $newVersionBefore]);
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    transferLoginAs($previous, 'owner-secret');
    expect(transferAccess()->canAccessOwnerPanel($previous->fresh(), $company->fresh()))->toBeFalse()
        ->and(fn () => transferAccess()->resolveCompanyContext($previous->fresh(), $company->fresh()))
        ->toThrow(CompanyAccessException::class)
        ->and(transferAccess()->canAccessOwnerPanel($previous->fresh(), $otherCompany->fresh()))->toBeTrue();
});

test('previous owner with zero memberships is soft-deleted; with others remains active', function () {
    $soloOwner = User::factory()->customer()->create();
    $soloCompany = transferWorkspace()->createCompany($soloOwner, 'Solo Co');
    $replacement = User::factory()->customer()->create();
    transferWorkspace()->addMembership($soloCompany, $replacement, 'Join');
    $sa = User::factory()->superAdmin()->create();

    transferService()->transfer($sa, $soloCompany, $replacement, 'Solo owner loses last access');

    expect(User::withTrashed()->findOrFail($soloOwner->id)->trashed())->toBeTrue()
        ->and(transferAccess()->isCompanyOwner($replacement->fresh(), $soloCompany->fresh()))->toBeTrue()
        ->and($soloCompany->fresh()->isActive())->toBeTrue();

    $multiOwner = User::factory()->customer()->create();
    $companyA = transferWorkspace()->createCompany($multiOwner, 'Multi A');
    $companyB = transferWorkspace()->createCompany($multiOwner, 'Multi B');
    $target = User::factory()->customer()->create();
    transferWorkspace()->addMembership($companyA, $target, 'Join A');

    transferService()->transfer($sa, $companyA, $target, 'Multi owner retains other company');

    expect($multiOwner->fresh()->trashed())->toBeFalse()
        ->and($multiOwner->fresh()->isActive())->toBeTrue()
        ->and(transferAccess()->isCompanyOwner($multiOwner->fresh(), $companyB->fresh()))->toBeTrue()
        ->and($companyB->fresh()->isActive())->toBeTrue()
        ->and($companyA->fresh()->isActive())->toBeTrue();
});

test('transfer history and audit commit atomically; failed transfer leaves owner unchanged', function () {
    ['owner' => $owner, 'company' => $company] = transferOwnedCompany();
    $newOwner = User::factory()->customer()->create();
    transferWorkspace()->addMembership($company, $newOwner, 'Join');
    $sa = User::factory()->superAdmin()->create();

    expect(fn () => transferService()->transfer($sa, $company, $newOwner, '   '))
        ->toThrow(InvalidArgumentException::class);

    expect(OwnershipTransfer::query()->count())->toBe(0)
        ->and($company->fresh()->owner_user_id)->toBe($owner->id);

    $history = transferService()->transfer($sa, $company, $newOwner, 'Recorded transfer');

    expect(OwnershipTransfer::query()->count())->toBe(1)
        ->and($history->previous_owner_user_id)->toBe($owner->id)
        ->and($history->new_owner_user_id)->toBe($newOwner->id)
        ->and($history->initiated_by_user_id)->toBe($sa->id)
        ->and($history->remark)->toBe('Recorded transfer')
        ->and(AuditEntry::query()
            ->where('subject_type', OwnershipTransfer::class)
            ->where('subject_id', $history->id)
            ->exists())->toBeTrue()
        ->and(AuditEntry::query()
            ->where('subject_type', Company::class)
            ->where('subject_id', $company->id)
            ->where('remark', 'Recorded transfer')
            ->exists())->toBeTrue();

    expect(fn () => $history->forceFill(['remark' => 'tamper'])->save())
        ->toThrow(CompanyDomainException::class);

    expect(fn () => $history->delete())
        ->toThrow(CompanyDomainException::class);
});

test('concurrent and repeated transfers cannot produce two owners', function () {
    ['owner' => $owner, 'company' => $company] = transferOwnedCompany();
    $first = User::factory()->customer()->create();
    $second = User::factory()->customer()->create();
    transferWorkspace()->addMembership($company, $first, 'Join first');
    transferWorkspace()->addMembership($company, $second, 'Join second');
    $sa = User::factory()->superAdmin()->create();

    transferService()->transfer($sa, $company, $first, 'First transfer');

    expect(Company::query()->whereKey($company->id)->where('owner_user_id', $first->id)->count())->toBe(1);

    // Stale attempt to treat original owner as still current fails closed for same-owner target.
    expect(fn () => transferService()->transfer($sa, $company->fresh(), $first, 'Repeat to current'))
        ->toThrow(CompanyDomainException::class);

    transferService()->transfer($sa, $company->fresh(), $second, 'Second sequential transfer');

    expect(Company::query()->whereKey($company->id)->whereNotNull('owner_user_id')->count())->toBe(1)
        ->and($company->fresh()->owner_user_id)->toBe($second->id)
        ->and(OwnershipTransfer::query()->where('company_id', $company->id)->count())->toBe(2);

    // Schema-level one-owner invariant: single owner_user_id column under row lock.
    DB::transaction(function () use ($company): void {
        $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
        expect(Company::query()->whereKey($locked->id)->count())->toBe(1);
    });
});

test('ownership transfer does not deactivate company or invent hard delete restore', function () {
    ['owner' => $owner, 'company' => $company] = transferOwnedCompany();
    $newOwner = User::factory()->customer()->create();
    transferWorkspace()->addMembership($company, $newOwner, 'Join');
    $sa = User::factory()->superAdmin()->create();

    transferService()->transfer($sa, $company, $newOwner, 'Commercial state preserved');

    expect($company->fresh()->isActive())->toBeTrue()
        ->and($company->fresh()->deactivated_at)->toBeNull()
        ->and(fn () => $company->fresh()->forceDelete())->toThrow(CompanyDomainException::class)
        ->and(fn () => User::withTrashed()->findOrFail($owner->id)->forceDelete())
        ->toThrow(IdentityHardDeleteProhibitedException::class);
});

test('deactivated company owner panel exception remains for active new owner after transfer', function () {
    ['company' => $company] = transferOwnedCompany();
    $newOwner = User::factory()->customer()->create();
    transferWorkspace()->addMembership($company, $newOwner, 'Join');
    $sa = User::factory()->superAdmin()->create();

    transferService()->transfer($sa, $company, $newOwner, 'Then deactivate company');
    transferWorkspace()->deactivateCompany($sa, $company->fresh(), 'Pause after transfer');

    expect(transferAccess()->canAccessOwnerPanel($newOwner->fresh(), $company->fresh()))->toBeTrue()
        ->and(transferAccess()->ownerPanelScope($newOwner->fresh(), $company->fresh()))
        ->toBe(CompanyAccessService::OWNER_PANEL_ADMIN_BILLING_ONLY)
        ->and(transferAccess()->canAccessBranchPanel($newOwner->fresh(), $company->fresh()))->toBeFalse();

    $newOwner->forceFill(['deactivated_at' => now()])->save();

    expect(transferAccess()->canAccessOwnerPanel($newOwner->fresh(), $company->fresh()))->toBeFalse();
});

test('transfer does not create a new identity or alter target credentials', function () {
    ['owner' => $previous, 'company' => $company] = transferOwnedCompany();
    $newOwner = User::factory()->customer()->create([
        'email' => 'keep-email@example.com',
        'password' => Hash::make('unchanged-secret'),
    ]);
    $hashBefore = $newOwner->getAuthPassword();
    $withTrashedBefore = User::withTrashed()->count();
    transferWorkspace()->addMembership($company, $newOwner, 'Join');
    $sa = User::factory()->superAdmin()->create();

    transferService()->transfer($sa, $company, $newOwner, 'No credential overwrite');

    expect(User::withTrashed()->count())->toBe($withTrashedBefore + 1) // + SA; no new Customer
        ->and(User::withTrashed()->findOrFail($previous->id)->trashed())->toBeTrue()
        ->and($newOwner->fresh()->email)->toBe('keep-email@example.com')
        ->and($newOwner->fresh()->getAuthPassword())->toBe($hashBefore)
        ->and($newOwner->fresh()->identity_type->value)->toBe('customer');
});
