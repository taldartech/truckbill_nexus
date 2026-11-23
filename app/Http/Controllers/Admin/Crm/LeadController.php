<?php

namespace App\Http\Controllers\Admin\Crm;

use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Domains\Crm\LeadService;
use App\Http\Controllers\Controller;
use App\Models\Crm\Lead;
use App\Models\InternalRoleAssignment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(
        private readonly LeadService $leads = new LeadService,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->assertInternal($request);

        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', 'all'));

        $query = Lead::query()
            ->with(['assignee:id,name,email'])
            ->orderByDesc('created_at');

        $isAdmin = $actor->isSuperAdmin()
            || InternalRoleAssignment::query()
                ->where('user_id', $actor->id)
                ->whereNull('deactivated_at')
                ->whereHas('role', fn ($q) => $q->where('slug', 'internal.admin'))
                ->exists();

        if (! $isAdmin) {
            $query->where('assigned_to_user_id', $actor->id);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('contact_name', 'ilike', '%'.$search.'%')
                    ->orWhere('email', 'ilike', '%'.$search.'%')
                    ->orWhere('company_name', 'ilike', '%'.$search.'%');
            });
        }

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $leads = $query->paginate(25)->withQueryString();

        return Inertia::render('admin/crm/leads/index', [
            'filters' => [
                'q' => $search,
                'status' => $status === '' ? 'all' : $status,
            ],
            'statuses' => array_map(fn (LeadStatus $s): string => $s->value, LeadStatus::cases()),
            'leads' => [
                'data' => $leads->getCollection()->map(fn (Lead $lead): array => [
                    'id' => $lead->id,
                    'contact_name' => $lead->contact_name,
                    'email' => $lead->email,
                    'company_name' => $lead->company_name,
                    'status' => $lead->status->value,
                    'source' => $lead->source->value,
                    'interested_product' => $lead->interested_product->value,
                    'assignee' => $lead->assignee?->email,
                    'show_url' => route('admin.crm.leads.show', $lead),
                ])->values()->all(),
                'meta' => [
                    'current_page' => $leads->currentPage(),
                    'last_page' => $leads->lastPage(),
                    'total' => $leads->total(),
                ],
            ],
            'urls' => [
                'index' => route('admin.crm.leads.index'),
                'create' => route('admin.crm.leads.create'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->assertInternal($request);

        return Inertia::render('admin/crm/leads/create', [
            'sources' => array_map(fn (LeadSource $s): string => $s->value, LeadSource::cases()),
            'products' => array_map(fn (InterestedProduct $p): string => $p->value, InterestedProduct::cases()),
            'store_url' => route('admin.crm.leads.store'),
            'index_url' => route('admin.crm.leads.index'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        $validated = $request->validate([
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'source' => ['required', Rule::enum(LeadSource::class)],
            'interested_product' => ['required', Rule::enum(InterestedProduct::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'duplicate_override' => ['sometimes', 'boolean'],
            'duplicate_override_remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $lead = $this->leads->create($actor, $validated);
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.leads.show', $lead)
            ->with('success', 'Lead created.');
    }

    public function show(Request $request, Lead $lead): Response
    {
        $this->assertInternal($request);

        $lead->load(['assignee:id,name,email', 'activities' => fn ($q) => $q->orderByDesc('created_at')->limit(50)]);

        return Inertia::render('admin/crm/leads/show', [
            'lead' => [
                'id' => $lead->id,
                'contact_name' => $lead->contact_name,
                'email' => $lead->email,
                'mobile' => $lead->mobile,
                'company_name' => $lead->company_name,
                'status' => $lead->status->value,
                'source' => $lead->source->value,
                'interested_product' => $lead->interested_product->value,
                'remarks' => $lead->remarks,
                'assignee' => $lead->assignee === null ? null : [
                    'id' => $lead->assignee->id,
                    'name' => $lead->assignee->name,
                    'email' => $lead->assignee->email,
                ],
                'allowed_transitions' => array_map(
                    fn (LeadStatus $s): string => $s->value,
                    $lead->status->allowedTransitions(),
                ),
            ],
            'activities' => $lead->activities->map(fn ($activity): array => [
                'id' => $activity->id,
                'activity_type' => $activity->activity_type,
                'body' => $activity->body,
                'created_at' => $activity->created_at?->toIso8601String(),
            ])->values()->all(),
            'urls' => [
                'index' => route('admin.crm.leads.index'),
                'transition' => route('admin.crm.leads.transition', $lead),
            ],
        ]);
    }

    public function transition(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(LeadStatus::class)],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->leads->transition(
                $actor,
                $lead,
                LeadStatus::from($validated['status']),
                (string) ($validated['remark'] ?? ''),
            );
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.leads.show', $lead)
            ->with('success', 'Lead status updated.');
    }

    private function assertInternal(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->isInternal() || ! $actor->isActive()) {
            abort(403, 'Internal CRM access required.');
        }

        return $actor;
    }
}
