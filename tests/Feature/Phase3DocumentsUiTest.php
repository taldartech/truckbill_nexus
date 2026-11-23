<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\DocumentAttachment;
use App\Models\Role;
use App\Models\User;
use App\Support\Files\Scanning\MalwareScanner;
use App\Support\Files\Scanning\PassThroughMalwareScanner;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
    Storage::fake('private_files');
    config([
        'files.disk' => 'private_files',
        'files.scanner' => 'pass',
        'files.max_bytes' => 2 * 1024 * 1024,
    ]);
    app()->bind(MalwareScanner::class, fn () => new PassThroughMalwareScanner);
});

afterEach(function () {
    TenantContextHolder::clear();
});

function documentsUiWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Docs UI Co');
    $branch = $company->branches()->firstOrFail();

    $viewer = User::factory()->customer()->create();
    $viewerMembership = $workspace->addMembership($company, $viewer, 'Join viewer');
    $viewerAssignment = $workspace->assignBranch($viewerMembership, $branch, 'Assign viewer');
    $rbac->assignCustomerBranchRole(
        $owner,
        $viewerMembership,
        $branch,
        $viewerAssignment,
        Role::query()->where('domain', PermissionDomain::Customer->value)->where('slug', 'customer.viewer')->firstOrFail(),
        'Grant viewer',
    );

    $otherOwner = User::factory()->customer()->create();
    $otherCompany = $workspace->createCompany($otherOwner, 'Other Docs Co');
    $otherBranch = $otherCompany->branches()->firstOrFail();

    return compact('owner', 'company', 'branch', 'viewer', 'otherOwner', 'otherCompany', 'otherBranch');
}

test('authorized users can open document manager for a party parent', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = documentsUiWorkspace();

    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $owner->email, 'password' => 'owner-secret']);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    enterBranchWorkspace($company, $branch)->assertRedirect();

    $party = app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: $branch,
        attributes: ['name' => 'Docs Party', 'contact_number' => '9111111111'],
        roles: [['role' => PartyRoleType::Customer->value, 'attributes' => []]],
    );

    $this->get(route('branch.documents.index', ['parentType' => 'party', 'parentId' => $party->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/documents/index')
            ->where('parent.type', 'party')
            ->where('parent.id', $party->id)
            ->where('max_upload_bytes', 2_097_152));

    $this->get(route('branch.parties.show', $party))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/show')
            ->where('documentDrawer.parent.type', 'party')
            ->where('documentDrawer.parent.id', $party->id)
            ->has('documentDrawer.attachments')
            ->has('documentDrawer.storage')
            ->has('documentDrawer.links.upload'));
});

test('document upload download authorization respects company isolation and scan gate', function () {
    [
        'owner' => $owner,
        'company' => $company,
        'branch' => $branch,
        'viewer' => $viewer,
        'otherOwner' => $otherOwner,
        'otherCompany' => $otherCompany,
        'otherBranch' => $otherBranch,
    ] = documentsUiWorkspace();

    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $owner->email, 'password' => 'owner-secret']);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    enterBranchWorkspace($company, $branch)->assertRedirect();

    $party = app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: $branch,
        attributes: ['name' => 'Upload Party'],
        roles: [['role' => PartyRoleType::Customer->value, 'attributes' => []]],
    );

    $this->post(route('branch.documents.store', ['parentType' => 'party', 'parentId' => $party->id]), [
        'file' => UploadedFile::fake()->create('rc.pdf', 100, 'application/pdf'),
        'label' => 'RC',
        'remark' => 'Upload RC',
    ])->assertRedirect();

    $attachment = DocumentAttachment::query()->where('parent_id', $party->id)->firstOrFail();

    $this->get(route('branch.documents.download', $attachment))->assertOk();

    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $otherOwner->email, 'password' => 'password']);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
    enterBranchWorkspace($otherCompany, $otherBranch)->assertRedirect();
    $this->get(route('branch.documents.download', $attachment))->assertNotFound();

    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $viewer->email, 'password' => 'password']);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
    enterBranchWorkspace($company, $branch)->assertRedirect();
    $this->post(route('branch.documents.store', ['parentType' => 'party', 'parentId' => $party->id]), [
        'file' => UploadedFile::fake()->create('x.pdf', 50, 'application/pdf'),
        'remark' => 'Denied upload',
    ])->assertForbidden();
});
