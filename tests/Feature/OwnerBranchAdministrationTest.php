<?php

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Company\OwnerBranchAdministrationService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function branchAdminWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

/**
 * @return array{owner: User, company: Company, headOffice: Branch}
 */
function createBranchAdminWorkspace(string $name = 'Branch Admin Co'): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = branchAdminWorkspace()->createCompany($owner, $name);
    $headOffice = $company->branches()->where('name', 'Head Office')->firstOrFail();

    return compact('owner', 'company', 'headOffice');
}

function loginBranchAdminCustomer(User $user, string $password = 'owner-secret'): TestResponse
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

test('owner can list create edit and enter branch management screens', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createBranchAdminWorkspace();

    loginBranchAdminCustomer($owner);
    enterManagementCompany($company)->assertRedirect();

    $this->get(route('owner.branches'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/branches/index')
            ->where('canManage', true)
            ->has('capacity')
            ->has('branches.data', 1));

    $this->get(route('owner.branches.create'))
        ->assertRedirect(route('owner.companies.branches.create', ['company' => $company]));

    $this->get(route('owner.companies.branches.create', ['company' => $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/branches/create')
            ->where('canCreate', true)
            ->where('company.id', $company->id));

    $this->post(route('owner.companies.branches.store', ['company' => $company]), [
        'name' => 'Warehouse West',
        'remark' => 'Opened west warehouse',
        'is_head_office' => false,
        'gst_mode' => 'inherit_company',
    ])->assertRedirect(route('owner.branches.all'));

    $warehouse = Branch::query()
        ->where('company_id', $company->id)
        ->where('name', 'Warehouse West')
        ->firstOrFail();

    expect($warehouse->isActive())->toBeTrue();

    $this->get(route('owner.branches.show', ['branch' => $warehouse]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/branches/show')
            ->where('branch.name', 'Warehouse West')
            ->where('canManage', true)
            ->where('canDeactivate', true));

    $this->post(route('owner.branches.update', ['branch' => $warehouse]), [
        'name' => 'Warehouse West Renamed',
        'remark' => 'Rename for clarity',
        'is_head_office' => false,
        'gst_mode' => 'inherit_company',
        'contact_number' => '9123456780',
        'address' => 'West yard',
    ])->assertRedirect(route('owner.branches.show', ['branch' => $warehouse]));

    expect($warehouse->fresh()->name)->toBe('Warehouse West Renamed');

    expect(AuditEntry::query()
        ->where('company_id', $company->id)
        ->where('subject_type', Branch::class)
        ->where('subject_id', $warehouse->id)
        ->exists())->toBeTrue();

    expect($headOffice->fresh()->isActive())->toBeTrue();
});

test('branch create may assign existing members and roles without granting other branches', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createBranchAdminWorkspace();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = branchAdminWorkspace()->addMembership($company, $member, 'Join operator');
    branchAdminWorkspace()->assignBranch($membership, $headOffice, 'HO access');

    $role = Role::factory()->create([
        'domain' => PermissionDomain::Customer,
        'name' => 'Depot Clerk',
        'slug' => 'depot-clerk-'.uniqid(),
    ]);

    loginBranchAdminCustomer($owner);
    enterManagementCompany($company)->assertRedirect();

    $this->post(route('owner.companies.branches.store', ['company' => $company]), [
        'name' => 'Depot North',
        'remark' => 'New depot with clerk',
        'is_head_office' => false,
        'gst_mode' => 'inherit_company',
        'assignments' => [
            [
                'user_id' => $member->id,
                'role_id' => $role->id,
            ],
        ],
    ])->assertRedirect(route('owner.branches.all'));

    $depot = Branch::query()
        ->where('company_id', $company->id)
        ->where('name', 'Depot North')
        ->firstOrFail();

    expect(BranchAssignment::query()
        ->where('company_membership_id', $membership->id)
        ->where('branch_id', $depot->id)
        ->whereNull('deactivated_at')
        ->exists())->toBeTrue();

    expect(BranchRoleAssignment::query()
        ->where('company_membership_id', $membership->id)
        ->where('branch_id', $depot->id)
        ->where('role_id', $role->id)
        ->whereNull('deactivated_at')
        ->exists())->toBeTrue();

    $access = app(CompanyAccessService::class);
    expect($access->canAccessBranch($member, $company, $headOffice))->toBeTrue()
        ->and($access->canAccessBranch($member, $company, $depot))->toBeTrue();

    // Future branch must not auto-grant non-owner members.
    $future = branchAdminWorkspace()->createBranch($company, 'Future Site', 'Opened later');
    expect($access->canAccessBranch($member, $company, $future))->toBeFalse()
        ->and($access->canAccessBranch($owner, $company, $future))->toBeTrue();
});

test('branch capacity is enforced for owner create', function () {
    ['owner' => $owner, 'company' => $company] = createBranchAdminWorkspace('Capacity Co');

    loginBranchAdminCustomer($owner);
    enterManagementCompany($company)->assertRedirect();

    $this->post(route('owner.companies.branches.store', ['company' => $company]), [
        'name' => 'Second Branch',
        'remark' => 'Uses remaining included slot',
        'is_head_office' => false,
        'gst_mode' => 'inherit_company',
    ])->assertRedirect(route('owner.branches.all'));

    $this->from(route('owner.companies.branches.create', ['company' => $company]))
        ->post(route('owner.companies.branches.store', ['company' => $company]), [
            'name' => 'Third Branch',
            'remark' => 'Should exceed default included capacity',
            'is_head_office' => false,
            'gst_mode' => 'inherit_company',
        ])
        ->assertSessionHasErrors('name');

    expect(Branch::query()
        ->where('company_id', $company->id)
        ->where('name', 'Third Branch')
        ->exists())->toBeFalse();
});

test('owner can deactivate non-last branch and cannot deactivate last active branch', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createBranchAdminWorkspace();
    loginBranchAdminCustomer($owner);
    enterManagementCompany($company)->assertRedirect();

    $this->post(route('owner.companies.branches.store', ['company' => $company]), [
        'name' => 'Closeable',
        'remark' => 'Will deactivate',
        'is_head_office' => false,
        'gst_mode' => 'inherit_company',
    ])->assertRedirect(route('owner.branches.all'));

    $closeable = Branch::query()
        ->where('company_id', $company->id)
        ->where('name', 'Closeable')
        ->firstOrFail();

    $this->post(route('owner.branches.deactivate', ['branch' => $closeable]), [
        'remark' => 'Closing depot',
    ])->assertRedirect(route('owner.branches'));

    expect($closeable->fresh()->isActive())->toBeFalse();

    $this->from(route('owner.branches.show', ['branch' => $headOffice]))
        ->post(route('owner.branches.deactivate', ['branch' => $headOffice]), [
            'remark' => 'Attempt last branch',
        ])
        ->assertSessionHasErrors('remark');

    expect($headOffice->fresh()->isActive())->toBeTrue();
});

test('non-owner and cross-company branch mutations are denied', function () {
    ['owner' => $owner, 'company' => $companyA] = createBranchAdminWorkspace('Company A');
    $other = createBranchAdminWorkspace('Company B');
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = branchAdminWorkspace()->addMembership($companyA, $member, 'Join');
    $headA = $companyA->branches()->whereNull('deactivated_at')->firstOrFail();
    branchAdminWorkspace()->assignBranch($membership, $headA, 'Assign');

    loginBranchAdminCustomer($member, 'member-secret');
    $this->get(route('owner.branches'))->assertForbidden();
    $this->post(route('owner.branches.store'), [
        'name' => 'Hijack',
        'remark' => 'No',
    ])->assertForbidden();

    loginBranchAdminCustomer($owner);
    enterManagementCompany($companyA)->assertRedirect();

    $this->get(route('owner.branches.show', ['branch' => $other['headOffice']]))->assertNotFound();

    $this->post(route('owner.branches.update', ['branch' => $other['headOffice']]), [
        'name' => 'Cross rename',
        'remark' => 'Cross company',
    ])->assertNotFound();
});

test('owner branch administration service rejects empty remarks and deactivated companies', function () {
    ['owner' => $owner, 'company' => $company] = createBranchAdminWorkspace();
    $service = app(OwnerBranchAdministrationService::class);

    expect(fn () => $service->createBranch($owner, $company, 'X', ''))
        ->toThrow(InvalidArgumentException::class);

    $sa = User::factory()->superAdmin()->create();
    branchAdminWorkspace()->deactivateCompany($sa, $company, 'Pause');

    expect(fn () => $service->createBranch($owner, $company->fresh(), 'Y', 'Remark'))
        ->toThrow(CompanyDomainException::class);
});
