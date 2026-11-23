<?php

namespace App\Domains\Crm;

use App\Domains\Crm\Enums\SupportTicketPriority;
use App\Domains\Crm\Enums\SupportTicketStatus;
use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Models\Crm\SupportTicket;
use App\Models\Crm\SupportTicketActivity;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;

final class SupportTicketService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
    ) {}

    /**
     * @param  array{
     *     subject: string,
     *     description: string,
     *     category: string,
     *     priority?: string|SupportTicketPriority,
     *     assigned_to_user_id?: int|null,
     *     related_company_id?: string|null,
     *     due_at?: string|null
     * }  $attributes
     */
    public function create(User $actor, array $attributes): SupportTicket
    {
        $this->assertInternalActor($actor);

        $rawPriority = $attributes['priority'] ?? SupportTicketPriority::Medium;
        $priority = $rawPriority instanceof SupportTicketPriority
            ? $rawPriority
            : SupportTicketPriority::from((string) $rawPriority);

        return $this->withPlatformTenant(function () use ($actor, $attributes, $priority): SupportTicket {
            return $this->auditedTransaction->run(function () use ($actor, $attributes, $priority): SupportTicket {
                $ticket = SupportTicket::query()->create([
                    'subject' => trim((string) $attributes['subject']),
                    'description' => trim((string) $attributes['description']),
                    'category' => trim((string) $attributes['category']),
                    'priority' => $priority,
                    'status' => SupportTicketStatus::Open,
                    'assigned_to_user_id' => $attributes['assigned_to_user_id'] ?? null,
                    'created_by_user_id' => $actor->id,
                    'related_company_id' => $attributes['related_company_id'] ?? null,
                    'due_at' => isset($attributes['due_at']) ? $attributes['due_at'] : null,
                ]);

                $this->recordActivity($ticket, $actor, 'created', 'Ticket created');
                $this->auditWriter->write(
                    AuditAction::Created,
                    SupportTicket::class,
                    $ticket->id,
                    remark: 'Support ticket created',
                    before: null,
                    after: ['id' => $ticket->id, 'status' => $ticket->status->value],
                );

                return $ticket->refresh();
            });
        });
    }

    public function transition(User $actor, SupportTicket $ticket, SupportTicketStatus $to, string $remark = ''): SupportTicket
    {
        $this->assertInternalActor($actor);

        $from = $ticket->status;
        if ($from === $to) {
            return $ticket;
        }

        if (! in_array($to, $from->allowedTransitions(), true)) {
            throw new CrmDomainException("Ticket status cannot move from {$from->value} to {$to->value}.");
        }

        if ($from === SupportTicketStatus::Resolved && $to === SupportTicketStatus::InProgress && trim($remark) === '') {
            throw new CrmDomainException('A reason is required to reopen a resolved ticket.');
        }

        if ($to === SupportTicketStatus::Closed && trim($remark) === '') {
            throw new CrmDomainException('A remark is required to close a ticket.');
        }

        return $this->withPlatformTenant(function () use ($actor, $ticket, $from, $to, $remark): SupportTicket {
            return $this->auditedTransaction->run(function () use ($actor, $ticket, $from, $to, $remark): SupportTicket {
                $before = ['status' => $from->value];
                $ticket->forceFill([
                    'status' => $to,
                    'resolved_at' => $to === SupportTicketStatus::Resolved ? now() : $ticket->resolved_at,
                    'closed_at' => $to === SupportTicketStatus::Closed ? now() : null,
                    'resolution_notes' => $remark !== '' ? $remark : $ticket->resolution_notes,
                ])->save();

                $this->recordActivity($ticket, $actor, 'status_changed', $remark !== '' ? $remark : "Status {$from->value} → {$to->value}", [
                    'from' => $from->value,
                    'to' => $to->value,
                ]);
                $this->auditWriter->write(
                    AuditAction::Updated,
                    SupportTicket::class,
                    $ticket->id,
                    remark: $remark !== '' ? $remark : "Ticket status {$from->value} → {$to->value}",
                    before: $before,
                    after: ['status' => $to->value],
                );

                return $ticket->refresh();
            });
        });
    }

    public function assign(User $actor, SupportTicket $ticket, User $assignee, string $remark): SupportTicket
    {
        $this->assertInternalActor($actor);

        if (! $assignee->isInternal() || ! $assignee->isActive()) {
            throw new CrmDomainException('Assignee must be an active Internal user.');
        }

        if (trim($remark) === '') {
            throw new CrmDomainException('A remark is required for ticket assignment.');
        }

        if (in_array($ticket->status, [SupportTicketStatus::Closed], true)) {
            throw new CrmDomainException('Closed tickets cannot be reassigned.');
        }

        return $this->withPlatformTenant(function () use ($actor, $ticket, $assignee, $remark): SupportTicket {
            return $this->auditedTransaction->run(function () use ($actor, $ticket, $assignee, $remark): SupportTicket {
                $before = ['assigned_to_user_id' => $ticket->assigned_to_user_id];
                $ticket->forceFill(['assigned_to_user_id' => $assignee->id])->save();
                $this->recordActivity($ticket, $actor, 'assigned', $remark, [
                    'assigned_to_user_id' => $assignee->id,
                ]);
                $this->auditWriter->write(
                    AuditAction::Updated,
                    SupportTicket::class,
                    $ticket->id,
                    remark: $remark,
                    before: $before,
                    after: ['assigned_to_user_id' => $assignee->id],
                );

                return $ticket->refresh();
            });
        });
    }

    private function assertInternalActor(User $actor): void
    {
        if (! $actor->isInternal() || ! $actor->isActive()) {
            throw new CrmDomainException('Only active Internal users may manage support tickets.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function recordActivity(SupportTicket $ticket, User $actor, string $type, ?string $body, ?array $meta = null): void
    {
        SupportTicketActivity::query()->create([
            'ticket_id' => $ticket->id,
            'actor_user_id' => $actor->id,
            'activity_type' => $type,
            'body' => $body,
            'meta' => $meta,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withPlatformTenant(callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(isInternal: true));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::set($previous);
            }
        }
    }
}
