<?php

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Invitation\Enums\InvitationStatus;
use App\Domains\Invitation\InvitationService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Models\Branch;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Invitation;
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

function ownerPanelWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

/**
 * @return array{owner: User, company: Company, headOffice: Branch}
 */
function createManagedOwnerWorkspace(string $name = 'Managed Co'): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = ownerPanelWorkspace()->createCompany($owner, $name);
    $headOffice = $company->branches()->where('name', 'Head Office')->firstOrFail();

    return compact('owner', 'company', 'headOffice');
}

function loginOwnerPanelCustomer(User $user, string $password = 'owner-secret'): TestResponse
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

test('owner can update company profile name with remark', function () {
    ['owner' => $owner, 'company' => $company] = createManagedOwnerWorkspace();

    loginOwnerPanelCustomer($owner);

    $this->post(route('owner.settings.update', ['company' => $company]), [
        'name' => 'Renamed Logistics',
        'remark' => 'Legal trading name update',
    ])->assertRedirect(route('owner.settings'));

    expect($company->fresh()->name)->toBe('Renamed Logistics');
});

test('owner profile update rejects empty remark and member cannot update', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createManagedOwnerWorkspace();
    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = ownerPanelWorkspace()->addMembership($company, $member, 'Join');
    ownerPanelWorkspace()->assignBranch($membership, $headOffice, 'Assign');

    loginOwnerPanelCustomer($owner);
    $this->from(route('owner.settings'))
        ->post(route('owner.settings.update', ['company' => $company]), [
            'name' => 'Nope',
            'remark' => '',
        ])
        ->assertSessionHasErrors('remark');

    loginOwnerPanelCustomer($member, 'member-secret');
    $this->post(route('owner.settings.update', ['company' => $company]), [
        'name' => 'Hijack',
        'remark' => 'Attempt',
    ])->assertForbidden();

    expect($company->fresh()->name)->toBe('Managed Co');
});

test('deactivated company profile is read-only for owner', function () {
    ['owner' => $owner, 'company' => $company] = createManagedOwnerWorkspace();
    $sa = User::factory()->superAdmin()->create();
    ownerPanelWorkspace()->deactivateCompany($sa, $company, 'Pause trading');

    loginOwnerPanelCustomer($owner);

    $this->get(route('owner.settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/settings')
            ->where('canEditProfile', false)
            ->where('ownerPanelScope', CompanyAccessService::OWNER_PANEL_ADMIN_BILLING_ONLY));

    $this->post(route('owner.settings.update', ['company' => $company]), [
        'name' => 'Should Fail',
        'remark' => 'Write while deactivated',
    ])->assertForbidden();
});

test('owner can invite revoke deactivate membership and assign branch role', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createManagedOwnerWorkspace();
    $role = Role::factory()->create([
        'domain' => PermissionDomain::Customer,
        'name' => 'Branch Clerk',
        'slug' => 'branch-clerk-'.uniqid(),
    ]);
    $invitations = app(InvitationService::class);

    loginOwnerPanelCustomer($owner);

    $this->post(route('owner.users.invite-for-branch'), [
        'email' => 'pending.revoke@example.com',
    ])->assertRedirect(route('owner.users'));

    $pendingInvitation = Invitation::query()
        ->where('email', 'pending.revoke@example.com')
        ->firstOrFail();
    expect($pendingInvitation->status)->toBe(InvitationStatus::Pending)
        ->and($pendingInvitation->invited_by_user_id)->toBe($owner->id);

    $acceptedInvite = $invitations->create(
        $owner,
        $company,
        'accepted.member@example.com',
        $headOffice,
    );
    $memberUser = $invitations->accept($acceptedInvite['plain_token'], [
        'name' => 'Accepted Member',
        'password' => 'member-secret-pass',
        'password_confirmation' => 'member-secret-pass',
    ]);

    $membership = CompanyMembership::query()
        ->where('company_id', $company->id)
        ->where('user_id', $memberUser->id)
        ->whereNull('deactivated_at')
        ->firstOrFail();

    $this->get(route('owner.users.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/users/show')
            ->where('membership.is_owner', false)
            ->where('canManage', true));

    $this->post(route('owner.users.assign-role'), [
        'role_id' => $role->id,
        'remark' => 'Grant clerk',
    ])->assertRedirect(route('owner.users.show'));

    $roleAssignment = BranchRoleAssignment::query()
        ->where('company_membership_id', $membership->id)
        ->whereNull('deactivated_at')
        ->firstOrFail();

    expect($roleAssignment->role_id)->toBe($role->id);

    $this->post(route('owner.users.roles.revoke'), [
        'remark' => 'Revoke clerk',
    ])->assertRedirect();

    expect($roleAssignment->fresh()->isActive())->toBeFalse();

    $this->post(route('owner.users.invitations.revoke'), [
        'remark' => 'No longer needed',
    ])->assertRedirect(route('owner.users'));

    expect($pendingInvitation->fresh()->status)->toBe(InvitationStatus::Revoked);

    $this->post(route('owner.users.deactivate'), [
        'remark' => 'Left company',
    ])->assertRedirect(route('owner.users'));

    expect($membership->fresh()->isActive())->toBeFalse();
});

test('owner cannot deactivate own membership or escalate member via owner routes', function () {
    ['owner' => $owner, 'company' => $company, 'headOffice' => $headOffice] = createManagedOwnerWorkspace();
    $ownerMembership = CompanyMembership::query()
        ->where('company_id', $company->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();

    $member = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membership = ownerPanelWorkspace()->addMembership($company, $member, 'Join');
    ownerPanelWorkspace()->assignBranch($membership, $headOffice, 'Assign');

    loginOwnerPanelCustomer($owner);
    $this->from(route('owner.users.show'))
        ->post(route('owner.users.deactivate'), [
            'remark' => 'Self deactivate',
        ])
        ->assertSessionHasErrors('remark');

    loginOwnerPanelCustomer($member, 'member-secret');
    $this->post(route('owner.users.invite'), [
        'email' => 'evil@example.com',
    ])->assertForbidden();
});

test('owner cannot manage another company memberships and audit is company scoped', function () {
    ['owner' => $ownerA, 'company' => $companyA] = createManagedOwnerWorkspace('Alpha Co');
    ['owner' => $ownerB, 'company' => $companyB, 'headOffice' => $headOfficeB] = createManagedOwnerWorkspace('Beta Co');

    $memberB = User::factory()->customer()->create([
        'password' => Hash::make('member-secret'),
    ]);
    $membershipB = ownerPanelWorkspace()->addMembership($companyB, $memberB, 'Join B');
    ownerPanelWorkspace()->assignBranch($membershipB, $headOfficeB, 'Assign B');

    loginOwnerPanelCustomer($ownerA);

    $this->get(route('owner.users.show'))->assertNotFound();

    $this->post(route('owner.users.invite'), [
        'email' => 'cross@example.com',
    ])->assertForbidden();

    $this->get(route('owner.audit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/audit')
            ->where('company.id', $companyA->id));

    $foreignCount = AuditEntry::query()->where('company_id', $companyB->id)->count();
    expect($foreignCount)->toBeGreaterThan(0);

    $this->get(route('owner.audit'))
        ->assertInertia(fn ($page) => $page
            ->where('entries.meta.total', AuditEntry::query()->where('company_id', $companyA->id)->count()));

    expect($ownerB->id)->not->toBe($ownerA->id);
});

test('company owner may create invitations via invitation service', function () {
    ['owner' => $owner, 'company' => $company] = createManagedOwnerWorkspace('Invite Owner Co');

    $result = app(InvitationService::class)->create($owner, $company, 'owner.invite@example.com');

    expect($result['invitation']->invited_by_user_id)->toBe($owner->id)
        ->and($result['invitation']->status)->toBe(InvitationStatus::Pending);
});
