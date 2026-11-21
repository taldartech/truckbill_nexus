<?php

namespace App\Support\Files;

use App\Support\Files\Exceptions\FileNotAvailableException;
use App\Support\Files\Scanning\MalwareScanner;
use App\Support\Tenant\TenantContextHolder;
use App\Support\Tenant\TenantStorageMetadata;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Private file store/scan/download/replace foundation (ADR-008 / WS-5).
 */
class PrivateFileService
{
    public function __construct(
        private readonly FileValidator $validator = new FileValidator,
        private readonly ?MalwareScanner $scanner = null,
    ) {}

    public function store(UploadedFile $upload): StoredFile
    {
        $this->assertAuthorizedActor();
        $this->validator->validate($upload);

        $tenant = TenantStorageMetadata::forCurrentContext();
        $disk = (string) config('files.disk', 'private_files');
        $extension = strtolower((string) $upload->getClientOriginalExtension());
        $uuid = (string) Str::uuid();
        $path = $this->buildPath($tenant['company_id'], $uuid, $extension);

        $absoluteTmp = $upload->getRealPath();
        if ($absoluteTmp === false) {
            throw new RuntimeException('Upload temporary path is unavailable.');
        }

        $checksum = hash_file('sha256', $absoluteTmp) ?: null;

        try {
            Storage::disk($disk)->putFileAs(
                dirname($path),
                $upload,
                basename($path),
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to store private file.', 0, $e);
        }

        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('Stored file path is missing after write.');
        }

        $file = StoredFile::query()->create([
            'id' => $uuid,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $upload->getClientOriginalName(),
            'extension' => $extension,
            'mime_type' => (string) ($upload->getMimeType() ?: $upload->getClientMimeType()),
            'size_bytes' => (int) $upload->getSize(),
            'checksum_sha256' => $checksum,
            'company_id' => $tenant['company_id'],
            'branch_id' => $tenant['branch_id'],
            'owner_id' => $tenant['owner_id'],
            'scan_status' => FileScanStatus::PendingScan->value,
            'scan_message' => null,
        ]);

        return $this->scanAndFinalize($file);
    }

    public function replace(StoredFile $existing, UploadedFile $upload): StoredFile
    {
        $this->assertCanAccess($existing);

        $replacement = $this->store($upload);

        if (! $replacement->isDownloadable()) {
            $this->deleteStoredObject($replacement);
            $replacement->delete();

            throw new FileNotAvailableException('Replacement file failed scan; original file preserved.');
        }

        return DB::transaction(function () use ($existing, $replacement) {
            $replacement->replaces_file_id = $existing->id;
            $replacement->save();

            $existing->pending_cleanup = true;
            $existing->replaced_at = now();
            $existing->scan_status = FileScanStatus::Rejected->value;
            $existing->scan_message = 'replaced';
            $existing->available_at = null;
            $existing->save();

            $this->cleanupReplaced($existing);

            return $replacement->refresh();
        });
    }

    public function download(StoredFile $file): StreamedResponse
    {
        $this->assertCanAccess($file);

        if (! $file->isDownloadable()) {
            throw new FileNotAvailableException('File is not available for download.');
        }

        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            throw new FileNotAvailableException('Stored object is missing.');
        }

        return $disk->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
        ]);
    }

    public function assertCanAccess(StoredFile $file): void
    {
        $this->assertAuthorizedActor();
        TenantStorageMetadata::assertSameTenant($file->tenantMetadata());
    }

    public function cleanupReplaced(StoredFile $file): void
    {
        if (! $file->pending_cleanup) {
            return;
        }

        $this->assertCanAccess($file);
        $this->deleteStoredObject($file);
        $file->delete();
    }

    private function scanAndFinalize(StoredFile $file): StoredFile
    {
        $file->scan_status = FileScanStatus::Scanning->value;
        $file->save();

        $absolute = Storage::disk($file->disk)->path($file->path);
        $result = $this->scanner()->scan($absolute, $file->original_name);

        if ($result['clean'] === true) {
            $file->scan_status = FileScanStatus::Available->value;
            $file->scan_message = $result['message'];
            $file->scanned_at = now();
            $file->available_at = now();
            $file->save();

            return $file->refresh();
        }

        $file->scan_status = FileScanStatus::Rejected->value;
        $file->scan_message = $result['message'];
        $file->scanned_at = now();
        $file->available_at = null;
        $file->save();

        $this->deleteStoredObject($file);

        return $file->refresh();
    }

    private function deleteStoredObject(StoredFile $file): void
    {
        $disk = Storage::disk($file->disk);

        if ($disk->exists($file->path)) {
            $disk->delete($file->path);
        }
    }

    private function buildPath(string $companyId, string $uuid, string $extension): string
    {
        return sprintf('%s/%s/%s.%s', $companyId, now()->format('Y/m/d'), $uuid, $extension);
    }

    private function scanner(): MalwareScanner
    {
        return $this->scanner ?? app(MalwareScanner::class);
    }

    private function assertAuthorizedActor(): void
    {
        if (! Auth::user() instanceof Authenticatable) {
            abort(403, 'Authentication is required for private file access.');
        }

        TenantContextHolder::require();
    }
}
