<?php

namespace App\Http\Controllers\Admin\Crm;

use App\Domains\Crm\Enums\SupportTicketPriority;
use App\Domains\Crm\Enums\SupportTicketStatus;
use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Domains\Crm\LeadService;
use App\Domains\Crm\SupportTicketService;
use App\Domains\Identity\Enums\IdentityType;
use App\Http\Controllers\Controller;
use App\Models\Crm\SupportTicket;
use App\Models\Crm\SupportTicketActivity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $tickets = new SupportTicketService,
        private readonly LeadService $leads = new LeadService,
    ) {}

    public function index(Request $request): Response
    {
        $this->assertInternal($request);

        $status = trim((string) $request->query('status', 'all'));
        $query = SupportTicket::query()
            ->with(['assignee:id,name,email'])
            ->orderByDesc('created_at');

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $tickets = $query->paginate(25)->withQueryString();

        return Inertia::render('admin/crm/tickets/index', [
            'filters' => ['status' => $status === '' ? 'all' : $status],
            'statuses' => array_map(fn (SupportTicketStatus $s): string => $s->value, SupportTicketStatus::cases()),
            'tickets' => [
                'data' => $tickets->getCollection()->map(fn (SupportTicket $ticket): array => [
                    'id' => $ticket->id,
                    'subject' => $ticket->subject,
                    'category' => $ticket->category,
                    'priority' => $ticket->priority->value,
                    'status' => $ticket->status->value,
                    'assignee' => $ticket->assignee?->email,
                    'due_at' => $ticket->due_at?->toIso8601String(),
                    'is_overdue' => $ticket->due_at !== null
                        && $ticket->due_at->isPast()
                        && ! in_array($ticket->status, [SupportTicketStatus::Resolved, SupportTicketStatus::Closed], true),
                    'show_url' => route('admin.crm.tickets.show', $ticket),
                ])->values()->all(),
                'meta' => [
                    'current_page' => $tickets->currentPage(),
                    'last_page' => $tickets->lastPage(),
                    'total' => $tickets->total(),
                ],
            ],
            'urls' => [
                'index' => route('admin.crm.tickets.index'),
                'create' => route('admin.crm.tickets.create'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->assertInternal($request);

        return Inertia::render('admin/crm/tickets/create', [
            'priorities' => array_map(fn (SupportTicketPriority $p): string => $p->value, SupportTicketPriority::cases()),
            'assignable_users' => $this->leads->assignableInternalUsers()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->all(),
            'store_url' => route('admin.crm.tickets.store'),
            'index_url' => route('admin.crm.tickets.index'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', 'string', 'max:64'],
            'priority' => ['required', Rule::enum(SupportTicketPriority::class)],
            'due_at' => ['nullable', 'date'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $ticket = $this->tickets->create($actor, $validated);
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['subject' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.tickets.show', $ticket)
            ->with('success', 'Support ticket created.');
    }

    public function show(Request $request, SupportTicket $ticket): Response
    {
        $this->assertInternal($request);
        $ticket->load(['assignee:id,name,email', 'activities' => fn ($q) => $q->orderByDesc('created_at')->limit(50)]);

        return Inertia::render('admin/crm/tickets/show', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'category' => $ticket->category,
                'priority' => $ticket->priority->value,
                'status' => $ticket->status->value,
                'assignee' => $ticket->assignee?->email,
                'due_at' => $ticket->due_at?->timezone('Asia/Kolkata')->format('d-m-Y H:i'),
                'allowed_transitions' => array_map(
                    fn (SupportTicketStatus $s): string => $s->value,
                    $ticket->status->allowedTransitions(),
                ),
                'can_assign' => $ticket->status !== SupportTicketStatus::Closed,
            ],
            'assignable_users' => $this->leads->assignableInternalUsers()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->all(),
            'activities' => $ticket->activities->map(fn (SupportTicketActivity $activity): array => [
                'id' => $activity->id,
                'activity_type' => $activity->activity_type,
                'body' => $activity->body,
                'created_at' => $activity->created_at?->toIso8601String(),
            ])->values()->all(),
            'urls' => [
                'index' => route('admin.crm.tickets.index'),
                'dashboard' => route('admin.crm.dashboard'),
                'transition' => route('admin.crm.tickets.transition', $ticket),
                'assign' => route('admin.crm.tickets.assign', $ticket),
            ],
        ]);
    }

    public function transition(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(SupportTicketStatus::class)],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->tickets->transition(
                $actor,
                $ticket,
                SupportTicketStatus::from($validated['status']),
                (string) ($validated['remark'] ?? ''),
            );
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.tickets.show', $ticket)
            ->with('success', 'Ticket status updated.');
    }

    public function assign(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $actor = $this->assertInternal($request);

        $validated = $request->validate([
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        $assignee = User::query()
            ->whereKey($validated['assigned_to_user_id'])
            ->where('identity_type', IdentityType::Internal->value)
            ->firstOrFail();

        try {
            $this->tickets->assign($actor, $ticket, $assignee, $validated['remark']);
        } catch (CrmDomainException $e) {
            throw ValidationException::withMessages(['assigned_to_user_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.crm.tickets.show', $ticket)
            ->with('success', 'Ticket assigned.');
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
