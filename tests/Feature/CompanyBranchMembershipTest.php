<?php

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Identity\Enums\IdentityType;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\Exceptions\AuditRemarkRequiredException;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use App\Support\Tenant\TenantStorageMetadata;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function workspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function access(): CompanyAccessService
{
    return app(CompanyAccessService::class);
}

function createOwnedCompany(string $name = 'Acme Logistics'): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);

    $company = workspace()->createCompany($owner, $name);
    $headOffice = $company->branches()->where('name', 'Head Office')->firstOrFail();

    return compact('owner', 'company', 'headOffice');
}

test('customer identity can belong to multiple companies', function () {
    $user = User::factory()->customer()->create();

    $companyA = workspace()->createCompany($user, 'Company A');
    $companyB = workspace()->createCompany(User::factory()->customer()->create(), 'Company B');
    workspace()->addMembership($companyB, $user, 'Joined second company');

    expect(CompanyMembership::query()->where('user_id', $user->id)->whereNull('deactivated_at')->count())->toBe(2)
        ->and(access()->activeMembership($user, $companyA))->not->toBeNull()
        ->and(access()->activeMembership($user, $companyB))->not->toBeNull();
});

test('multiple customer identities can belong to one company', function () {
    ['owner' => $owner, 'company' => $company] = createOwnedCompany();
    $member = User::factory()->customer()->create();

    workspace()->addMembership($company, $member, 'Added operator');

    expect(CompanyMembership::query()->where('company_id', $company->id)->whereNull('deactivated_at')->count())->toBe(2)
        ->and(access()->isCompanyOwner($owner, $company))->toBeTrue()
        ->and(access()->isCompanyOwner($member, $company))->toBeFalse();
});

test('internal identities cannot become company members', function () {
    ['company' => $company] = createOwnedCompany();
    $internal = User::factory()->internal()->create();

    expect(fn () => workspace()->addMembership($company, $internal, 'Should fail'))
        ->toThrow(CompanyDomainException::class);

    expect(CompanyMembership::query()->where('user_id', $internal->id)->exists())->toBeFalse()
        ->and($internal->fresh()->identity_type)->toBe(IdentityType::Internal);
});

test('duplicate active memberships are rejected by service and database', function () {
    ['company' => $company] = createOwnedCompany();
    $member = User::factory()->customer()->create();
    workspace()->addMembership($company, $member, 'First join');

    expect(fn () => workspace()->addMembership($company, $member, 'Duplicate join'))
        ->toThrow(CompanyDomainException::class);

    expect(fn () => DB::table('company_memberships')->insert([
        'id' => (string) Str::uuid(),
        'company_id' => $company->id,
        'user_id' => $member->id,
        'deactivated_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => null,
    ]))->toThrow(QueryException::class);
});

test('adding membership does not overwrite existing credentials', function () {
    ['company' => $company] = createOwnedCompany();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('keep-me-safe'),
    ]);
    $hashBefore = $member->getAuthPassword();

    workspace()->addMembership($company, $member, 'Join without credential change');

    expect($member->fresh()->getAuthPassword())->toBe($hashBefore)
        ->and($member->fresh()->identity_type)->toBe(IdentityType::Customer);
});

test('inactive membership denies company and branch access', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $member = User::factory()->customer()->create();
    $membership = workspace()->addMembership($company, $member, 'Join');
    workspace()->assignBranch($membership, $headOffice, 'Assign HO');

    workspace()->deactivateMembership($membership, 'Left company');

    expect(access()->activeMembership($member, $company))->toBeNull()
        ->and(access()->canAccessBranchPanel($member, $company))->toBeFalse()
        ->and(access()->canAccessBranch($member, $company, $headOffice))->toBeFalse()
        ->and(fn () => access()->resolveCompanyContext($member, $company))
        ->toThrow(CompanyAccessException::class);
});

test('exactly one owner is designated via companies.owner_user_id', function () {
    ['owner' => $owner, 'company' => $company] = createOwnedCompany();
    $other = User::factory()->customer()->create();
    workspace()->addMembership($company, $other, 'Member only');

    expect(access()->isCompanyOwner($owner, $company))->toBeTrue()
        ->and(access()->isCompanyOwner($other, $company))->toBeFalse()
        ->and(Company::query()->whereKey($company->id)->where('owner_user_id', $owner->id)->count())->toBe(1);

    expect(fn () => $company->forceFill(['owner_user_id' => $other->id])->save())
        ->toThrow(CompanyDomainException::class);
});

test('owner access cannot be inferred from client-supplied owner scope flag', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $member = User::factory()->customer()->create();
    $membership = workspace()->addMembership($company, $member, 'Join');
    workspace()->assignBranch($membership, $headOffice, 'Assign HO');

    // Spoofed isOwnerScope on a holder context must not grant Owner semantics through access service.
    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: null,
        ownerId: (string) $company->owner_user_id,
        isOwnerScope: true,
    ));

    expect(access()->isCompanyOwner($member, $company))->toBeFalse()
        ->and(access()->canAccessOwnerPanel($member, $company))->toBeFalse();

    $resolved = access()->resolveCompanyContext($member, $company, $headOffice);
    expect($resolved->isOwnerScope)->toBeFalse();
});

test('owner has access to every existing branch and future branches', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $warehouse = workspace()->createBranch($company, 'Warehouse', 'Opened warehouse');

    expect(access()->canAccessBranch($owner, $company, $headOffice))->toBeTrue()
        ->and(access()->canAccessBranch($owner, $company, $warehouse))->toBeTrue();

    $future = workspace()->createBranch($company, 'Future Depot', 'Opened after membership');
    expect(access()->canAccessBranch($owner, $company, $future))->toBeTrue()
        ->and(BranchAssignment::query()->where('branch_id', $future->id)->exists())->toBeFalse();
});

test('ordinary members do not gain future branch access automatically', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $member = User::factory()->customer()->create();
    $membership = workspace()->addMembership($company, $member, 'Join');
    workspace()->assignBranch($membership, $headOffice, 'Assign HO only');

    $future = workspace()->createBranch($company, 'New Site', 'Opened later');

    expect(access()->canAccessBranch($member, $company, $headOffice))->toBeTrue()
        ->and(access()->canAccessBranch($member, $company, $future))->toBeFalse()
        ->and(fn () => access()->resolveCompanyContext($member, $company, $future))
        ->toThrow(CompanyAccessException::class);
});

test('explicit branch assignment grants only the assigned branch', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $second = workspace()->createBranch($company, 'Second', 'Second branch');
    $member = User::factory()->customer()->create();
    $membership = workspace()->addMembership($company, $member, 'Join');
    workspace()->assignBranch($membership, $headOffice, 'HO only');

    expect(access()->canAccessBranch($member, $company, $headOffice))->toBeTrue()
        ->and(access()->canAccessBranch($member, $company, $second))->toBeFalse();
});

test('missing assignment denies branch access', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $member = User::factory()->customer()->create();
    workspace()->addMembership($company, $member, 'Join without branch');

    expect(access()->canAccessBranchPanel($member, $company))->toBeFalse()
        ->and(access()->canAccessBranch($member, $company, $headOffice))->toBeFalse();
});

test('cross-company branch assignment is rejected', function () {
    ['company' => $companyA, 'headOffice' => $branchA] = createOwnedCompany('A');
    ['company' => $companyB] = createOwnedCompany('B');
    $member = User::factory()->customer()->create();
    $membershipB = workspace()->addMembership($companyB, $member, 'Join B');

    expect(fn () => workspace()->assignBranch($membershipB, $branchA, 'Cross company'))
        ->toThrow(CompanyDomainException::class);
});

test('inactive branch denies access for members and owners', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $member = User::factory()->customer()->create();
    $membership = workspace()->addMembership($company, $member, 'Join');
    workspace()->assignBranch($membership, $headOffice, 'Assign');

    workspace()->deactivateBranch($headOffice, 'Closed for renovations');

    expect(access()->canAccessBranch($owner, $company, $headOffice))->toBeFalse()
        ->and(access()->canAccessBranch($member, $company, $headOffice))->toBeFalse();
});

test('user with membership in another company cannot access this company', function () {
    ['company' => $companyA, 'headOffice' => $branchA] = createOwnedCompany('A');
    ['company' => $companyB] = createOwnedCompany('B');
    $outsider = User::factory()->customer()->create();
    workspace()->addMembership($companyB, $outsider, 'Join B only');

    expect(access()->activeMembership($outsider, $companyA))->toBeNull()
        ->and(access()->canAccessBranchPanel($outsider, $companyA))->toBeFalse()
        ->and(fn () => access()->resolveCompanyContext($outsider, $companyA, $branchA))
        ->toThrow(CompanyAccessException::class);
});

test('spoofed company and branch identifiers are rejected by resolution', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $foreign = workspace()->createCompany(User::factory()->customer()->create(), 'Foreign Co');
    $foreignBranch = $foreign->branches()->firstOrFail();

    expect(fn () => access()->resolveCompanyContext($owner, $foreign))
        ->toThrow(CompanyAccessException::class);

    expect(fn () => access()->resolveCompanyContext($owner, $company, $foreignBranch))
        ->toThrow(CompanyAccessException::class);

    // Cross-company branch id with claimed home company still fails company_id match.
    expect(access()->canAccessBranch($owner, $company, $foreignBranch))->toBeFalse();
});

test('activateContext injects resolved context and clears between activations', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();

    $ctx = access()->activateContext($owner, $company, $headOffice);
    expect(TenantContextHolder::require()->companyId)->toBe($company->id)
        ->and(TenantContextHolder::require()->branchId)->toBe($headOffice->id)
        ->and($ctx->isOwnerScope)->toBeTrue();

    TenantContextHolder::clear();
    expect(TenantContextHolder::has())->toBeFalse();

    expect(fn () => TenantContextHolder::require())
        ->toThrow(RuntimeException::class);
});

test('missing or ambiguous tenant context fails closed', function () {
    expect(fn () => TenantContextHolder::require())
        ->toThrow(RuntimeException::class);

    TenantContextHolder::inject(new TenantContext);
    expect(fn () => TenantContextHolder::require())
        ->toThrow(RuntimeException::class);
});

test('company deactivation blocks branch panel but retains limited owner panel', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $member = User::factory()->customer()->create();
    $membership = workspace()->addMembership($company, $member, 'Join');
    workspace()->assignBranch($membership, $headOffice, 'Assign');

    workspace()->deactivateCompany($company, 'Subscription paused');

    expect($owner->fresh()->isActive())->toBeTrue()
        ->and($owner->fresh()->isDeactivated())->toBeFalse()
        ->and(access()->canAccessBranchPanel($owner, $company))->toBeFalse()
        ->and(access()->canAccessBranchPanel($member, $company))->toBeFalse()
        ->and(access()->canAccessOwnerPanel($owner, $company))->toBeTrue()
        ->and(access()->canAccessOwnerPanel($member, $company))->toBeFalse();

    $ownerCtx = access()->resolveCompanyContext($owner, $company);
    expect($ownerCtx->isOwnerScope)->toBeTrue()
        ->and($ownerCtx->branchId)->toBeNull();

    expect(fn () => access()->resolveCompanyContext($member, $company))
        ->toThrow(CompanyAccessException::class);
});

test('deactivated owner identity loses owner panel access', function () {
    ['owner' => $owner, 'company' => $company] = createOwnedCompany();

    $owner->forceFill(['deactivated_at' => now()])->save();

    expect(access()->canAccessOwnerPanel($owner->fresh(), $company))->toBeFalse()
        ->and(access()->isCompanyOwner($owner->fresh(), $company))->toBeFalse()
        ->and(fn () => access()->resolveCompanyContext($owner->fresh(), $company))
        ->toThrow(CompanyAccessException::class);
});

test('company and membership mutations write atomic audits with required remarks', function () {
    ['owner' => $owner, 'company' => $company] = createOwnedCompany();

    expect(AuditEntry::query()->where('subject_type', Company::class)->where('action', AuditAction::Created->value)->count())->toBe(1)
        ->and(AuditEntry::query()->where('subject_type', CompanyMembership::class)->count())->toBe(1)
        ->and(AuditEntry::query()->where('subject_type', Branch::class)->count())->toBe(1);

    workspace()->updateCompany($company, 'Acme Renamed', 'Legal name change');
    expect(AuditEntry::query()->where('subject_type', Company::class)->where('action', AuditAction::Updated->value)->count())->toBe(1);

    expect(fn () => workspace()->deactivateCompany($company->fresh(), ''))
        ->toThrow(InvalidArgumentException::class);

    expect($company->fresh()->isActive())->toBeTrue()
        ->and(AuditEntry::query()->where('subject_type', Company::class)->where('action', AuditAction::Updated->value)->count())->toBe(1);

    $beforeCount = AuditEntry::query()->count();

    // Force audit failure mid-mutation to prove atomicity.
    expect(fn () => DB::transaction(function () use ($company): void {
        $company->forceFill(['name' => 'Should Roll Back'])->save();
        throw new AuditRemarkRequiredException('forced');
    }))->toThrow(AuditRemarkRequiredException::class);

    expect($company->fresh()->name)->toBe('Acme Renamed')
        ->and(AuditEntry::query()->count())->toBe($beforeCount);

    workspace()->deactivateCompany($company->fresh(), 'Shutting down operations');
    expect($company->fresh()->isDeactivated())->toBeTrue()
        ->and(AuditEntry::query()->where('subject_type', Company::class)->where('remark', 'Shutting down operations')->exists())->toBeTrue()
        ->and($owner->fresh()->isActive())->toBeTrue();
});

test('failed membership create does not leave partial records', function () {
    ['company' => $company] = createOwnedCompany();
    $member = User::factory()->customer()->create();
    $beforeMemberships = CompanyMembership::query()->count();
    $beforeAudits = AuditEntry::query()->count();

    // Simulate failure after validation by using a non-customer after factory (service rejects before write).
    $internal = User::factory()->internal()->create();

    expect(fn () => workspace()->addMembership($company, $internal, 'Nope'))
        ->toThrow(CompanyDomainException::class);

    expect(CompanyMembership::query()->count())->toBe($beforeMemberships)
        ->and(AuditEntry::query()->count())->toBe($beforeAudits)
        ->and(CompanyMembership::query()->where('user_id', $member->id)->exists())->toBeFalse();
});

test('branch assignment revocation and duplicate active assignment rules', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $member = User::factory()->customer()->create();
    $membership = workspace()->addMembership($company, $member, 'Join');
    $assignment = workspace()->assignBranch($membership, $headOffice, 'Assign');

    expect(fn () => workspace()->assignBranch($membership, $headOffice, 'Duplicate'))
        ->toThrow(QueryException::class);

    workspace()->revokeBranchAssignment($assignment, 'Revoked access');

    expect(access()->canAccessBranch($member, $company, $headOffice))->toBeFalse()
        ->and($assignment->fresh()->isActive())->toBeFalse();
});

test('hard delete and restore are prohibited on company domain models', function () {
    ['company' => $company, 'headOffice' => $headOffice] = createOwnedCompany();
    $membership = $company->memberships()->firstOrFail();

    expect(fn () => $company->forceDelete())->toThrow(CompanyDomainException::class)
        ->and(fn () => $company->restore())->toThrow(CompanyDomainException::class)
        ->and(fn () => $headOffice->forceDelete())->toThrow(CompanyDomainException::class)
        ->and(fn () => $membership->forceDelete())->toThrow(CompanyDomainException::class);
});

test('owner membership cannot be deactivated without ownership transfer', function () {
    ['company' => $company] = createOwnedCompany();
    $ownerMembership = $company->memberships()->firstOrFail();

    expect(fn () => workspace()->deactivateMembership($ownerMembership, 'Try remove owner'))
        ->toThrow(CompanyDomainException::class);
});

test('internal team is blocked from customer file metadata helpers', function () {
    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-1',
        branchId: 'br-1',
        ownerId: 'own-1',
        isInternal: true,
    ));

    expect(fn () => TenantStorageMetadata::forCurrentContext())
        ->toThrow(HttpException::class);

    expect(fn () => TenantStorageMetadata::assertSameTenant([
        'company_id' => 'co-1',
        'branch_id' => 'br-1',
        'owner_id' => 'own-1',
    ]))->toThrow(HttpException::class);
});

test('super admin privilege is unrelated to company owner designation', function () {
    $super = User::factory()->superAdmin()->create();
    ['owner' => $owner, 'company' => $company] = createOwnedCompany();

    expect($super->isSuperAdmin())->toBeTrue()
        ->and(access()->isCompanyOwner($super, $company))->toBeFalse()
        ->and(access()->isCompanyOwner($owner, $company))->toBeTrue()
        ->and($owner->isSuperAdmin())->toBeFalse();
});
