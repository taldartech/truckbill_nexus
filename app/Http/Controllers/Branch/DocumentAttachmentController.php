<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Document\DocumentParentAccess;
use App\Domains\Document\DocumentParentTypes;
use App\Domains\Document\DocumentTypeCatalog;
use App\Domains\Document\DocumentValidity;
use App\Domains\Document\Exceptions\DocumentDomainException;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentAttachment;
use App\Models\User;
use App\Support\Files\Exceptions\FileNotAvailableException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentAttachmentController extends Controller
{
    use AuthorizesBranchPanel;
    use PresentsDocumentDrawer;

    public const MAX_UPLOAD_BYTES = 2_097_152;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly DocumentAttachmentService $documents,
        private readonly DocumentParentAccess $parentAccess = new DocumentParentAccess,
    ) {}

    public function index(Request $request, string $parentType, string $parentId, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParentType($parentType);
        $permissions = $this->parentAccess->permissionFlags($actor, $company, $branch, $parentType);

        try {
            $attachments = $this->documents->listForParent(
                $actor,
                $company,
                $branch,
                $parentType,
                $parentId,
                currentOnly: false,
            );
            $usage = $this->documents->storageUsage($company);
        } catch (MasterDomainException|DocumentDomainException $e) {
            abort(403, $e->getMessage());
        }

        $attachments->load('storedFile');
        $context = $this->documents->contextFor($company, $parentType, $parentId);

        return Inertia::render('branch/masters/documents/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'parent' => [
                'type' => $parentType,
                'id' => $parentId,
            ],
            ...$this->documentContextPayload($context),
            'storage' => $usage,
            'max_upload_bytes' => self::MAX_UPLOAD_BYTES,
            'attachments' => $attachments->map(fn (DocumentAttachment $attachment): array => $this->presentDocumentAttachment($attachment, $company, $branch, $context))->values()->all(),
            'links' => [
                'hub' => route('branch.masters.index'),
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
        ]);
    }

    public function store(Request $request, string $parentType, string $parentId, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParentType($parentType);

        try {
            $this->documents->assertCanAttach($actor, $company, $branch, $parentType, $parentId);
        } catch (MasterDomainException|DocumentDomainException $e) {
            abort(403, $e->getMessage());
        }

        $context = $this->documents->contextFor($company, $parentType, $parentId);
        $requestedType = $request->input('document_type');
        $requestedType = is_string($requestedType) ? $requestedType : null;

        $expiresOnRules = [Rule::requiredIf(DocumentTypeCatalog::requiresExpiry($requestedType)), 'nullable', 'date'];
        if ($request->filled('issued_on')) {
            $expiresOnRules[] = 'after_or_equal:issued_on';
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png,webp'],
            'document_type' => $context->allowedTypes === null
                ? ['nullable', 'string', 'max:64']
                : ['required', 'string', Rule::in($context->allowedTypes)],
            'label' => ['nullable', 'string', 'max:128'],
            'document_number' => ['nullable', 'string', 'max:64'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => $expiresOnRules,
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->documents->uploadAndAttach(
                $actor,
                $company,
                $branch,
                $parentType,
                $parentId,
                $validated['file'],
                $validated['document_type'] ?? null,
                $validated['label'] ?? null,
                $validated['remark'] ?? 'Upload and attach document',
                new DocumentValidity(
                    documentNumber: $validated['document_number'] ?? null,
                    issuedOn: isset($validated['issued_on']) ? CarbonImmutable::parse($validated['issued_on']) : null,
                    expiresOn: isset($validated['expires_on']) ? CarbonImmutable::parse($validated['expires_on']) : null,
                ),
            );
        } catch (MasterDomainException|DocumentDomainException|FileNotAvailableException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'Document uploaded.');
    }

    public function replace(Request $request, DocumentAttachment $attachment, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAttachment($company, $attachment);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png,webp'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->documents->replace(
                $actor,
                $attachment,
                $validated['file'],
                $validated['remark'] ?? 'Replace document',
            );
        } catch (MasterDomainException|DocumentDomainException|FileNotAvailableException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'Document replaced.');
    }

    public function download(Request $request, DocumentAttachment $attachment, Company $company, Branch $branch): StreamedResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAttachment($company, $attachment);

        try {
            return $this->documents->download($actor, $attachment);
        } catch (MasterDomainException|DocumentDomainException|FileNotAvailableException $e) {
            abort(403, $e->getMessage());
        }
    }

    public function destroy(Request $request, DocumentAttachment $attachment, Company $company, Branch $branch): RedirectResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAttachment($company, $attachment);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->documents->softDelete($actor, $attachment, $validated['remark']);
        } catch (MasterDomainException|DocumentDomainException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'Document deleted.');
    }

    private function assertParentType(string $parentType): void
    {
        if (! DocumentParentTypes::isSupported($parentType)) {
            abort(404);
        }
    }

    private function assertAttachment(Company $company, DocumentAttachment $attachment): void
    {
        if ($attachment->company_id !== $company->id) {
            abort(404);
        }
    }
}
