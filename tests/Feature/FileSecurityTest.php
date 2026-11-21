<?php

use App\Models\User;
use App\Support\Files\Exceptions\FileNotAvailableException;
use App\Support\Files\Exceptions\FileValidationException;
use App\Support\Files\FileScanStatus;
use App\Support\Files\PrivateFileService;
use App\Support\Files\Scanning\FakeMalwareScanner;
use App\Support\Files\Scanning\MalwareScanner;
use App\Support\Files\Scanning\PassThroughMalwareScanner;
use App\Support\Files\Scanning\RejectAllMalwareScanner;
use App\Support\Files\StoredFile;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake('private_files');
    config([
        'files.disk' => 'private_files',
        'files.scanner' => 'pass',
        'files.max_bytes' => 2 * 1024 * 1024,
    ]);

    app()->bind(MalwareScanner::class, fn () => new PassThroughMalwareScanner);

    TenantContextHolder::clear();
    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-files',
        branchId: 'br-files',
        ownerId: 'own-files',
    ));

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    TenantContextHolder::clear();
});

function pdfUpload(string $name = 'document.pdf', int $kilobytes = 100): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
}

test('successful upload becomes available after scan', function () {
    $file = app(PrivateFileService::class)->store(pdfUpload());

    expect($file->scan_status)->toBe(FileScanStatus::Available->value);
    expect($file->isDownloadable())->toBeTrue();
    Storage::disk('private_files')->assertExists($file->path);
});

test('oversized upload is rejected', function () {
    expect(fn () => app(PrivateFileService::class)->store(pdfUpload('big.pdf', 3072)))
        ->toThrow(FileValidationException::class);

    expect(StoredFile::query()->count())->toBe(0);
});

test('disallowed extension is rejected', function () {
    $upload = UploadedFile::fake()->create('notes.exe', 10, 'application/octet-stream');

    expect(fn () => app(PrivateFileService::class)->store($upload))
        ->toThrow(FileValidationException::class);

    expect(StoredFile::query()->count())->toBe(0);
});

test('mime mismatch is rejected', function () {
    $upload = UploadedFile::fake()->create('document.pdf', 10, 'text/plain');

    expect(fn () => app(PrivateFileService::class)->store($upload))
        ->toThrow(FileValidationException::class);

    expect(StoredFile::query()->count())->toBe(0);
});

test('unscanned pending file cannot be downloaded', function () {
    $file = StoredFile::query()->create([
        'disk' => 'private_files',
        'path' => 'co-files/pending.pdf',
        'original_name' => 'pending.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'company_id' => 'co-files',
        'branch_id' => 'br-files',
        'owner_id' => 'own-files',
        'scan_status' => FileScanStatus::PendingScan->value,
    ]);

    Storage::disk('private_files')->put($file->path, 'x');

    expect(fn () => app(PrivateFileService::class)->download($file))
        ->toThrow(FileNotAvailableException::class);
});

test('scanning status file cannot be downloaded', function () {
    $file = StoredFile::query()->create([
        'disk' => 'private_files',
        'path' => 'co-files/scanning.pdf',
        'original_name' => 'scanning.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'company_id' => 'co-files',
        'branch_id' => 'br-files',
        'owner_id' => 'own-files',
        'scan_status' => FileScanStatus::Scanning->value,
    ]);

    Storage::disk('private_files')->put($file->path, 'x');

    expect(fn () => app(PrivateFileService::class)->download($file))
        ->toThrow(FileNotAvailableException::class);
});

test('failed scan cannot be downloaded and object is removed', function () {
    app()->bind(MalwareScanner::class, fn () => new RejectAllMalwareScanner);

    $file = app(PrivateFileService::class)->store(pdfUpload('bad.pdf'));

    expect($file->scan_status)->toBe(FileScanStatus::Rejected->value);
    expect($file->isDownloadable())->toBeFalse();
    Storage::disk('private_files')->assertMissing($file->path);

    expect(fn () => app(PrivateFileService::class)->download($file))
        ->toThrow(FileNotAvailableException::class);
});

test('malicious scan result cannot be downloaded', function () {
    app()->bind(MalwareScanner::class, fn () => new FakeMalwareScanner);

    $file = app(PrivateFileService::class)->store(pdfUpload('report-MALWARE.pdf'));

    expect($file->scan_status)->toBe(FileScanStatus::Rejected->value);
    expect(fn () => app(PrivateFileService::class)->download($file))
        ->toThrow(FileNotAvailableException::class);
});

test('cross-company download is denied', function () {
    $file = app(PrivateFileService::class)->store(pdfUpload());

    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-other',
        branchId: 'br-files',
        ownerId: 'own-files',
    ));

    expect(fn () => app(PrivateFileService::class)->download($file))
        ->toThrow(HttpException::class);
});

test('cross-branch download is denied without owner scope', function () {
    $file = app(PrivateFileService::class)->store(pdfUpload());

    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-files',
        branchId: 'br-other',
        ownerId: 'own-files',
    ));

    expect(fn () => app(PrivateFileService::class)->download($file))
        ->toThrow(HttpException::class);
});

test('owner-scope access without owner identity is denied', function () {
    $file = app(PrivateFileService::class)->store(pdfUpload());

    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-files',
        branchId: null,
        ownerId: null,
        isOwnerScope: true,
    ));

    expect(fn () => app(PrivateFileService::class)->download($file))
        ->toThrow(HttpException::class);
});

test('internal team without matching branch cannot download branch-scoped file', function () {
    $file = app(PrivateFileService::class)->store(pdfUpload());

    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-files',
        branchId: null,
        ownerId: null,
        isInternal: true,
    ));

    expect(fn () => app(PrivateFileService::class)->download($file))
        ->toThrow(HttpException::class);
});

test('missing tenant context is denied', function () {
    TenantContextHolder::clear();

    expect(fn () => app(PrivateFileService::class)->store(pdfUpload()))
        ->toThrow(RuntimeException::class);
});

test('unauthenticated user is denied', function () {
    auth()->logout();

    expect(fn () => app(PrivateFileService::class)->store(pdfUpload()))
        ->toThrow(HttpException::class);
});

test('failed replacement preserves the original file', function () {
    $original = app(PrivateFileService::class)->store(pdfUpload('original.pdf'));
    $originalPath = $original->path;

    app()->bind(MalwareScanner::class, fn () => new RejectAllMalwareScanner);

    expect(fn () => app(PrivateFileService::class)->replace($original, pdfUpload('replacement.pdf')))
        ->toThrow(FileNotAvailableException::class);

    $original->refresh();
    expect($original->isDownloadable())->toBeTrue();
    Storage::disk('private_files')->assertExists($originalPath);
});

test('successful replacement cleans up the old file', function () {
    $original = app(PrivateFileService::class)->store(pdfUpload('original.pdf'));
    $oldPath = $original->path;

    $replacement = app(PrivateFileService::class)->replace($original, pdfUpload('replacement.pdf'));

    expect($replacement->isDownloadable())->toBeTrue();
    expect($replacement->replaces_file_id)->toBe($original->id);
    expect(StoredFile::query()->find($original->id))->toBeNull();
    Storage::disk('private_files')->assertMissing($oldPath);
    Storage::disk('private_files')->assertExists($replacement->path);
});

test('private files are not on the public disk and are not publicly served', function () {
    $file = app(PrivateFileService::class)->store(pdfUpload());

    expect(Storage::disk('public')->exists($file->path))->toBeFalse();
    expect(config('filesystems.disks.private_files.serve'))->toBeFalse();
    expect(config('filesystems.disks.private_files.visibility'))->toBe('private');
});

test('cleanup cannot remove an unrelated tenants file', function () {
    $file = app(PrivateFileService::class)->store(pdfUpload());
    $file->pending_cleanup = true;
    $file->save();

    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-other',
        branchId: 'br-files',
        ownerId: 'own-files',
    ));

    expect(fn () => app(PrivateFileService::class)->cleanupReplaced($file))
        ->toThrow(HttpException::class);

    expect(StoredFile::query()->whereKey($file->id)->exists())->toBeTrue();
});

test('failed storage write does not create an available file record', function () {
    $blocker = storage_path('framework/testing/not-a-dir-'.uniqid());
    file_put_contents($blocker, 'x');

    config([
        'filesystems.disks.broken_private' => [
            'driver' => 'local',
            'root' => $blocker,
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
        ],
        'files.disk' => 'broken_private',
    ]);

    expect(fn () => app(PrivateFileService::class)->store(pdfUpload()))
        ->toThrow(RuntimeException::class);

    expect(StoredFile::query()->count())->toBe(0);

    @unlink($blocker);
});

test('download streams an available private file', function () {
    $file = app(PrivateFileService::class)->store(pdfUpload('ok.pdf'));
    $response = app(PrivateFileService::class)->download($file);

    expect($response->getStatusCode())->toBe(200);
});

test('owner-scope with matching owner may access same-company branch file', function () {
    $file = app(PrivateFileService::class)->store(pdfUpload());

    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-files',
        branchId: null,
        ownerId: 'own-files',
        isOwnerScope: true,
    ));

    $response = app(PrivateFileService::class)->download($file);

    expect($response->getStatusCode())->toBe(200);
});
