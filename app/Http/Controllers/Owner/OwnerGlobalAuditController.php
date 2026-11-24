<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\OwnerGlobalPanelService;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cross-company audit feed for Customer Owners (read-only aggregation).
 */
class OwnerGlobalAuditController extends Controller
{
    public function __construct(
        private readonly OwnerGlobalPanelService $panel,
    ) {}

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->panel->assertOwnsAtLeastOneCompany($user);

        $companyId = $request->query('company');
        $companyId = is_string($companyId) ? $companyId : null;

        $paginator = $this->panel->paginateOwnedAuditEntries($user, $companyId);
        $companies = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->get(['id', 'name']);
        $names = $this->panel->companyNameMap($companies);

        return Inertia::render('owner/audit/all', [
            'filters' => [
                'company' => $companyId ?? '',
            ],
            'companyOptions' => $this->panel->ownedCompanyFilterOptions($user),
            'entries' => [
                'data' => $paginator->getCollection()->map(function (AuditEntry $entry) use ($names): array {
                    return [
                        'id' => $entry->id,
                        'uuid' => $entry->uuid,
                        'action' => $entry->action,
                        'subject_type' => class_basename($entry->subject_type),
                        'subject_id' => $entry->subject_id,
                        'remark' => $entry->remark,
                        'created_at' => $entry->created_at->toIso8601String(),
                        'company' => [
                            'id' => $entry->company_id,
                            'name' => $names[$entry->company_id] ?? '—',
                        ],
                        'company_audit_url' => route('owner.companies.index'),
                    ];
                })->values(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'links' => [
                'index' => route('owner.audit.all'),
                'companies' => route('owner.companies.index'),
            ],
        ]);
    }
}
