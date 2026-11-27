<?php

require_once __DIR__.'/../Support/VehicleMasterFixtures.php';

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Document\DocumentTypeCatalog;
use App\Domains\Document\Exceptions\DocumentDomainException;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Vehicle\VehicleService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\Role;
use App\Models\User;
use App\Support\Files\Exceptions\FileNotAvailableException;
use App\Support\Files\Exceptions\FileValidationException;
use App\Support\Files\Scanning\MalwareScanner;
use App\Support\Files\Scanning\PassThroughMalwareScanner;
use App\Support\Files\Scanning\RejectAllMalwareScanner;
use App\Support\Files\StoredFile;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function docActingAsCustomer(User $user): void
{
    test()->flushSession();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
}

function docPdf(string $name = 'document.pdf', int $kilobytes = 100): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
}

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

function docCustomerRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

function docWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Doc Co');
    $branch = $company->branches()->firstOrFail();

    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = $workspace->addMembership($company, $member, 'Join');
    $memberAssignment = $workspace->assignBranch($membership, $branch, 'Assign');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $memberAssignment,
        docCustomerRole('customer.admin'),
        'Grant admin',
    );

    $otherOwner = User::factory()->customer()->create();
    $otherCompany = $workspace->createCompany($otherOwner, 'Other Doc Co');
    $otherBranch = $otherCompany->branches()->firstOrFail();

    return compact('owner', 'company', 'branch', 'member', 'otherOwner', 'otherCompany', 'otherBranch');
}

test('upload attach download and storage accounting', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = docWorkspace();
    docActingAsCustomer($owner);

    $vehicle = app(VehicleService::class)->create($owner, $company, null, ownVehicleMasterPayload($owner, [
        'registration_number' => 'MH14DOC001',
        'vehicle_type' => 'truck',
    ]));

    $docs = app(DocumentAttachmentService::class);
    $attachment = $docs->uploadAndAttach(
        $owner,
        $company,
        $branch,
        'vehicle',
        $vehicle->id,
        docPdf('rc.pdf', 200),
        documentType: DocumentTypeCatalog::REGISTRATION_CERTIFICATE,
        label: 'RC Book',
    );

    expect($attachment->counts_toward_storage)->toBeTrue()
        ->and($attachment->version)->toBe(1);

    $response = $docs->download($owner, $attachment);
    expect($response->getStatusCode())->toBe(200);

    $usage = $docs->storageUsage($company);
    expect($usage['attachment_count'])->toBe(1)->and($usage['file_bytes'])->toBeGreaterThan(0);
});

test('cross-company file attach and download denied', function () {
    [
        'owner' => $owner,
        'company' => $company,
        'branch' => $branch,
        'otherOwner' => $otherOwner,
        'otherCompany' => $otherCompany,
        'otherBranch' => $otherBranch,
    ] = docWorkspace();

    docActingAsCustomer($owner);
    $vehicle = app(VehicleService::class)->create($owner, $company, null, ownVehicleMasterPayload($owner, [
        'registration_number' => 'MH14DOC002',
    ]));

    $file = app(DocumentAttachmentService::class)->uploadAndAttach(
        $owner,
        $company,
        $branch,
        'vehicle',
        $vehicle->id,
        docPdf(),
        documentType: DocumentTypeCatalog::REGISTRATION_CERTIFICATE,
    );

    docActingAsCustomer($otherOwner);
    expect(fn () => app(DocumentAttachmentService::class)->download($otherOwner, $file))
        ->toThrow(MasterDomainException::class);

    expect(fn () => app(DocumentAttachmentService::class)->attachExistingFile(
        $otherOwner,
        $otherCompany,
        $otherBranch,
        'company_record',
        $otherCompany->id,
        $file->storedFile()->firstOrFail(),
    ))->toThrow(DocumentDomainException::class);
});

test('unauthorized parent and file id tampering rejected', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch, 'otherCompany' => $otherCompany] = docWorkspace();
    docActingAsCustomer($owner);

    expect(fn () => app(DocumentAttachmentService::class)->uploadAndAttach(
        $owner,
        $company,
        $branch,
        'vehicle',
        (string) Str::uuid(),
        docPdf(),
    ))->toThrow(DocumentDomainException::class);

    $foreign = StoredFile::query()->create([
        'id' => (string) Str::uuid(),
        'disk' => 'private_files',
        'path' => 'foreign/x.pdf',
        'original_name' => 'x.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'company_id' => $otherCompany->id,
        'branch_id' => null,
        'owner_id' => (string) $otherCompany->owner_user_id,
        'scan_status' => 'available',
        'available_at' => now(),
    ]);

    expect(fn () => app(DocumentAttachmentService::class)->attachExistingFile(
        $owner,
        $company,
        $branch,
        'company_record',
        $company->id,
        $foreign,
    ))->toThrow(DocumentDomainException::class);
});

test('invalid type size scan failure and double-count prevention', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = docWorkspace();
    docActingAsCustomer($owner);

    $docs = app(DocumentAttachmentService::class);

    expect(fn () => $docs->uploadAndAttach(
        $owner,
        $company,
        $branch,
        'company_record',
        $company->id,
        docPdf('big.pdf', 3072),
    ))->toThrow(FileValidationException::class);

    expect(fn () => $docs->uploadAndAttach(
        $owner,
        $company,
        $branch,
        'company_record',
        $company->id,
        UploadedFile::fake()->create('evil.exe', 10, 'application/octet-stream'),
    ))->toThrow(FileValidationException::class);

    app()->bind(MalwareScanner::class, fn () => new RejectAllMalwareScanner);
    expect(fn () => $docs->uploadAndAttach(
        $owner,
        $company,
        $branch,
        'company_record',
        $company->id,
        docPdf('bad.pdf'),
    ))->toThrow(FileNotAvailableException::class);

    app()->bind(MalwareScanner::class, fn () => new PassThroughMalwareScanner);
    $ok = $docs->uploadAndAttach(
        $owner,
        $company,
        $branch,
        'company_record',
        $company->id,
        docPdf('ok.pdf'),
    );

    expect(fn () => $docs->attachExistingFile(
        $owner,
        $company,
        $branch,
        'company_record',
        $company->id,
        $ok->storedFile()->firstOrFail(),
    ))->toThrow(DocumentDomainException::class);

    // Failed uploads must not count toward storage.
    $usage = $docs->storageUsage($company);
    expect($usage['attachment_count'])->toBe(1);
});

test('replacement soft-delete retention and reconciliation', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = docWorkspace();
    docActingAsCustomer($owner);

    $docs = app(DocumentAttachmentService::class);
    $original = $docs->uploadAndAttach(
        $owner,
        $company,
        $branch,
        'company_record',
        $company->id,
        docPdf('v1.pdf', 50),
    );

    $replaced = $docs->replace($owner, $original, docPdf('v2.pdf', 60), 'Replace RC');
    expect($replaced->version)->toBe(2)
        ->and($replaced->supersedes_attachment_id)->toBe($original->id)
        ->and($original->fresh()->is_current)->toBeFalse();

    $docs->softDelete($owner, $replaced, 'Soft delete current', retainStorageCount: true);
    $usage = $docs->storageUsage($company);
    expect($usage['soft_deleted_retained_bytes'])->toBeGreaterThan(0)
        ->and($usage['file_bytes'])->toBeGreaterThan(0);

    $reconcile = $docs->reconcileStorage($company);
    expect($reconcile)->toHaveKeys(['counted_bytes', 'raw_available_bytes', 'delta_bytes']);
});
