<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Company;
use App\Support\Audit\AuditEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only company audit viewer for Owners (append-only AuditEntry store).
 */
class OwnerAuditController extends Controller
{
    use AuthorizesOwnerPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
    ) {}

    public function __invoke(Request $request, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $action = trim((string) $request->query('action', ''));
        $subjectType = trim((string) $request->query('subject_type', ''));

        $query = AuditEntry::query()
            ->where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($action !== '') {
            $query->where('action', $action);
        }

        if ($subjectType !== '') {
            $query->where('subject_type', $subjectType);
        }

        $entries = $query->paginate(25)->withQueryString();

        $availableActions = AuditEntry::query()
            ->where('company_id', $company->id)
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $availableSubjects = AuditEntry::query()
            ->where('company_id', $company->id)
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type');

        return Inertia::render('owner/audit', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'filters' => [
                'action' => $action,
                'subject_type' => $subjectType,
            ],
            'filterOptions' => [
                'actions' => $availableActions->values(),
                'subject_types' => $availableSubjects->values(),
            ],
            'entries' => [
                'data' => $entries->getCollection()->map(function (AuditEntry $entry): array {
                    return [
                        'id' => $entry->id,
                        'uuid' => $entry->uuid,
                        'action' => $entry->action,
                        'subject_type' => class_basename($entry->subject_type),
                        'subject_id' => $entry->subject_id,
                        'remark' => $entry->remark,
                        'actor_type' => $entry->actor_type,
                        'actor_id' => $entry->actor_id,
                        'is_owner_scope' => $entry->is_owner_scope,
                        'created_at' => $entry->created_at->toIso8601String(),
                    ];
                })->values(),
                'links' => $entries->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $entries->currentPage(),
                    'last_page' => $entries->lastPage(),
                    'total' => $entries->total(),
                ],
            ],
            'links' => [
                'dashboard' => route('owner.home'),
                'index' => route('owner.audit'),
            ],
        ]);
    }
}
