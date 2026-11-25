<?php

namespace App\Domains\Crm;

use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Domains\Identity\Enums\IdentityType;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\LeadFollowUp;
use App\Models\InternalRoleAssignment;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class LeadService
{
    public const DUPLICATE_WINDOW_DAYS = 30;

    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
    ) {}

    /**
     * @param  array{
     *     contact_name: string,
     *     email?: string|null,
     *     mobile?: string|null,
     *     company_name?: string|null,
     *     source: string|LeadSource,
     *     interested_product: string|InterestedProduct,
     *     remarks?: string|null,
     *     assigned_to_user_id?: int|null,
     *     duplicate_override?: bool,
     *     duplicate_override_remark?: string|null
     * }  $attributes
     */
    public function create(User $actor, array $attributes): Lead
    {
        $this->assertInternalActor($actor);

        $source = $attributes['source'] instanceof LeadSource
            ? $attributes['source']
            : LeadSource::from((string) $attributes['source']);
        $product = $attributes['interested_product'] instanceof InterestedProduct
            ? $attributes['interested_product']
            : InterestedProduct::from((string) $attributes['interested_product']);

        $email = isset($attributes['email']) ? trim((string) $attributes['email']) : '';
        $normalized = $email !== '' ? Str::lower($email) : null;

        $override = (bool) ($attributes['duplicate_override'] ?? false);
        $overrideRemark = trim((string) ($attributes['duplicate_override_remark'] ?? ''));

        if ($normalized !== null) {
            $duplicates = $this->findPotentialDuplicates($normalized, $product);
            if ($duplicates->isNotEmpty() && ! $override) {
                throw new CrmDomainException(
                    'A potential duplicate lead exists within '.self::DUPLICATE_WINDOW_DAYS.' days. Provide an override remark to continue.'
                );
            }
            if ($duplicates->isNotEmpty() && $override && $overrideRemark === '') {
                throw new CrmDomainException('A mandatory remark is required to override duplicate detection.');
            }
        }

        $assigneeId = $attributes['assigned_to_user_id'] ?? null;
        if ($assigneeId !== null) {
            $this->assertAssignableSalesUser((int) $assigneeId);
            $this->assertCanAssign($actor);
        }

        return $this->withPlatformTenant(function () use ($actor, $attributes, $source, $product, $normalized, $override, $overrideRemark, $assigneeId, $email): Lead {
            return $this->auditedTransaction->run(function () use ($actor, $attributes, $source, $product, $normalized, $override, $overrideRemark, $assigneeId, $email): Lead {
                $lead = Lead::query()->create([
                    'contact_name' => trim((string) $attributes['contact_name']),
                    'email' => $email !== '' ? $email : null,
                    'mobile' => isset($attributes['mobile']) ? trim((string) $attributes['mobile']) : null,
                    'company_name' => isset($attributes['company_name']) ? trim((string) $attributes['company_name']) : null,
                    'source' => $source,
                    'interested_product' => $product,
                    'status' => LeadStatus::New,
                    'assigned_to_user_id' => $assigneeId,
                    'created_by_user_id' => $actor->id,
                    'remarks' => isset($attributes['remarks']) ? trim((string) $attributes['remarks']) : null,
                    'email_normalized' => $normalized,
                    'duplicate_override' => $override,
                    'duplicate_override_remark' => $override ? $overrideRemark : null,
                ]);

                $this->recordActivity($lead, $actor, 'created', 'Lead created');
                $this->auditWriter->write(
                    AuditAction::Created,
                    Lead::class,
                    $lead->id,
                    remark: 'CRM lead created',
                    before: null,
                    after: $this->snapshot($lead),
                );

                return $lead->refresh();
            });
        });
    }

    public function assign(User $actor, Lead $lead, User $assignee, string $remark): Lead
    {
        $this->assertCanAssign($actor);
        $this->assertAssignableSalesUser($assignee->id);

        if ($lead->status->isTerminal()) {
            throw new CrmDomainException('Terminal leads cannot be reassigned.');
        }

        if (trim($remark) === '') {
            throw new CrmDomainException('A remark is required for lead assignment.');
        }

        return $this->withPlatformTenant(function () use ($actor, $lead, $assignee, $remark): Lead {
            return $this->auditedTransaction->run(function () use ($actor, $lead, $assignee, $remark): Lead {
                $before = $this->snapshot($lead);
                $lead->forceFill(['assigned_to_user_id' => $assignee->id])->save();
                $this->recordActivity($lead, $actor, 'assigned', $remark, [
                    'assigned_to_user_id' => $assignee->id,
                ]);
                $this->auditWriter->write(
                    AuditAction::Updated,
                    Lead::class,
                    $lead->id,
                    remark: $remark,
                    before: $before,
                    after: $this->snapshot($lead->refresh()),
                );

                return $lead->refresh();
            });
        });
    }

    public function transition(User $actor, Lead $lead, LeadStatus $to, string $remark = ''): Lead
    {
        $this->assertCanMutateLead($actor, $lead);

        $from = $lead->status;
        if ($from === $to) {
            return $lead;
        }

        if (! in_array($to, $from->allowedTransitions(), true)) {
            throw new CrmDomainException("Lead status cannot move from {$from->value} to {$to->value}.");
        }

        if (in_array($to, [LeadStatus::Disqualified, LeadStatus::Lost], true) && trim($remark) === '') {
            throw new CrmDomainException('Remarks are required for Disqualified/Lost transitions.');
        }

        if ($to === LeadStatus::Converted) {
            throw new CrmDomainException('Use the conversion workflow to convert a Qualified lead.');
        }

        return $this->withPlatformTenant(function () use ($actor, $lead, $from, $to, $remark): Lead {
            return $this->auditedTransaction->run(function () use ($actor, $lead, $from, $to, $remark): Lead {
                $before = $this->snapshot($lead);
                $lead->forceFill([
                    'status' => $to,
                    'disqualified_at' => in_array($to, [LeadStatus::Disqualified, LeadStatus::Lost], true) ? now() : null,
                ])->save();

                $this->recordActivity($lead, $actor, 'status_changed', $remark !== '' ? $remark : "Status {$from->value} → {$to->value}", [
                    'from' => $from->value,
                    'to' => $to->value,
                ]);
                $this->auditWriter->write(
                    AuditAction::Updated,
                    Lead::class,
                    $lead->id,
                    remark: $remark !== '' ? $remark : "Lead status {$from->value} → {$to->value}",
                    before: $before,
                    after: $this->snapshot($lead->refresh()),
                );

                return $lead->refresh();
            });
        });
    }

    /**
     * @param  array{due_at: Carbon|string, notes?: string|null, assigned_to_user_id?: int|null}  $attributes
     */
    public function scheduleFollowUp(User $actor, Lead $lead, array $attributes): LeadFollowUp
    {
        $this->assertCanMutateLead($actor, $lead);

        if ($lead->status->isTerminal()) {
            throw new CrmDomainException('Follow-ups cannot be scheduled on terminal leads.');
        }

        $assigneeId = (int) ($attributes['assigned_to_user_id'] ?? $lead->assigned_to_user_id ?? $actor->id);
        $this->assertAssignableSalesUser($assigneeId);

        return $this->withPlatformTenant(function () use ($actor, $lead, $attributes, $assigneeId): LeadFollowUp {
            return $this->auditedTransaction->run(function () use ($actor, $lead, $attributes, $assigneeId): LeadFollowUp {
                $followUp = LeadFollowUp::query()->create([
                    'lead_id' => $lead->id,
                    'assigned_to_user_id' => $assigneeId,
                    'created_by_user_id' => $actor->id,
                    'due_at' => Carbon::parse($attributes['due_at']),
                    'notes' => isset($attributes['notes']) ? trim((string) $attributes['notes']) : null,
                ]);

                $this->recordActivity($lead, $actor, 'follow_up_scheduled', $followUp->notes, [
                    'follow_up_id' => $followUp->id,
                    'due_at' => $followUp->due_at->toIso8601String(),
                ]);

                return $followUp->refresh();
            });
        });
    }

    public function completeFollowUp(User $actor, LeadFollowUp $followUp, string $remark = ''): LeadFollowUp
    {
        $lead = Lead::query()->whereKey($followUp->lead_id)->firstOrFail();
        $this->assertCanMutateLead($actor, $lead);

        if ($followUp->completed_at !== null) {
            throw new CrmDomainException('This follow-up is already completed.');
        }

        return $this->withPlatformTenant(function () use ($actor, $lead, $followUp, $remark): LeadFollowUp {
            return $this->auditedTransaction->run(function () use ($actor, $lead, $followUp, $remark): LeadFollowUp {
                $followUp->forceFill([
                    'completed_at' => now(),
                    'completion_notes' => trim($remark) !== '' ? trim($remark) : null,
                ])->save();

                $this->recordActivity($lead, $actor, 'follow_up_completed', trim($remark) !== '' ? $remark : 'Follow-up completed', [
                    'follow_up_id' => $followUp->id,
                ]);
                $this->auditWriter->write(
                    AuditAction::Updated,
                    LeadFollowUp::class,
                    $followUp->id,
                    remark: trim($remark) !== '' ? $remark : 'CRM follow-up completed',
                    before: ['completed_at' => null],
                    after: ['completed_at' => $followUp->completed_at?->toIso8601String()],
                );

                return $followUp->refresh();
            });
        });
    }

    public function addNote(User $actor, Lead $lead, string $body): LeadActivity
    {
        $this->assertInternalActor($actor);
        $this->assertCanMutateLead($actor, $lead);

        $note = trim($body);
        if ($note === '') {
            throw new CrmDomainException('Note content is required.');
        }

        return $this->withPlatformTenant(function () use ($actor, $lead, $note): LeadActivity {
            return $this->auditedTransaction->run(function () use ($actor, $lead, $note): LeadActivity {
                $activity = $this->recordActivity($lead, $actor, 'note', $note);

                $this->auditWriter->write(
                    AuditAction::Created,
                    LeadActivity::class,
                    $activity->id,
                    remark: 'CRM lead note added',
                    before: null,
                    after: [
                        'lead_id' => $lead->id,
                        'activity_type' => 'note',
                    ],
                );

                return $activity;
            });
        });
    }

    /**
     * @return Collection<int, User>
     */
    public function assignableInternalUsers(): Collection
    {
        return User::query()
            ->where('identity_type', IdentityType::Internal->value)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * @return Collection<int, Lead>
     */
    public function findPotentialDuplicates(string $emailNormalized, InterestedProduct $product)
    {
        $since = now()->subDays(self::DUPLICATE_WINDOW_DAYS);

        return Lead::query()
            ->where('email_normalized', $emailNormalized)
            ->where('interested_product', $product->value)
            ->where('created_at', '>=', $since)
            ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Disqualified->value, LeadStatus::Lost->value])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    private function assertInternalActor(User $actor): void
    {
        if (! $actor->isInternal() || ! $actor->isActive()) {
            throw new CrmDomainException('Only active Internal users may manage CRM leads.');
        }
    }

    private function assertCanAssign(User $actor): void
    {
        $this->assertInternalActor($actor);
        if (! $actor->isSuperAdmin() && ! $this->isInternalAdmin($actor)) {
            throw new CrmDomainException('Only Super Admin or Internal Admin may assign leads.');
        }
    }

    private function assertCanMutateLead(User $actor, Lead $lead): void
    {
        $this->assertInternalActor($actor);

        if ($actor->isSuperAdmin() || $this->isInternalAdmin($actor)) {
            return;
        }

        if ((int) $lead->assigned_to_user_id !== (int) $actor->id) {
            throw new CrmDomainException('Sales Executives may only mutate leads assigned to them.');
        }
    }

    private function isInternalAdmin(User $actor): bool
    {
        return InternalRoleAssignment::query()
            ->where('user_id', $actor->id)
            ->whereNull('deactivated_at')
            ->whereHas('role', fn ($q) => $q->where('slug', 'internal.admin'))
            ->exists();
    }

    private function assertAssignableSalesUser(int $userId): void
    {
        $user = User::query()->whereKey($userId)->first();
        if (! $user instanceof User || ! $user->isInternal() || ! $user->isActive()) {
            throw new CrmDomainException('Assignee must be an active Internal user.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function recordActivity(Lead $lead, User $actor, string $type, ?string $body, ?array $meta = null): LeadActivity
    {
        return LeadActivity::query()->create([
            'lead_id' => $lead->id,
            'actor_user_id' => $actor->id,
            'activity_type' => $type,
            'body' => $body,
            'meta' => $meta,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'status' => $lead->status->value,
            'assigned_to_user_id' => $lead->assigned_to_user_id,
            'email_normalized' => $lead->email_normalized,
            'interested_product' => $lead->interested_product->value,
        ];
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
