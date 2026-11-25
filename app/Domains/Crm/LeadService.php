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
use Illuminate\Support\Facades\Cache;
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
     *     state?: string|null,
     *     city?: string|null,
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

        $state = $this->normalizeGeoText($attributes['state'] ?? null);
        $city = $this->normalizeGeoText($attributes['city'] ?? null);

        return $this->withPlatformTenant(function () use ($actor, $attributes, $source, $product, $normalized, $override, $overrideRemark, $assigneeId, $email, $state, $city): Lead {
            return $this->auditedTransaction->run(function () use ($actor, $attributes, $source, $product, $normalized, $override, $overrideRemark, $assigneeId, $email, $state, $city): Lead {
                $lead = Lead::query()->create([
                    'contact_name' => trim((string) $attributes['contact_name']),
                    'email' => $email !== '' ? $email : null,
                    'mobile' => isset($attributes['mobile']) ? trim((string) $attributes['mobile']) : null,
                    'company_name' => isset($attributes['company_name']) ? trim((string) $attributes['company_name']) : null,
                    'state' => $state,
                    'city' => $city,
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

    /**
     * @param  array{
     *     contact_name?: string,
     *     email?: string|null,
     *     mobile?: string|null,
     *     company_name?: string|null,
     *     state?: string|null,
     *     city?: string|null,
     *     source?: string|LeadSource,
     *     interested_product?: string|InterestedProduct,
     *     remarks?: string|null,
     *     assigned_to_user_id?: int|null
     * }  $attributes
     */
    public function update(User $actor, Lead $lead, array $attributes): Lead
    {
        $this->assertCanMutateLead($actor, $lead);

        if ($lead->status->isTerminal()) {
            throw new CrmDomainException('Terminal leads cannot be edited.');
        }

        $source = array_key_exists('source', $attributes)
            ? ($attributes['source'] instanceof LeadSource
                ? $attributes['source']
                : LeadSource::from((string) $attributes['source']))
            : $lead->source;
        $product = array_key_exists('interested_product', $attributes)
            ? ($attributes['interested_product'] instanceof InterestedProduct
                ? $attributes['interested_product']
                : InterestedProduct::from((string) $attributes['interested_product']))
            : $lead->interested_product;

        $email = array_key_exists('email', $attributes)
            ? trim((string) ($attributes['email'] ?? ''))
            : (string) ($lead->email ?? '');
        $normalized = $email !== '' ? Str::lower($email) : null;

        $assigneeId = array_key_exists('assigned_to_user_id', $attributes)
            ? $attributes['assigned_to_user_id']
            : $lead->assigned_to_user_id;
        if ($assigneeId !== null && (int) $assigneeId !== (int) $lead->assigned_to_user_id) {
            $this->assertAssignableSalesUser((int) $assigneeId);
            $this->assertCanAssign($actor);
        }

        $state = array_key_exists('state', $attributes)
            ? $this->normalizeGeoText($attributes['state'])
            : $lead->state;
        $city = array_key_exists('city', $attributes)
            ? $this->normalizeGeoText($attributes['city'])
            : $lead->city;

        return $this->withPlatformTenant(function () use ($actor, $lead, $attributes, $source, $product, $normalized, $assigneeId, $email, $state, $city): Lead {
            return $this->auditedTransaction->run(function () use ($actor, $lead, $attributes, $source, $product, $normalized, $assigneeId, $email, $state, $city): Lead {
                $before = $this->snapshot($lead);
                $lead->forceFill([
                    'contact_name' => array_key_exists('contact_name', $attributes)
                        ? trim((string) $attributes['contact_name'])
                        : $lead->contact_name,
                    'email' => $email !== '' ? $email : null,
                    'mobile' => array_key_exists('mobile', $attributes)
                        ? (trim((string) ($attributes['mobile'] ?? '')) ?: null)
                        : $lead->mobile,
                    'company_name' => array_key_exists('company_name', $attributes)
                        ? (trim((string) ($attributes['company_name'] ?? '')) ?: null)
                        : $lead->company_name,
                    'state' => $state,
                    'city' => $city,
                    'source' => $source,
                    'interested_product' => $product,
                    'assigned_to_user_id' => $assigneeId,
                    'remarks' => array_key_exists('remarks', $attributes)
                        ? (trim((string) ($attributes['remarks'] ?? '')) ?: null)
                        : $lead->remarks,
                    'email_normalized' => $normalized,
                ])->save();

                $this->recordActivity($lead, $actor, 'updated', 'Lead details updated');
                $this->auditWriter->write(
                    AuditAction::Updated,
                    Lead::class,
                    $lead->id,
                    remark: 'CRM lead updated',
                    before: $before,
                    after: $this->snapshot($lead->refresh()),
                );

                return $lead->refresh();
            });
        });
    }

    /**
     * Public website lead intake (no Internal CRM actor).
     * Always forces Source=Website and Status=New; never trusts privileged fields.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{lead: Lead, created: bool, replayed: bool, appended: bool, receipt: string}
     */
    public function captureFromWebsite(array $attributes): array
    {
        $contactName = trim((string) ($attributes['contact_name'] ?? $attributes['name'] ?? ''));
        $email = trim((string) ($attributes['email'] ?? ''));
        $normalized = $email !== '' ? Str::lower($email) : null;
        $product = $this->mapPublicProduct($attributes['interested_product'] ?? $attributes['product_interested_in'] ?? null);
        $channel = $this->normalizeSourceChannel($attributes['source'] ?? null);
        $remarks = isset($attributes['remarks']) ? trim((string) $attributes['remarks']) : null;
        $state = $this->normalizeGeoText($attributes['state'] ?? null);
        $city = $this->normalizeGeoText($attributes['city'] ?? null);
        $idempotencyKey = $this->resolveIdempotencyKey($attributes, $normalized, $product, $channel, $remarks);

        $cacheKey = 'public-lead:idem:'.$idempotencyKey;
        $ttl = max(60, (int) config('public_leads.idempotency_ttl_seconds', 600));

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['lead_id'], $cached['receipt'])) {
            $existing = Lead::query()->find($cached['lead_id']);
            if ($existing instanceof Lead) {
                return [
                    'lead' => $existing,
                    'created' => false,
                    'replayed' => true,
                    'appended' => false,
                    'receipt' => (string) $cached['receipt'],
                ];
            }
        }

        $byKey = Lead::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($byKey instanceof Lead) {
            $receipt = $this->receiptFor($byKey);
            Cache::put($cacheKey, ['lead_id' => $byKey->id, 'receipt' => $receipt], $ttl);

            return [
                'lead' => $byKey,
                'created' => false,
                'replayed' => true,
                'appended' => false,
                'receipt' => $receipt,
            ];
        }

        if (
            $normalized !== null
            && (bool) config('public_leads.append_enquiry_on_open_duplicate', true)
        ) {
            $openDuplicate = $this->findPotentialDuplicates($normalized, $product)->first();
            if ($openDuplicate instanceof Lead) {
                return $this->withPlatformTenant(function () use ($openDuplicate, $remarks, $attributes, $channel, $cacheKey, $ttl, $idempotencyKey): array {
                    return $this->auditedTransaction->run(function () use ($openDuplicate, $remarks, $attributes, $channel, $cacheKey, $ttl, $idempotencyKey): array {
                        $body = $remarks !== null && $remarks !== ''
                            ? $remarks
                            : 'Additional website enquiry received.';
                        $this->recordSystemActivity($openDuplicate, 'website_enquiry', $body, [
                            'source_channel' => $channel,
                            'utm_source' => $attributes['utm_source'] ?? null,
                            'utm_medium' => $attributes['utm_medium'] ?? null,
                            'utm_campaign' => $attributes['utm_campaign'] ?? null,
                            'landing_url' => $attributes['landing_url'] ?? null,
                        ]);

                        if ($openDuplicate->idempotency_key === null) {
                            $openDuplicate->forceFill(['idempotency_key' => $idempotencyKey])->save();
                        }

                        $receipt = $this->receiptFor($openDuplicate);
                        Cache::put($cacheKey, ['lead_id' => $openDuplicate->id, 'receipt' => $receipt], $ttl);

                        return [
                            'lead' => $openDuplicate->refresh(),
                            'created' => false,
                            'replayed' => false,
                            'appended' => true,
                            'receipt' => $receipt,
                        ];
                    });
                });
            }
        }

        return $this->withPlatformTenant(function () use (
            $contactName,
            $email,
            $normalized,
            $product,
            $channel,
            $remarks,
            $state,
            $city,
            $attributes,
            $idempotencyKey,
            $cacheKey,
            $ttl,
        ): array {
            return $this->auditedTransaction->run(function () use (
                $contactName,
                $email,
                $normalized,
                $product,
                $channel,
                $remarks,
                $state,
                $city,
                $attributes,
                $idempotencyKey,
                $cacheKey,
                $ttl,
            ): array {
                $lead = Lead::query()->create([
                    'contact_name' => $contactName,
                    'email' => $email !== '' ? $email : null,
                    'mobile' => isset($attributes['mobile']) ? trim((string) $attributes['mobile']) : null,
                    'company_name' => isset($attributes['company_name']) ? trim((string) $attributes['company_name']) : null,
                    'state' => $state,
                    'city' => $city,
                    'source' => LeadSource::Website,
                    'source_channel' => $channel,
                    'interested_product' => $product,
                    'status' => LeadStatus::New,
                    'assigned_to_user_id' => null,
                    'created_by_user_id' => null,
                    'remarks' => $remarks,
                    'email_normalized' => $normalized,
                    'utm_source' => $this->clip((string) ($attributes['utm_source'] ?? ''), 255),
                    'utm_medium' => $this->clip((string) ($attributes['utm_medium'] ?? ''), 255),
                    'utm_campaign' => $this->clip((string) ($attributes['utm_campaign'] ?? ''), 255),
                    'landing_url' => $this->clip((string) ($attributes['landing_url'] ?? ''), 2000),
                    'referrer' => $this->clip((string) ($attributes['referrer'] ?? ''), 2000),
                    'ip_address' => isset($attributes['ip_address']) ? trim((string) $attributes['ip_address']) : null,
                    'idempotency_key' => $idempotencyKey,
                    'duplicate_override' => false,
                ]);

                $this->recordSystemActivity($lead, 'created', 'Lead captured from public website', [
                    'source_channel' => $channel,
                ]);
                if ($remarks !== null && $remarks !== '') {
                    $this->recordSystemActivity($lead, 'website_enquiry', $remarks, [
                        'source_channel' => $channel,
                    ]);
                }

                $this->auditWriter->write(
                    AuditAction::Created,
                    Lead::class,
                    $lead->id,
                    remark: 'CRM lead captured from website',
                    before: null,
                    after: $this->snapshot($lead),
                );

                $receipt = $this->receiptFor($lead);
                Cache::put($cacheKey, ['lead_id' => $lead->id, 'receipt' => $receipt], $ttl);

                return [
                    'lead' => $lead->refresh(),
                    'created' => true,
                    'replayed' => false,
                    'appended' => false,
                    'receipt' => $receipt,
                ];
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
     * @param  array<string, mixed>|null  $meta
     */
    private function recordSystemActivity(Lead $lead, string $type, ?string $body, ?array $meta = null): LeadActivity
    {
        return LeadActivity::query()->create([
            'lead_id' => $lead->id,
            'actor_user_id' => null,
            'activity_type' => $type,
            'body' => $body,
            'meta' => $meta,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalizeGeoText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(strip_tags((string) $value));
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, 100);
    }

    private function mapPublicProduct(mixed $raw): InterestedProduct
    {
        $value = Str::lower(trim((string) ($raw ?? '')));

        return match ($value) {
            'broker' => InterestedProduct::Broker,
            'ownfleet', 'own_fleet', 'fleet' => InterestedProduct::OwnFleet,
            'partload', 'parchun' => InterestedProduct::Partload,
            'combined', 'all' => InterestedProduct::Combined,
            default => InterestedProduct::Undecided,
        };
    }

    private function normalizeSourceChannel(mixed $raw): string
    {
        $channel = Str::lower(trim((string) ($raw ?? 'website')));
        $channel = preg_replace('/[^a-z0-9_\-]/', '', $channel) ?? 'website';

        if ($channel === '' || ! str_starts_with($channel, 'website')) {
            $channel = 'website';
        }

        return mb_substr($channel, 0, 100);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveIdempotencyKey(
        array $attributes,
        ?string $normalizedEmail,
        InterestedProduct $product,
        string $channel,
        ?string $remarks,
    ): string {
        $provided = trim((string) ($attributes['idempotency_key'] ?? ''));
        if ($provided !== '') {
            return mb_substr($provided, 0, 128);
        }

        return hash('sha256', implode('|', [
            $normalizedEmail ?? '',
            (string) ($attributes['mobile'] ?? ''),
            $product->value,
            $channel,
            mb_substr((string) ($remarks ?? ''), 0, 500),
            // Bucket retries within the same minute without blocking genuine re-enquiries later.
            now()->format('Y-m-d-H-i'),
        ]));
    }

    private function receiptFor(Lead $lead): string
    {
        return 'tb_'.substr(hash('sha256', $lead->id.'|'.$lead->created_at?->timestamp), 0, 16);
    }

    private function clip(string $value, int $max): ?string
    {
        $trimmed = trim(strip_tags($value));
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $max);
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
            'state' => $lead->state,
            'city' => $lead->city,
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
