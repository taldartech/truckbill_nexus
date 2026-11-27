<?php

namespace App\Support\Files;

use App\Support\Files\Exceptions\FileNotAvailableException;
use App\Support\Files\Exceptions\FileValidationException;
use App\Support\Files\Exceptions\ScannerConfigurationException;
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

        // Resolve scanner before writing so unsafe production/local misconfig fails closed
        // without leaving an available object.
        $scanner = $this->scanner();

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

        try {
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
        } catch (Throwable $e) {
            $this->deletePath($disk, $path, $e);

            throw new RuntimeException('Failed to persist private file metadata.', 0, $e);
        }

        return $this->scanAndFinalize($file, $scanner);
    }

    public function replace(StoredFile $existing, UploadedFile $upload): StoredFile
    {
        $this->assertCanAccess($existing);

        $replacement = $this->store($upload);

        if (! $replacement->isDownloadable()) {
            try {
                $this->discardRejectedUpload($replacement);
            } catch (Throwable $cleanup) {
                throw new FileNotAvailableException(
                    'Replacement file failed scan; original file preserved; replacement cleanup pending.',
                    0,
                    $cleanup
                );
            }

            throw new FileNotAvailableException('Replacement file failed scan; original file preserved.');
        }

        try {
            DB::transaction(function () use ($existing, $replacement): void {
                $replacement->replaces_file_id = $existing->id;
                $replacement->save();

                $existing->pending_cleanup = true;
                $existing->replaced_at = now();
                $existing->scan_status = FileScanStatus::Rejected->value;
                $existing->scan_message = 'replaced';
                $existing->available_at = null;
                $existing->save();
            });
        } catch (Throwable $e) {
            // Original remains downloadable; discard the unused replacement artefact.
            // Refresh so a failed in-transaction assignment (e.g. replaces_file_id) is not re-persisted.
            try {
                $this->discardRejectedUpload($replacement->refresh());
            } catch (Throwable $cleanup) {
                throw new RuntimeException(
                    'Failed to finalize file replacement metadata; original preserved; replacement cleanup pending.',
                    0,
                    $cleanup
                );
            }

            throw new RuntimeException('Failed to finalize file replacement metadata; original preserved.', 0, $e);
        }

        // Filesystem deletion only after a successful DB commit (retryable via pending_cleanup).
        $this->cleanupReplaced($existing->refresh());

        return $replacement->refresh();
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

    /**
     * Store a platform-owned image (e.g. Billing Profile logo) using the same validation,
     * private disk, SHA-256 checksum, and malware scanner without weakening Customer isolation.
     */
    public function storePlatformImage(UploadedFile $upload): StoredFile
    {
        $this->assertAuthorizedPlatformActor();
        $this->validator->validate($upload);

        $extension = strtolower((string) $upload->getClientOriginalExtension());
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new FileValidationException('Company logo must be a JPG, JPEG, PNG, or WEBP image.');
        }

        $scanner = $this->scanner();
        $disk = (string) config('files.disk', 'private_files');
        $uuid = (string) Str::uuid();
        $path = $this->buildPath(StoredFile::PLATFORM_SCOPE, $uuid, $extension);

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
            throw new RuntimeException('Failed to store platform image.', 0, $e);
        }

        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('Stored platform image path is missing after write.');
        }

        try {
            $file = StoredFile::query()->create([
                'id' => $uuid,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $upload->getClientOriginalName(),
                'extension' => $extension,
                'mime_type' => (string) ($upload->getMimeType() ?: $upload->getClientMimeType()),
                'size_bytes' => (int) $upload->getSize(),
                'checksum_sha256' => $checksum,
                'company_id' => StoredFile::PLATFORM_SCOPE,
                'branch_id' => null,
                'owner_id' => null,
                'scan_status' => FileScanStatus::PendingScan->value,
                'scan_message' => null,
            ]);
        } catch (Throwable $e) {
            $this->deletePath($disk, $path, $e);

            throw new RuntimeException('Failed to persist platform image metadata.', 0, $e);
        }

        $finalized = $this->scanAndFinalize($file, $scanner);
        if (! $finalized->isDownloadable()) {
            throw new FileNotAvailableException('Uploaded company logo failed malware validation.');
        }

        return $finalized;
    }

    public function downloadPlatformFile(StoredFile $file): StreamedResponse
    {
        $this->assertAuthorizedPlatformActor();
        if ($file->company_id !== StoredFile::PLATFORM_SCOPE) {
            abort(403, 'Cross-scope platform file access denied.');
        }

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

    /**
     * Read platform-scoped file bytes for immutable document rendering (e.g. approved invoices).
     *
     * @return array{mime: string, contents: string}|null
     */
    public function readPlatformFileBytes(StoredFile $file): ?array
    {
        if ($file->company_id !== StoredFile::PLATFORM_SCOPE || ! $file->isDownloadable()) {
            return null;
        }

        $disk = Storage::disk($file->disk);
        if (! $disk->exists($file->path)) {
            return null;
        }

        $contents = $disk->get($file->path);
        if ($contents === null) {
            return null;
        }

        return [
            'mime' => $file->mime_type ?: 'image/png',
            'contents' => $contents,
        ];
    }

    /**
     * Deletes a replaced file's object then metadata. Safe to retry while pending_cleanup is true.
     * Filesystem deletion is intentionally outside any DB transaction that could roll back.
     */
    public function cleanupReplaced(StoredFile $file): void
    {
        if (! $file->pending_cleanup) {
            return;
        }

        $this->assertCanAccess($file);

        try {
            $this->deleteStoredObject($file);
        } catch (Throwable $e) {
            $this->retainPendingCleanup($file, $e, 'replaced');

            throw new RuntimeException(
                'Failed to delete replaced file object; cleanup remains pending for retry.',
                0,
                $e
            );
        }

        $file->delete();
    }

    private function scanAndFinalize(StoredFile $file, MalwareScanner $scanner): StoredFile
    {
        $file->scan_status = FileScanStatus::Scanning->value;
        $file->available_at = null;
        $file->save();

        try {
            $absolute = Storage::disk($file->disk)->path($file->path);
            $result = $scanner->scan($absolute, $file->original_name);
        } catch (Throwable $e) {
            $this->markRejected($file, 'scan exception: '.$this->safeFailureMessage($e));
            $this->deleteStoredObjectSafely($file, $e);

            throw new FileNotAvailableException(
                'Malware scan failed closed; file is not available.',
                0,
                $e
            );
        }

        if ($result['clean'] === true) {
            $file->scan_status = FileScanStatus::Available->value;
            $file->scan_message = $result['message'];
            $file->scanned_at = now();
            $file->available_at = now();
            $file->save();

            return $file->refresh();
        }

        $this->markRejected($file, $result['message']);
        $this->deleteStoredObjectSafely($file);

        return $file->refresh();
    }

    private function markRejected(StoredFile $file, string $message): void
    {
        $file->scan_status = FileScanStatus::Rejected->value;
        $file->scan_message = $message;
        $file->scanned_at = now();
        $file->available_at = null;
        $file->save();
    }

    private function deleteStoredObject(StoredFile $file): void
    {
        $this->deletePath($file->disk, $file->path);
    }

    private function deleteStoredObjectSafely(StoredFile $file, ?Throwable $scanFailure = null): void
    {
        try {
            $this->deleteStoredObject($file);
        } catch (Throwable $cleanup) {
            // Already rejected / not downloadable; retain path + pending_cleanup for retry.
            $this->retainPendingCleanup($file, $cleanup, $file->scan_message ?: 'rejected');

            throw new RuntimeException(
                'File marked rejected but object cleanup failed; cleanup remains pending for retry.',
                0,
                $scanFailure ?? $cleanup
            );
        }
    }

    private function deletePath(string $disk, string $path, ?Throwable $prior = null): void
    {
        try {
            $filesystem = Storage::disk($disk);

            if ($filesystem->exists($path) && ! $filesystem->delete($path)) {
                throw new RuntimeException('Storage delete returned false.');
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Failed to clean up private file object.',
                0,
                $prior ?? $e
            );
        }
    }

    /**
     * Discard an unused/rejected upload artefact.
     *
     * Deletes metadata only after the filesystem object is removed. On delete failure,
     * retains non-downloadable metadata with pending_cleanup for retry via cleanupReplaced().
     */
    private function discardRejectedUpload(StoredFile $file): void
    {
        $context = $file->scan_message ?: 'discarded';

        $file->scan_status = FileScanStatus::Rejected->value;
        $file->available_at = null;
        $file->pending_cleanup = true;
        $file->scan_message = $context;
        $file->save();

        $this->cleanupReplaced($file);
    }

    private function retainPendingCleanup(StoredFile $file, Throwable $e, string $context): void
    {
        $file->pending_cleanup = true;
        $file->available_at = null;
        $file->scan_status = FileScanStatus::Rejected->value;
        $file->scan_message = $this->cleanupPendingMessage($context, $e);
        $file->save();
    }

    private function cleanupPendingMessage(string $context, Throwable $e): string
    {
        $message = trim($context.'; cleanup pending: '.$this->safeFailureMessage($e));

        return Str::limit($message, 500, '…');
    }

    private function buildPath(string $companyId, string $uuid, string $extension): string
    {
        return sprintf('%s/%s/%s.%s', $companyId, now()->format('Y/m/d'), $uuid, $extension);
    }

    private function scanner(): MalwareScanner
    {
        if ($this->scanner !== null) {
            return $this->scanner;
        }

        try {
            return app(MalwareScanner::class);
        } catch (ScannerConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ScannerConfigurationException(
                'Malware scanner could not be resolved; private file operations fail closed.',
                0,
                $e
            );
        }
    }

    private function safeFailureMessage(Throwable $e): string
    {
        $class = $e::class;
        $message = trim($e->getMessage());

        if ($message === '') {
            return $class;
        }

        // Avoid leaking paths/secrets from raw exception text into persisted diagnostics.
        $truncated = preg_replace('/[^\p{L}\p{N}\s._:-]/u', '', $message) ?? '';
        $truncated = Str::limit(trim($truncated), 180, '…');

        return $truncated === '' ? $class : $class.': '.$truncated;
    }

    private function assertAuthorizedActor(): void
    {
        if (! Auth::user() instanceof Authenticatable) {
            abort(403, 'Authentication is required for private file access.');
        }

        TenantContextHolder::require();
    }

    private function assertAuthorizedPlatformActor(): void
    {
        if (! Auth::user() instanceof Authenticatable) {
            abort(403, 'Authentication is required for private file access.');
        }

        $context = TenantContextHolder::require();
        if (! $context->isInternal) {
            abort(403, 'Internal platform access is required for platform files.');
        }
    }
}
