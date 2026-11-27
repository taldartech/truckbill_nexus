<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Rbac\InternalAuthorization;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyEnquiryWebhookCredential;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal Super Admin company lifecycle screens (PO-P2-INTERNAL-UI-AUTH).
 * Company is resolved from the route — never from client tenant fields.
 */
class CompanyLifecycleController extends Controller
{
    public function index(Request $request): Response
    {
        app(InternalAuthorization::class)->authorizeAnyOrAbort($request, [
            PermissionCodes::INTERNAL_ONBOARDING_VIEW,
            PermissionCodes::INTERNAL_ONBOARDING_MANAGE,
        ]);

        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', 'all'));

        $query = Company::query()
            ->with(['owner:id,name,email'])
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('pan', 'ilike', '%'.$search.'%')
                    ->orWhereHas('owner', function ($ownerQuery) use ($search): void {
                        $ownerQuery->where('email', 'ilike', '%'.$search.'%')
                            ->orWhere('name', 'ilike', '%'.$search.'%');
                    });
            });
        }

        if ($status === 'active') {
            $query->whereNull('deactivated_at');
        } elseif ($status === 'deactivated') {
            $query->whereNotNull('deactivated_at');
        }

        $companies = $query->paginate(25)->withQueryString();

        return Inertia::render('admin/companies/index', [
            'filters' => [
                'q' => $search,
                'status' => $status === '' ? 'all' : $status,
            ],
            'companies' => [
                'data' => $companies->getCollection()->map(fn (Company $company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'pan' => $company->pan,
                    'is_active' => $company->isActive(),
                    'deactivated_at' => $company->deactivated_at?->toIso8601String(),
                    'owner' => $company->owner === null ? null : [
                        'id' => $company->owner->id,
                        'name' => $company->owner->name,
                        'email' => $company->owner->email,
                    ],
                    'show_url' => route('admin.companies.show', $company),
                ])->values()->all(),
                'links' => $companies->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $companies->currentPage(),
                    'last_page' => $companies->lastPage(),
                    'total' => $companies->total(),
                ],
            ],
            'index_url' => route('admin.companies.index'),
        ]);
    }

    public function show(Request $request, Company $company): Response
    {
        $this->assertSuperAdmin($request);

        $company->load(['owner:id,name,email']);

        $transfers = OwnershipTransfer::query()
            ->where('company_id', $company->id)
            ->with(['previousOwner:id,name,email', 'newOwner:id,name,email', 'initiatedBy:id,name,email'])
            ->orderByDesc('transferred_at')
            ->limit(20)
            ->get();

        $auditEntries = AuditEntry::query()
            ->where('company_id', $company->id)
            ->where('subject_type', Company::class)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $customerCandidates = User::query()
            ->customer()
            ->active()
            ->orderBy('email')
            ->limit(100)
            ->get(['id', 'name', 'email']);

        $branches = Branch::query()
            ->where('company_id', $company->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])
            ->values()
            ->all();

        $webhookCredentials = CompanyEnquiryWebhookCredential::query()
            ->where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return Inertia::render('admin/companies/show', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'pan' => $company->pan,
                'gstin' => $company->gstin,
                'is_active' => $company->isActive(),
                'deactivated_at' => $company->deactivated_at?->toIso8601String(),
                'owner' => $company->owner === null ? null : [
                    'id' => $company->owner->id,
                    'name' => $company->owner->name,
                    'email' => $company->owner->email,
                ],
            ],
            'customer_candidates' => $customerCandidates->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->all(),
            'transfers' => $transfers->map(fn (OwnershipTransfer $transfer): array => [
                'id' => $transfer->id,
                'previous_owner' => $transfer->previousOwner?->email,
                'new_owner' => $transfer->newOwner?->email,
                'initiated_by' => $transfer->initiatedBy?->email,
                'remark' => $transfer->remark,
                'transferred_at' => $transfer->transferred_at->toIso8601String(),
            ])->values()->all(),
            'audit_entries' => $auditEntries->map(fn (AuditEntry $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'remark' => $entry->remark,
                'created_at' => $entry->created_at->toIso8601String(),
            ])->values()->all(),
            'branches' => $branches,
            'enquiries_entitled' => app(CompanyCapabilityResolver::class)
                ->allowsRead($company, CompanyCapabilities::ENQUIRIES),
            'enquiry_webhook_credentials' => $webhookCredentials->map(fn (CompanyEnquiryWebhookCredential $row): array => [
                'id' => $row->id,
                'label' => $row->label,
                'token_prefix' => $row->token_prefix,
                'receiving_branch_id' => $row->receiving_branch_id,
                'last_used_at' => $row->last_used_at?->toIso8601String(),
                'revoked_at' => $row->revoked_at?->toIso8601String(),
                'is_usable' => $row->isUsable(),
                'rotate_url' => route('admin.companies.enquiry-webhook-credentials.rotate', [$company, $row]),
                'revoke_url' => route('admin.companies.enquiry-webhook-credentials.revoke', [$company, $row]),
                'update_url' => route('admin.companies.enquiry-webhook-credentials.update', [$company, $row]),
            ])->values()->all(),
            'urls' => [
                'index' => route('admin.companies.index'),
                'deactivate' => route('admin.companies.deactivate', $company),
                'reactivate' => route('admin.companies.reactivate', $company),
                'transfer' => route('admin.companies.ownership-transfers.store', $company),
                'enquiry_webhook_store' => route('admin.companies.enquiry-webhook-credentials.store', $company),
            ],
        ]);
    }

    public function deactivate(
        Request $request,
        Company $company,
        CompanyWorkspaceService $workspace,
    ): RedirectResponse {
        $this->assertSuperAdmin($request);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $workspace->deactivateCompany($actor, $company, $validated['remark']);
        } catch (CompanyDomainException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.companies.show', $company)
            ->with('success', 'Company deactivated.');
    }

    public function reactivate(
        Request $request,
        Company $company,
        CompanyWorkspaceService $workspace,
    ): RedirectResponse {
        $this->assertSuperAdmin($request);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $workspace->reactivateCompany($actor, $company, $validated['remark']);
        } catch (CompanyDomainException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.companies.show', $company)
            ->with('success', 'Company reactivated.');
    }

    private function assertSuperAdmin(Request $request): User
    {
        return app(InternalAuthorization::class)->authorizeAnyOrAbort($request, [
            PermissionCodes::INTERNAL_ONBOARDING_MANAGE,
        ]);
    }
}
