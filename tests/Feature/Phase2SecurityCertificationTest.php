<?php

/**
 * P2-WS-9 dedicated Phase 2 exit security certification suite.
 *
 * Complements (does not replace) TenantIsolationTest, SessionPolicyTest,
 * InvitationLifecycleTest, OwnershipTransferTest, DeactivationLifecycleTest,
 * PermissionMasterTest, FileSecurityTest, WorkspacePanelTest, and OwnerPanelManagementTest.
 */

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\OwnershipTransferService;
use App\Domains\Company\WorkspaceNavigationService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Branch;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Files\PrivateFileService;
use App\Support\Files\Scanning\MalwareScanner;
use App\Support\Files\Scanning\PassThroughMalwareScanner;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function certWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function certLogin(User $user, string $password = 'owner-secret'): TestResponse
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
 * @return array{ownerA: User, companyA: Company, branchA: Branch, ownerB: User, companyB: Company, branchB: Branch}
 */
function certTwoOwnedCompanies(): array
{
    $ownerA = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $companyA = certWorkspace()->createCompany($ownerA, 'Cert Alpha');
    $branchA = $companyA->branches()->where('name', 'Head Office')->firstOrFail();

    $ownerB = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $companyB = certWorkspace()->createCompany($ownerB, 'Cert Beta');
    $branchB = $companyB->branches()->where('name', 'Head Office')->firstOrFail();

    return compact('ownerA', 'companyA', 'branchA', 'ownerB', 'companyB', 'branchB');
}

test('owner cannot mutate or audit another company via owner panel routes', function () {
    ['ownerA' => $ownerA, 'companyA' => $companyA, 'ownerB' => $ownerB, 'companyB' => $companyB, 'branchB' => $branchB] = certTwoOwnedCompanies();

    $memberB = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membershipB = certWorkspace()->addMembership($companyB, $memberB, 'Join B');
    certWorkspace()->assignBranch($membershipB, $branchB, 'Assign B');

    certLogin($ownerA);

    $this->post(route('owner.settings.update', ['company' => $companyB]), [
        'name' => 'Hijacked Name',
        'remark' => 'Cross-company write',
    ])->assertForbidden();
    expect($companyB->fresh()->name)->toBe('Cert Beta');

    $this->post(route('owner.users.invite'), [
        'email' => 'cross.invite@example.com',
    ])->assertForbidden();

    $this->post(route('owner.users.invite-for-branch'), [
        'email' => 'cross.branch.invite@example.com',
    ])->assertForbidden();

    $this->get(route('owner.audit'))->assertForbidden();

    $this->post(route('owner.users.deactivate'), [
        'remark' => 'Cross deactivate',
    ])->assertForbidden();
    expect($membershipB->fresh()->isActive())->toBeTrue();

    $this->get(route('owner.settings'))->assertOk();
    expect($ownerB->id)->not->toBe($ownerA->id);
});

test('owner panel rejects client-supplied tenant identifiers on management routes', function () {
    ['ownerA' => $owner, 'companyA' => $company] = certTwoOwnedCompanies();

    certLogin($owner);

    $this->get(route('owner.settings').'?company_id='.$company->id)
        ->assertForbidden();

    $this->post(route('owner.settings.update', ['company' => $company]), [
        'name' => 'Still Alpha',
        'remark' => 'Valid rename blocked by tenant key',
        'company_id' => $company->id,
    ])->assertForbidden();

    $this->post(route('owner.users.invite'), [
        'email' => 'tenant.key@example.com',
        'branch_id' => '00000000-0000-0000-0000-000000000001',
    ])->assertForbidden();

    $this->get(route('owner.audit').'?owner_id='.$owner->id)
        ->assertForbidden();

    $this->withHeaders(['X-Company-Id' => $company->id])
        ->get(route('owner.users'))
        ->assertForbidden();
});

test('stale mru owned company cannot authorize owner panel after ownership transfer', function () {
    $previousOwner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $retained = certWorkspace()->createCompany($previousOwner, 'Cert Retained');
    $transferred = certWorkspace()->createCompany($previousOwner, 'Cert Transferred');
    $newOwner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);

    app(WorkspaceNavigationService::class)->rememberOwnedCompany($previousOwner, $transferred);
    expect($previousOwner->fresh()->mru_owned_company_id)->toBe($transferred->id);

    $sa = User::factory()->superAdmin()->create();
    app(OwnershipTransferService::class)->transfer(
        $sa,
        $transferred,
        $newOwner,
        'Certification transfer',
    );

    expect(app(WorkspaceNavigationService::class)->resolveMruOwnedCompany($previousOwner->fresh()))->toBeNull()
        ->and($previousOwner->fresh()->trashed())->toBeFalse();

    certLogin($previousOwner);

    // PO-OWNER-GLOBAL-MANAGEMENT: owners land on the global Owner dashboard.
    $this->get(route('workspace.landing'))
        ->assertRedirect(route('owner.home'));

    $this->get(route('owner.home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/home')
            ->where('totals.companies', 1)
            ->where('companies.0.id', $retained->id));

    $this->get(route('owner.home'))->assertForbidden();
    $this->post(route('workspace.companies.enter', ['company' => $transferred]))->assertForbidden();
});

test('customer branch role revoke takes effect immediately for permission checks', function () {
    (new RbacSeeder)->run();

    ['ownerA' => $owner, 'companyA' => $company, 'branchA' => $branch] = certTwoOwnedCompanies();
    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = certWorkspace()->addMembership($company, $member, 'Join');
    $assignment = certWorkspace()->assignBranch($membership, $branch, 'Assign');

    $role = Role::query()
        ->where('domain', PermissionDomain::Customer)
        ->where('slug', 'customer.operator')
        ->firstOrFail();

    certLogin($owner);

    $this->post(route('owner.users.assign-role'), [
        'role_id' => $role->id,
        'remark' => 'Grant operator',
    ])->assertRedirect();

    $authorizer = app(PermissionAuthorizer::class);
    expect($authorizer->allowsCustomer($member, PermissionCodes::PLATFORM_CREATE, $company, $branch))->toBeTrue();

    $roleAssignment = BranchRoleAssignment::query()
        ->where('company_membership_id', $membership->id)
        ->where('branch_id', $branch->id)
        ->whereNull('deactivated_at')
        ->firstOrFail();

    $this->post(route('owner.users.roles.revoke'), [
        'remark' => 'Revoke operator',
    ])->assertRedirect();

    expect($roleAssignment->fresh()->isActive())->toBeFalse()
        ->and($authorizer->allowsCustomer($member->fresh(), PermissionCodes::PLATFORM_CREATE, $company, $branch))->toBeFalse()
        ->and(app(CompanyAccessService::class)->canAccessBranchPanel($member, $company, $branch))->toBeTrue();
});

test('multi-company file access is limited to active company context', function () {
    Storage::fake('private_files');
    config([
        'files.disk' => 'private_files',
        'files.scanner' => 'pass',
        'files.max_bytes' => 2 * 1024 * 1024,
    ]);
    app()->bind(MalwareScanner::class, fn () => new PassThroughMalwareScanner);

    ['ownerA' => $owner, 'companyA' => $companyA, 'branchA' => $branchA, 'companyB' => $companyB, 'branchB' => $branchB] = certTwoOwnedCompanies();

    // Make ownerA also a member of companyB so multi-company identity is realistic.
    $membershipB = certWorkspace()->addMembership($companyB, $owner, 'Cross membership');
    certWorkspace()->assignBranch($membershipB, $branchB, 'Assign B');

    $this->actingAs($owner);

    TenantContextHolder::inject(new TenantContext(
        companyId: $companyA->id,
        branchId: $branchA->id,
        ownerId: (string) $companyA->owner_user_id,
    ));

    $fileA = app(PrivateFileService::class)->store(
        UploadedFile::fake()->create('alpha.pdf', 20, 'application/pdf'),
    );

    TenantContextHolder::inject(new TenantContext(
        companyId: $companyB->id,
        branchId: $branchB->id,
        ownerId: (string) $companyB->owner_user_id,
    ));

    expect(fn () => app(PrivateFileService::class)->download($fileA))
        ->toThrow(HttpException::class);

    TenantContextHolder::inject(new TenantContext(
        companyId: $companyA->id,
        branchId: $branchA->id,
        ownerId: (string) $companyA->owner_user_id,
    ));

    expect(app(PrivateFileService::class)->download($fileA))->not->toBeNull();
});

test('internal context cannot download customer files even with matching company ids', function () {
    Storage::fake('private_files');
    config([
        'files.disk' => 'private_files',
        'files.scanner' => 'pass',
        'files.max_bytes' => 2 * 1024 * 1024,
    ]);
    app()->bind(MalwareScanner::class, fn () => new PassThroughMalwareScanner);

    ['companyA' => $company, 'branchA' => $branch] = certTwoOwnedCompanies();
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer);

    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
    ));

    $file = app(PrivateFileService::class)->store(
        UploadedFile::fake()->create('customer.pdf', 20, 'application/pdf'),
    );

    $internal = User::factory()->internal()->create();
    $this->actingAs($internal);

    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: $branch->id,
        ownerId: (string) $company->owner_user_id,
        isInternal: true,
    ));

    expect(fn () => app(PrivateFileService::class)->download($file))
        ->toThrow(HttpException::class);
});

test('deactivated company blocks branch panel and owner write routes while audit remains readable', function () {
    ['ownerA' => $owner, 'companyA' => $company, 'branchA' => $branch] = certTwoOwnedCompanies();
    $sa = User::factory()->superAdmin()->create();
    certWorkspace()->deactivateCompany($sa, $company, 'Cert deactivate');

    certLogin($owner);

    $this->get(route('owner.home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ownerPanelScope', CompanyAccessService::OWNER_PANEL_ADMIN_BILLING_ONLY));

    $this->post(route('owner.settings.update', ['company' => $company]), [
        'name' => 'Should Fail',
        'remark' => 'Write while deactivated',
    ])->assertForbidden();

    $this->post(route('owner.users.invite'), [
        'email' => 'deactivated.invite@example.com',
    ])->assertForbidden();

    $this->get(route('branch.dashboard', [
        'company' => $company,
        'branch' => $branch,
    ]))->assertForbidden();

    $this->get(route('owner.audit'))->assertOk();
    $this->get(route('owner.subscription'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/overview')
            ->where('canManage', false)
            ->has('entitlement_note'));
});

test('member cannot escalate to owner panel or assign customer roles', function () {
    (new RbacSeeder)->run();

    ['ownerA' => $owner, 'companyA' => $company, 'branchA' => $branch] = certTwoOwnedCompanies();
    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = certWorkspace()->addMembership($company, $member, 'Join');
    $branchAssignment = certWorkspace()->assignBranch($membership, $branch, 'Assign');

    certLogin($member, 'member-secret');

    foreach ([
        route('owner.home'),
        route('owner.users'),
        route('owner.settings'),
        route('owner.audit'),
        route('owner.subscription'),
    ] as $url) {
        $this->get($url)->assertForbidden();
    }

    $this->post(route('owner.users.invite'), [
        'email' => 'escalation@example.com',
    ])->assertForbidden();

    $role = Role::query()
        ->where('domain', PermissionDomain::Customer)
        ->where('slug', 'customer.operator')
        ->firstOrFail();

    expect(fn () => app(RoleAssignmentService::class)->assignCustomerBranchRole(
        $member,
        $membership,
        $branch,
        $branchAssignment,
        $role,
        'Member escalation',
    ))->toThrow(RbacAuthorizationException::class);

    expect($company->isOwnedBy($owner))->toBeTrue()
        ->and($company->isOwnedBy($member))->toBeFalse();
});

test('public registration remains disabled for phase 2 web-only auth', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Public',
        'email' => 'public@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});
