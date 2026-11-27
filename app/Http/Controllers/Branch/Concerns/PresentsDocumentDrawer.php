<?php

namespace App\Http\Controllers\Branch\Concerns;

use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Document\DocumentContext;
use App\Domains\Document\DocumentParentTypes;
use App\Domains\Document\DocumentTypeCatalog;
use App\Domains\Document\Enums\DocumentExpiryStatus;
use App\Domains\Document\Exceptions\DocumentDomainException;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Http\Controllers\Branch\DocumentAttachmentController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;

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
        if (! in_array($parentType, DocumentParentTypes::drawerParents(), true)) {
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
        $context = $documents->contextFor($company, $parentType, $parentId);

        return [
            'parent' => [
                'type' => $parentType,
                'id' => $parentId,
            ],
            ...$this->documentContextPayload($context),
            'storage' => $usage,
            'max_upload_bytes' => DocumentAttachmentController::MAX_UPLOAD_BYTES,
            'attachments' => $attachments->map(fn (DocumentAttachment $attachment): array => $this->presentDocumentAttachment($attachment, $company, $branch, $context))->values()->all(),
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
            ],
        ];
    }

    /**
     * @return array{title: string, retired: bool, sections: list<array{key: string, title: string}>, document_types: list<array{value: string, label: string, requires_expiry: bool, tracks_validity: bool}>}
     */
    protected function documentContextPayload(DocumentContext $context): array
    {
        return [
            'title' => $context->title,
            'retired' => $context->retired,
            'sections' => $context->presentSections(),
            'document_types' => $context->presentTypeOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentDocumentAttachment(DocumentAttachment $attachment, Company $company, Branch $branch, DocumentContext $context): array
    {
        $file = $attachment->storedFile;
        $expiryStatus = DocumentExpiryStatus::forExpiry($attachment->expires_on, CarbonImmutable::today());

        return [
            'id' => $attachment->id,
            'document_type' => $attachment->document_type,
            'document_type_label' => DocumentTypeCatalog::label($attachment->document_type),
            'section' => $context->sectionFor($attachment->document_type),
            'label' => $attachment->label,
            'document_number' => $attachment->document_number,
            'issued_on' => $attachment->issued_on?->toDateString(),
            'expires_on' => $attachment->expires_on?->toDateString(),
            'expiry_status' => $expiryStatus === null ? null : [
                'value' => $expiryStatus->value,
                'label' => $expiryStatus->label(),
            ],
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
            'replace_url' => $context->retired ? null : route('branch.documents.replace', [
                'company' => $company,
                'branch' => $branch,
                'attachment' => $attachment->id,
            ]),
            'destroy_url' => $context->retired ? null : route('branch.documents.destroy', [
                'company' => $company,
                'branch' => $branch,
                'attachment' => $attachment->id,
            ]),
        ];
    }
}
