<?php

namespace App\Http\Controllers\Branch\Concerns;

use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Document\Exceptions\DocumentDomainException;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Http\Controllers\Branch\DocumentAttachmentController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentAttachment;
use App\Models\User;

trait PresentsDocumentDrawer
{
    /**
     * @param  array{can_view?: bool, can_create: bool, can_edit: bool, can_delete: bool}  $permissions
     * @return array<string, mixed>|null
     */
    protected function documentDrawerPayload(
        User $actor,
        Company $company,
        Branch $branch,
        string $parentType,
        string $parentId,
        DocumentAttachmentService $documents,
        array $permissions,
    ): ?array {
        if (! in_array($parentType, ['party', 'vehicle', 'bank_cash_account', 'station'], true)) {
            return null;
        }

        try {
            $attachments = $documents->listForParent(
                $actor,
                $company,
                $branch,
                $parentType,
                $parentId,
                currentOnly: false,
            );
            $usage = $documents->storageUsage($company);
        } catch (MasterDomainException|DocumentDomainException) {
            return null;
        }

        $attachments->load('storedFile');

        return [
            'parent' => [
                'type' => $parentType,
                'id' => $parentId,
            ],
            'storage' => $usage,
            'max_upload_bytes' => DocumentAttachmentController::MAX_UPLOAD_BYTES,
            'attachments' => $attachments->map(fn (DocumentAttachment $attachment): array => $this->presentDocumentAttachment($attachment, $company, $branch))->values()->all(),
            'permissions' => [
                'can_create' => (bool) $permissions['can_create'],
                'can_edit' => (bool) $permissions['can_edit'],
                'can_delete' => (bool) $permissions['can_delete'],
            ],
            'links' => [
                'manager' => route('branch.documents.index', [
                    'company' => $company,
                    'branch' => $branch,
                    'parentType' => $parentType,
                    'parentId' => $parentId,
                ]),
                'upload' => route('branch.documents.store', [
                    'company' => $company,
                    'branch' => $branch,
                    'parentType' => $parentType,
                    'parentId' => $parentId,
                ]),
                'replace_template' => route('branch.documents.replace', [
                    'company' => $company,
                    'branch' => $branch,
                    'attachment' => '__ATTACHMENT__',
                ]),
                'destroy_template' => route('branch.documents.destroy', [
                    'company' => $company,
                    'branch' => $branch,
                    'attachment' => '__ATTACHMENT__',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentDocumentAttachment(DocumentAttachment $attachment, Company $company, Branch $branch): array
    {
        $file = $attachment->storedFile;

        return [
            'id' => $attachment->id,
            'document_type' => $attachment->document_type,
            'label' => $attachment->label,
            'version' => $attachment->version,
            'is_current' => (bool) $attachment->is_current,
            'counts_toward_storage' => (bool) $attachment->counts_toward_storage,
            'supersedes_attachment_id' => $attachment->supersedes_attachment_id,
            'created_at' => $attachment->created_at?->toIso8601String(),
            'file' => $file === null ? null : [
                'id' => $file->id,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'scan_status' => $file->scan_status,
                'is_downloadable' => $file->isDownloadable(),
                'available_at' => $file->available_at?->toIso8601String(),
            ],
            'download_url' => $file !== null && $file->isDownloadable()
                ? route('branch.documents.download', [
                    'company' => $company,
                    'branch' => $branch,
                    'attachment' => $attachment->id,
                ])
                : null,
            'replace_url' => route('branch.documents.replace', [
                'company' => $company,
                'branch' => $branch,
                'attachment' => $attachment->id,
            ]),
            'destroy_url' => route('branch.documents.destroy', [
                'company' => $company,
                'branch' => $branch,
                'attachment' => $attachment->id,
            ]),
        ];
    }
}
