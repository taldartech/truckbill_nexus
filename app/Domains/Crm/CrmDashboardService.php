<?php

namespace App\Domains\Crm;

use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Enums\SupportTicketStatus;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadFollowUp;
use App\Models\Crm\SupportTicket;
use App\Models\InternalRoleAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Role-scoped CRM metrics from live tables (no fabricated KPIs).
 */
final class CrmDashboardService
{
    /**
     * @return array{
     *     scope: string,
     *     leads_total: int,
     *     leads_by_status: array<string, int>,
     *     pending_follow_ups: int,
     *     overdue_follow_ups: int,
     *     tickets_open: int,
     *     tickets_overdue: int,
     *     recent_leads: list<array{id: string, contact_name: string, status: string, created_at: string|null}>,
     *     recent_tickets: list<array{id: string, subject: string, status: string, created_at: string|null}>
     * }
     */
    public function metrics(User $actor): array
    {
        $isAdmin = $actor->isSuperAdmin() || $this->isInternalAdmin($actor);

        $leadQuery = Lead::query();
        $followUpQuery = LeadFollowUp::query()->whereNull('completed_at');
        $ticketQuery = SupportTicket::query();

        if (! $isAdmin) {
            $leadQuery->where('assigned_to_user_id', $actor->id);
            $followUpQuery->where('assigned_to_user_id', $actor->id);
            $ticketQuery->where('assigned_to_user_id', $actor->id);
        }

        $leadsByStatus = [];
        foreach (LeadStatus::cases() as $status) {
            $leadsByStatus[$status->value] = (clone $leadQuery)->where('status', $status->value)->count();
        }

        $now = Carbon::now();
        $pendingFollowUps = (clone $followUpQuery)->count();
        $overdueFollowUps = (clone $followUpQuery)->where('due_at', '<', $now)->count();

        $openStatuses = [
            SupportTicketStatus::Open->value,
            SupportTicketStatus::InProgress->value,
            SupportTicketStatus::OnHold->value,
        ];
        $ticketsOpen = (clone $ticketQuery)->whereIn('status', $openStatuses)->count();
        $ticketsOverdue = (clone $ticketQuery)
            ->whereIn('status', $openStatuses)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->count();

        $recentLeads = (clone $leadQuery)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'contact_name', 'status', 'created_at'])
            ->map(fn (Lead $lead): array => [
                'id' => $lead->id,
                'contact_name' => $lead->contact_name,
                'status' => $lead->status->value,
                'created_at' => $lead->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $recentTickets = (clone $ticketQuery)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'subject', 'status', 'created_at'])
            ->map(fn (SupportTicket $ticket): array => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status->value,
                'created_at' => $ticket->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'scope' => $isAdmin ? 'team' : 'assigned',
            'leads_total' => array_sum($leadsByStatus),
            'leads_by_status' => $leadsByStatus,
            'pending_follow_ups' => $pendingFollowUps,
            'overdue_follow_ups' => $overdueFollowUps,
            'tickets_open' => $ticketsOpen,
            'tickets_overdue' => $ticketsOverdue,
            'recent_leads' => $recentLeads,
            'recent_tickets' => $recentTickets,
        ];
    }

    private function isInternalAdmin(User $actor): bool
    {
        return InternalRoleAssignment::query()
            ->where('user_id', $actor->id)
            ->whereNull('deactivated_at')
            ->whereHas('role', fn ($q) => $q->where('slug', 'internal.admin'))
            ->exists();
    }
}
