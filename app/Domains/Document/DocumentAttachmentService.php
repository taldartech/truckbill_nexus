<?php

namespace App\Domains\Document;

use App\Domains\Document\Exceptions\DocumentDomainException;
use App\Domains\Master\Support\MasterAccess;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentAttachment;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Files\Exceptions\FileNotAvailableException;
use App\Support\Files\PrivateFileService;
use App\Support\Files\StoredFile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared document attachment lifecycle (P3-WS-3) atop PrivateFileService.
 *
 * Reusable by future LR/trips/invoices without implementing those modules now.
 */
final class DocumentAttachmentService
{
    public function __construct(
        private readonly MasterAccess $access = new MasterAccess,
        private readonly PrivateFileService $files = new PrivateFileService,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * Attach an already-stored private file to an approved parent record.
     */
    public function attachExistingFile(
        User $actor,
        Company $company,
        ?Branch $branch,
        string $parentType,
        string $parentId,
        StoredFile $file,
        ?string $documentType = null,
        ?string $label = null,
        string $remark = 'Attach document',
    ): DocumentAttachment {
        $this->access->assertCanMutate($actor, $company, $branch);
        $this->assertParentAuthorized($company, $parentType, $parentId);
        $this->assertFileUsable($file, $company);

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $parentType, $parentId, $file, $documentType, $label, $remark): DocumentAttachment {
            return $this->access->withTenant($actor, $company, $branch, function () use ($company, $branch, $parentType, $parentId, $file, $documentType, $label, $remark): DocumentAttachment {
                $attachment = DocumentAttachment::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch?->id,
                    'parent_type' => $parentType,
                    'parent_id' => $parentId,
                    'stored_file_id' => $file->id,
                    'document_type' => $documentType,
                    'label' => $label,
                    'version' => 1,
                    'supersedes_attachment_id' => null,
                    'counts_toward_storage' => true,
                    'is_current' => true,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    DocumentAttachment::class,
                    $attachment->id,
                    remark: $remark,
                    before: null,
                    after: $attachment->toArray(),
                );

                return $attachment;
            });
        });
    }

    /**
     * Upload a new private file and attach it in one authorized flow.
     */
    public function uploadAndAttach(
        User $actor,
        Company $company,
        ?Branch $branch,
        string $parentType,
        string $parentId,
        UploadedFile $upload,
        ?string $documentType = null,
        ?string $label = null,
        string $remark = 'Upload and attach document',
    ): DocumentAttachment {
        $this->access->assertCanMutate($actor, $company, $branch);
        $this->assertParentAuthorized($company, $parentType, $parentId);

        return $this->access->withTenant($actor, $company, $branch, function () use ($actor, $company, $branch, $parentType, $parentId, $upload, $documentType, $label, $remark): DocumentAttachment {
            $file = $this->files->store($upload);

            if (! $file->isDownloadable()) {
                // Failed/unsafe uploads must never count toward storage.
                throw new FileNotAvailableException('Uploaded file failed scan and cannot be attached.');
            }

            return $this->attachExistingFile(
                $actor,
                $company,
                $branch,
                $parentType,
                $parentId,
                $file,
                $documentType,
                $label,
                $remark,
            );
        });
    }

    /**
     * Replace the current attachment file; cleanup of superseded object only after success.
     */
    public function replace(
        User $actor,
        DocumentAttachment $attachment,
        UploadedFile $upload,
        string $remark = 'Replace document',
    ): DocumentAttachment {
        $company = $attachment->company()->firstOrFail();
        $branch = $attachment->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($attachment->branch_id)->first()
            : null;

        $this->access->assertCanEdit($actor, $company, $branch);
        $this->assertParentAuthorized($company, $attachment->parent_type, $attachment->parent_id);

        return $this->access->withTenant($actor, $company, $branch, function () use ($company, $branch, $attachment, $upload, $remark): DocumentAttachment {
            // Store replacement first; only then mark supersession. Prior file stays for
            // version history and continues counting toward storage (Rules / P3-WS-3).
            $replacementFile = $this->files->store($upload);

            if (! $replacementFile->isDownloadable()) {
                throw new FileNotAvailableException('Replacement file is not available; original attachment retained.');
            }

            return $this->auditedTransaction->run(function () use ($company, $branch, $attachment, $replacementFile, $remark): DocumentAttachment {
                $before = $attachment->toArray();
                $attachment->is_current = false;
                $attachment->save();

                $next = DocumentAttachment::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch !== null ? $branch->id : $attachment->branch_id,
                    'parent_type' => $attachment->parent_type,
                    'parent_id' => $attachment->parent_id,
                    'stored_file_id' => $replacementFile->id,
                    'document_type' => $attachment->document_type,
                    'label' => $attachment->label,
                    'version' => ((int) $attachment->version) + 1,
                    'supersedes_attachment_id' => $attachment->id,
                    'counts_toward_storage' => true,
                    'is_current' => true,
                ]);

                $this->audit->write(
                    AuditAction::Updated,
                    DocumentAttachment::class,
                    $next->id,
                    remark: $remark,
                    before: $before,
                    after: $next->toArray(),
                );

                return $next;
            });
        });
    }

    public function download(User $actor, DocumentAttachment $attachment): StreamedResponse
    {
        $company = $attachment->company()->firstOrFail();
        $branch = $attachment->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($attachment->branch_id)->first()
            : $this->access->resolveActorBranch($actor, $company);

        $this->access->assertCanView($actor, $company, $branch);

        $file = $attachment->storedFile()->firstOrFail();
        if ($file->company_id !== $company->id) {
            throw new DocumentDomainException('Stored file does not belong to attachment company.');
        }

        return $this->access->withTenant($actor, $company, $branch, function () use ($file): StreamedResponse {
            return $this->files->download($file);
        });
    }

    /**
     * Soft-delete attachment metadata; retained soft-deleted parents keep storage accounting.
     */
    public function softDelete(User $actor, DocumentAttachment $attachment, string $remark, bool $retainStorageCount = true): void
    {
        $company = $attachment->company()->firstOrFail();
        $branch = $attachment->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($attachment->branch_id)->first()
            : null;

        $this->access->assertCanDelete($actor, $company, $branch);

        $this->auditedTransaction->run(function () use ($actor, $company, $branch, $attachment, $remark, $retainStorageCount): void {
            $this->access->withTenant($actor, $company, $branch, function () use ($attachment, $remark, $retainStorageCount): void {
                $before = $attachment->toArray();
                $attachment->is_current = false;
                if (! $retainStorageCount) {
                    $attachment->counts_toward_storage = false;
                }
                $attachment->save();
                $attachment->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    DocumentAttachment::class,
                    $attachment->id,
                    remark: $remark,
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @return Collection<int, DocumentAttachment>
     */
    public function listForParent(User $actor, Company $company, Branch $branch, string $parentType, string $parentId, bool $currentOnly = true): Collection
    {
        $this->access->assertCanView($actor, $company, $branch);
        $this->assertParentAuthorized($company, $parentType, $parentId);

        $builder = DocumentAttachment::query()
            ->where('company_id', $company->id)
            ->where('parent_type', $parentType)
            ->where('parent_id', $parentId)
            ->orderByDesc('version');

        if ($currentOnly) {
            $builder->where('is_current', true);
        }

        return $builder->get();
    }

    /**
     * Storage usage foundations for reconciliation (bytes counted by company).
     *
     * @return array{company_id: string, attachment_count: int, file_bytes: int, soft_deleted_retained_bytes: int}
     */
    public function storageUsage(Company $company): array
    {
        $rows = DocumentAttachment::withTrashed()
            ->where('company_id', $company->id)
            ->where('counts_toward_storage', true)
            ->get(['id', 'stored_file_id', 'deleted_at']);

        $fileIds = $rows->pluck('stored_file_id')->unique()->values();
        $bytesByFile = StoredFile::query()
            ->whereIn('id', $fileIds)
            ->pluck('size_bytes', 'id');

        $total = 0;
        $retained = 0;
        foreach ($rows as $row) {
            $size = (int) ($bytesByFile[$row->stored_file_id] ?? 0);
            $total += $size;
            if ($row->deleted_at !== null) {
                $retained += $size;
            }
        }

        return [
            'company_id' => $company->id,
            'attachment_count' => $rows->count(),
            'file_bytes' => $total,
            'soft_deleted_retained_bytes' => $retained,
        ];
    }

    /**
     * Reconcile counted attachment files vs raw stored_files for a company.
     *
     * @return array{company_id: string, counted_bytes: int, raw_available_bytes: int, delta_bytes: int}
     */
    public function reconcileStorage(Company $company): array
    {
        $usage = $this->storageUsage($company);
        $raw = (int) StoredFile::query()
            ->where('company_id', $company->id)
            ->whereNotNull('available_at')
            ->sum('size_bytes');

        return [
            'company_id' => $company->id,
            'counted_bytes' => $usage['file_bytes'],
            'raw_available_bytes' => $raw,
            'delta_bytes' => $raw - $usage['file_bytes'],
        ];
    }

    private function assertFileUsable(StoredFile $file, Company $company): void
    {
        if ($file->company_id !== $company->id) {
            throw new DocumentDomainException('Cannot attach a file from another company.');
        }

        if (! $file->isDownloadable()) {
            throw new FileNotAvailableException('Unscanned or unsafe files cannot be attached or downloaded.');
        }

        // Prevent double-counting the same stored file as multiple current attachments.
        $already = DocumentAttachment::query()
            ->where('stored_file_id', $file->id)
            ->where('counts_toward_storage', true)
            ->exists();

        if ($already) {
            throw new DocumentDomainException('Stored file is already attached and counted toward storage.');
        }
    }

    /**
     * Parent authorization foundation: parent must resolve within company scope.
     * Concrete parent types register lightweight existence checks here.
     */
    private function assertParentAuthorized(Company $company, string $parentType, string $parentId): void
    {
        $exists = match ($parentType) {
            'party' => DB::table('parties')->where('company_id', $company->id)->where('id', $parentId)->whereNull('deleted_at')->exists(),
            'vehicle' => DB::table('vehicles')->where('company_id', $company->id)->where('id', $parentId)->whereNull('deleted_at')->exists(),
            'bank_cash_account' => DB::table('bank_cash_accounts')->where('company_id', $company->id)->where('id', $parentId)->whereNull('deleted_at')->exists(),
            'station' => DB::table('stations')
                ->where('owner_user_id', $company->owner_user_id)
                ->where('id', $parentId)
                ->whereNull('deleted_at')
                ->exists(),
            'lorry_receipt' => DB::table('lorry_receipts')->where('company_id', $company->id)->where('id', $parentId)->whereNull('deleted_at')->exists(),
            'trip' => DB::table('trips')->where('company_id', $company->id)->where('id', $parentId)->whereNull('deleted_at')->exists(),
            // Generic company-scoped placeholder for future modules (invoice).
            'company_record' => $parentId === $company->id,
            default => throw new DocumentDomainException('Unsupported document parent type.'),
        };

        if (! $exists) {
            throw new DocumentDomainException('Parent record not found in company scope.');
        }
    }
}
