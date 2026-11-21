<?php

use App\Models\User;
use App\Support\Files\Exceptions\FileNotAvailableException;
use App\Support\Files\Exceptions\ScannerConfigurationException;
use App\Support\Files\FileScanStatus;
use App\Support\Files\PrivateFileService;
use App\Support\Files\Scanning\FakeMalwareScanner;
use App\Support\Files\Scanning\MalwareScanner;
use App\Support\Files\Scanning\MalwareScannerFactory;
use App\Support\Files\Scanning\PassThroughMalwareScanner;
use App\Support\Files\Scanning\RejectAllMalwareScanner;
use App\Support\Files\StoredFile;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake('private_files');
    config([
        'files.disk' => 'private_files',
        'files.scanner' => 'pass',
        'files.max_bytes' => 2 * 1024 * 1024,
    ]);

    app()->instance(MalwareScannerFactory::class, new MalwareScannerFactory(isProduction: false));
    app()->bind(MalwareScanner::class, fn ($app) => $app->make(MalwareScannerFactory::class)->make());

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
    StoredFile::flushEventListeners();
    Mockery::close();
});

function remediationPdf(string $name = 'document.pdf', int $kilobytes = 100): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
}

test('production configuration cannot resolve the pass-through scanner', function () {
    $factory = new MalwareScannerFactory(isProduction: true);

    expect(fn () => $factory->make('pass'))
        ->toThrow(ScannerConfigurationException::class);
});

test('production with missing scanner configuration fails safely', function () {
    $factory = new MalwareScannerFactory(isProduction: true);

    expect(fn () => $factory->make(''))
        ->toThrow(ScannerConfigurationException::class);
});

test('production with unsupported scanner configuration fails safely', function () {
    $factory = new MalwareScannerFactory(isProduction: true);

    expect(fn () => $factory->make('not-a-real-engine'))
        ->toThrow(ScannerConfigurationException::class);
});

test('local configuration can use pass fake and reject adapters', function () {
    $factory = new MalwareScannerFactory(isProduction: false);

    expect($factory->make('pass'))->toBeInstanceOf(PassThroughMalwareScanner::class);
    expect($factory->make('fake'))->toBeInstanceOf(FakeMalwareScanner::class);
    expect($factory->make('reject'))->toBeInstanceOf(RejectAllMalwareScanner::class);
});

test('no file becomes available when a scanner cannot be safely resolved', function () {
    app()->instance(MalwareScannerFactory::class, new MalwareScannerFactory(isProduction: true));
    config(['files.scanner' => 'pass']);
    app()->bind(MalwareScanner::class, fn ($app) => $app->make(MalwareScannerFactory::class)->make());

    expect(fn () => app(PrivateFileService::class)->store(remediationPdf()))
        ->toThrow(ScannerConfigurationException::class);

    expect(StoredFile::query()->count())->toBe(0);
    expect(Storage::disk('private_files')->allFiles())->toBeEmpty();
});

test('scanner exception rejects file cleans object and fails closed for download', function () {
    app()->bind(MalwareScanner::class, fn () => new class implements MalwareScanner
    {
        public function scan(string $absolutePath, string $originalName): array
        {
            throw new RuntimeException('scanner crashed');
        }
    });

    expect(fn () => app(PrivateFileService::class)->store(remediationPdf('scan-boom.pdf')))
        ->toThrow(FileNotAvailableException::class);

    $file = StoredFile::query()->first();

    expect($file)->not->toBeNull();
    expect($file->scan_status)->toBe(FileScanStatus::Rejected->value);
    expect($file->isDownloadable())->toBeFalse();
    expect($file->scan_message)->toContain('scan exception');
    expect($file->scan_message)->not->toContain('/');
    Storage::disk('private_files')->assertMissing($file->path);

    expect(fn () => app(PrivateFileService::class)->download($file))
        ->toThrow(FileNotAvailableException::class);
});

test('metadata persistence failure cleans up untracked storage object', function () {
    StoredFile::creating(function (): void {
        throw new RuntimeException('forced metadata failure');
    });

    expect(fn () => app(PrivateFileService::class)->store(remediationPdf('orphan.pdf')))
        ->toThrow(RuntimeException::class);

    expect(StoredFile::query()->count())->toBe(0);
    expect(Storage::disk('private_files')->allFiles())->toBeEmpty();
});

test('database transaction failure before cleanup preserves original file', function () {
    $original = app(PrivateFileService::class)->store(remediationPdf('original.pdf'));
    $originalPath = $original->path;

    StoredFile::updating(function (StoredFile $file): void {
        if ($file->isDirty('replaces_file_id') || $file->isDirty('pending_cleanup')) {
            throw new RuntimeException('forced replacement tx failure');
        }
    });

    expect(fn () => app(PrivateFileService::class)->replace($original, remediationPdf('replacement.pdf')))
        ->toThrow(RuntimeException::class);

    $original->refresh();
    expect($original->isDownloadable())->toBeTrue();
    expect($original->pending_cleanup)->toBeFalse();
    Storage::disk('private_files')->assertExists($originalPath);
});

test('filesystem deletion failure after commit retains retryable cleanup state', function () {
    $original = app(PrivateFileService::class)->store(remediationPdf('original.pdf'));
    $replacement = app(PrivateFileService::class)->store(remediationPdf('replacement.pdf'));
    $oldPath = $original->path;

    DB::transaction(function () use ($original, $replacement): void {
        $replacement->replaces_file_id = $original->id;
        $replacement->save();

        $original->pending_cleanup = true;
        $original->replaced_at = now();
        $original->scan_status = FileScanStatus::Rejected->value;
        $original->scan_message = 'replaced';
        $original->available_at = null;
        $original->save();
    });

    $failingDisk = Mockery::mock(Filesystem::class);
    $failingDisk->shouldReceive('exists')->with($oldPath)->andReturn(true);
    $failingDisk->shouldReceive('delete')->with($oldPath)->andThrow(new RuntimeException('disk I/O error'));

    Storage::forgetDisk('private_files');
    Storage::set('private_files', $failingDisk);

    expect(fn () => app(PrivateFileService::class)->cleanupReplaced($original->refresh()))
        ->toThrow(RuntimeException::class);

    $original->refresh();
    expect($original->pending_cleanup)->toBeTrue();
    expect($original->path)->toBe($oldPath);
    expect($original->isDownloadable())->toBeFalse();
    expect(StoredFile::query()->whereKey($replacement->id)->exists())->toBeTrue();
});

test('pending cleanup can be retried after a previous filesystem failure', function () {
    $original = app(PrivateFileService::class)->store(remediationPdf('original.pdf'));
    $replacement = app(PrivateFileService::class)->store(remediationPdf('replacement.pdf'));
    $oldPath = $original->path;

    DB::transaction(function () use ($original, $replacement): void {
        $replacement->replaces_file_id = $original->id;
        $replacement->save();

        $original->pending_cleanup = true;
        $original->replaced_at = now();
        $original->scan_status = FileScanStatus::Rejected->value;
        $original->scan_message = 'replaced; cleanup pending: object delete failed';
        $original->available_at = null;
        $original->save();
    });

    Storage::disk('private_files')->assertExists($oldPath);

    app(PrivateFileService::class)->cleanupReplaced($original->refresh());

    expect(StoredFile::query()->find($original->id))->toBeNull();
    Storage::disk('private_files')->assertMissing($oldPath);
    expect(StoredFile::query()->find($replacement->id)?->isDownloadable())->toBeTrue();
});

test('cleanup still cannot remove another tenants pending file', function () {
    $file = app(PrivateFileService::class)->store(remediationPdf());
    $file->pending_cleanup = true;
    $file->scan_status = FileScanStatus::Rejected->value;
    $file->available_at = null;
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
