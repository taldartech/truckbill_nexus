<?php

namespace App\Http\Controllers\Admin\Crm;

use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Domains\Crm\LeadService;
use App\Domains\Crm\Onboarding\LeadOnboardingService;
use App\Http\Controllers\Controller;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\LeadFollowUp;
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
        private readonly LeadOnboardingService $onboarding = new LeadOnboardingService,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->assertInternal($request);

        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', 'all'));

        $query = Lead::query()
            ->with(['assignee:id,name,email'])
            ->orderByDesc('created_at');

        if (! $this->isAdmin($actor)) {
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
                'dashboard' => route('admin.crm.dashboard'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->assertInternal($request);

        return Inertia::render('admin/crm/leads/create', [
            'sources' => array_map(fn (LeadSource $s): string => $s->value, LeadSource::cases()),
            'products' => array_map(fn (InterestedProduct $p): string => $p->value, InterestedProduct::cases()),
            'assignable_users' => $this->isAdmin($actor)
                ? $this->leads->assignableInternalUsers()->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])->values()->all()
                : [],
            'can_assign' => $this->isAdmin($actor),
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
        $actor = $this->assertInternal($request);

        $lead->load([
            'assignee:id,name,email',
            'activities' => fn ($q) => $q->with('actor:id,name,email')->orderByDesc('created_at')->limit(50),
            'followUps' => fn ($q) => $q->with('assignee:id,name,email')->orderByDesc('due_at'),
            'convertedCompany:id,name',
        ]);

        $statusTransitions = array_values(array_filter(
            $lead->status->allowedTransitions(),
            fn (LeadStatus $status): bool => $status !== LeadStatus::Converted,
        ));

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
                    $statusTransitions,
                ),
                'converted_company' => $lead->convertedCompany === null ? null : [
                    'id' => $lead->convertedCompany->id,
                    'name' => $lead->convertedCompany->name,
                ],
                'can_invite' => $lead->status->canReceiveOnboardingInvite() && filled($lead->email),
            ],
            'follow_ups' => $lead->followUps->map(fn (LeadFollowUp $followUp): array => [
                'id' => $followUp->id,
                'due_at' => $followUp->due_at->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
                'notes' => $followUp->notes,
                'completed_at' => $followUp->completed_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
                'completion_notes' => $followUp->completion_notes,
                'assignee' => $followUp->assignee?->email,
                'is_overdue' => $followUp->completed_at === null && $followUp->due_at->isPast(),
                'complete_url' => $followUp->completed_at === null
                    ? route('admin.crm.leads.follow-ups.complete', [$lead, $followUp])
                    : null,
            ])->values()->all(),
            'activities' => $lead->activities->map(fn (LeadActivity $activity): array => [
                'id' => $activity->id,
                'activity_type' => $activity->activity_type,
                'body' => $activity->body,
                'is_note' => $activity->activity_type === 'note',
                'actor' => $activity->actor !== null
                    ? ($activity->actor->name !== '' ? $activity->actor->name : $activity->actor->email)
                    : null,
                'created_at' => $activity->created_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
            ])->values()->all(),
            'assignable_users' => $this->isAdmin($actor)
                ? $this->leads->assignableInternalUsers()->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])->values()->all()
                : [],
            'can_assign' => $this->isAdmin($actor) && ! $lead->status->isTerminal(),
            'onboarding' => $this->onboardingSummary($lead),
            'urls' => [
                'index' => route('admin.crm.leads.index'),
                'transition' => route('admin.crm.leads.transition', $lead),
                'assign' => route('admin.crm.leads.assign', $lead),
                'follow_up' => route('admin.crm.leads.follow-ups.store', $lead),
                'note' => route('admin.crm.leads.notes.store', $lead),
                'invite' => route('admin.crm.leads.invite', $lead),
                'invite_resend' => route('admin.crm.leads.invite.resend', $lead),
                'invite_revoke' => route('admin.crm.leads.invite.revoke', $lead),
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

    public function assign(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        $validated = $request->validate([
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        $assignee = User::query()->whereKey($validated['assigned_to_user_id'])->firstOrFail();

        try {
            $this->leads->assign($actor, $lead, $assignee, $validated['remark']);
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['assigned_to_user_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.leads.show', $lead)
            ->with('success', 'Lead assigned.');
    }

    public function storeFollowUp(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        $validated = $request->validate([
            'due_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->leads->scheduleFollowUp($actor, $lead, $validated);
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['due_at' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.leads.show', $lead)
            ->with('success', 'Follow-up scheduled.');
    }

    public function completeFollowUp(Request $request, Lead $lead, LeadFollowUp $followUp): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        if ($followUp->lead_id !== $lead->id) {
            abort(404);
        }

        $validated = $request->validate([
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->leads->completeFollowUp($actor, $followUp, (string) ($validated['remark'] ?? ''));
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.leads.show', $lead)
            ->with('success', 'Follow-up completed.');
    }

    public function storeNote(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $this->leads->addNote($actor, $lead, $validated['body']);
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['body' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.leads.show', $lead)
            ->with('success', 'Note added.');
    }

    public function invite(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        try {
            $result = $this->onboarding->sendInvite($actor, $lead);
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['invite' => $e->getMessage()]);
        }

        $message = 'Onboarding invitation '.$result['invitation']->delivery_status.'.';
        if (in_array(config('mail.default'), ['array', 'log'], true)) {
            $message .= ' Test link: '.$result['accept_url'];
        }

        return redirect()
            ->route('admin.crm.leads.show', $lead)
            ->with('success', $message)
            ->with('onboarding_accept_url', $result['accept_url']);
    }

    public function resendInvite(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        try {
            $result = $this->onboarding->resendInvite($actor, $lead);
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['invite' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.leads.show', $lead)
            ->with('success', 'Onboarding invitation resent.')
            ->with('onboarding_accept_url', $result['accept_url']);
    }

    public function revokeInvite(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->onboarding->revokeInvite($actor, $lead, $validated['remark']);
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.leads.show', $lead)
            ->with('success', 'Onboarding invitation revoked.');
    }

    /**
     * @return array<string, mixed>
     */
    private function onboardingSummary(Lead $lead): array
    {
        $invitation = $this->onboarding->latestInvitation($lead);
        if ($invitation === null) {
            return [
                'status' => 'not_invited',
                'recipient' => $lead->email,
                'can_send' => $lead->status->canReceiveOnboardingInvite() && filled($lead->email),
                'can_resend' => false,
                'can_revoke' => false,
                'accept_url' => null,
            ];
        }

        return [
            'status' => $invitation->statusLabel(),
            'recipient' => $invitation->email,
            'sent_at' => $invitation->sent_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
            'expires_at' => $invitation->expires_at->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
            'completed_at' => $invitation->used_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
            'delivery_status' => $invitation->delivery_status,
            'completed_user_id' => $invitation->completed_user_id,
            'can_send' => ! $invitation->isActive() && $lead->status->canReceiveOnboardingInvite() && filled($lead->email),
            'can_resend' => $invitation->isActive() || $invitation->statusLabel() === 'expired',
            'can_revoke' => $invitation->isActive(),
            'accept_url' => null,
        ];
    }

    private function assertInternal(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->isInternal() || ! $actor->isActive()) {
            abort(403, 'Internal CRM access required.');
        }

        return $actor;
    }

    private function isAdmin(User $actor): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return InternalRoleAssignment::query()
            ->where('user_id', $actor->id)
            ->whereNull('deactivated_at')
            ->whereHas('role', fn ($q) => $q->where('slug', 'internal.admin'))
            ->exists();
    }
}
