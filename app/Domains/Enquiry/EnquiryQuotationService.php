<?php

namespace App\Domains\Enquiry;

use App\Domains\Enquiry\Enums\BroadcastRequestStatus;
use App\Domains\Enquiry\Enums\EnquiryStatus;
use App\Domains\Enquiry\Enums\QuotationSelectionStatus;
use App\Domains\Enquiry\Exceptions\EnquiryAuthorizationException;
use App\Domains\Enquiry\Exceptions\EnquiryConflictException;
use App\Domains\Enquiry\Exceptions\EnquiryDomainException;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Enquiry;
use App\Models\EnquiryQuotation;
use App\Models\EnquiryQuotationBroadcastRequest;
use App\Models\EnquiryQuotationBroadcastTarget;
use App\Models\EnquiryQuotationSelectionHistory;
use App\Models\EnquiryStatusHistory;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quotation CRUD, preferred selection, and broadcast request persistence (Phase 4 Batch D).
 */
final class EnquiryQuotationService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly OutboxWriter $outbox = new OutboxWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(
        Company $company,
        Branch $branch,
        Enquiry $enquiry,
        User $actor,
        array $payload,
    ): EnquiryQuotation {
        $this->assertMutableEnquiry($company, $branch, $enquiry);

        $normalized = $this->normalizeQuotationPayload($company, $enquiry, $payload, forUpdate: false);

        try {
            return $this->auditedTransaction->run(function () use ($company, $branch, $enquiry, $actor, $normalized): EnquiryQuotation {
                return $this->withTenant($company, $branch, function () use ($company, $enquiry, $actor, $normalized): EnquiryQuotation {
                    $quote = EnquiryQuotation::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'enquiry_id' => $enquiry->id,
                        ...$normalized,
                        'selection_status' => QuotationSelectionStatus::Pending,
                    ]);

                    $this->transitionToQuotationsReceived($company, $enquiry, $actor);

                    $this->audit->write(
                        AuditAction::Created,
                        EnquiryQuotation::class,
                        $quote->id,
                        remark: 'Enquiry quotation created',
                        before: null,
                        after: $quote->toArray(),
                    );

                    return $quote->fresh();
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new EnquiryConflictException(
                'A quotation for this transporter already exists on the enquiry.',
                previous: $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(
        Company $company,
        Branch $branch,
        Enquiry $enquiry,
        EnquiryQuotation $quotation,
        User $actor,
        array $payload,
        string $remark,
    ): EnquiryQuotation {
        $this->assertMutableEnquiry($company, $branch, $enquiry);

        if ($quotation->company_id !== $company->id || $quotation->enquiry_id !== $enquiry->id) {
            throw new EnquiryAuthorizationException('Quotation does not belong to this enquiry.');
        }

        $remark = trim($remark);
        if ($remark === '') {
            throw ValidationException::withMessages(['remark' => 'A remark is required when editing quotations.']);
        }

        $normalized = $this->normalizeQuotationPayload($company, $enquiry, $payload, forUpdate: true);

        return $this->auditedTransaction->run(function () use (
            $company,
            $branch,
            $quotation,
            $normalized,
            $remark,
        ): EnquiryQuotation {
            return $this->withTenant($company, $branch, function () use ($quotation, $normalized, $remark): EnquiryQuotation {
                $before = $quotation->toArray();
                $quotation->forceFill($normalized)->save();

                $this->audit->write(
                    AuditAction::Updated,
                    EnquiryQuotation::class,
                    $quotation->id,
                    remark: $remark,
                    before: $before,
                    after: $quotation->fresh()?->toArray(),
                );

                return $quotation->fresh();
            });
        });
    }

    public function selectPreferred(
        Company $company,
        Branch $branch,
        Enquiry $enquiry,
        EnquiryQuotation $quotation,
        User $actor,
        ?string $remark = null,
    ): Enquiry {
        $this->assertMutableEnquiry($company, $branch, $enquiry);

        if ($quotation->company_id !== $company->id || $quotation->enquiry_id !== $enquiry->id) {
            throw new EnquiryAuthorizationException('Quotation does not belong to this enquiry.');
        }

        return $this->auditedTransaction->run(function () use ($company, $branch, $enquiry, $quotation, $actor, $remark): Enquiry {
            return $this->withTenant($company, $branch, function () use ($company, $enquiry, $quotation, $actor, $remark): Enquiry {
                $enquiryFresh = Enquiry::query()->whereKey($enquiry->id)->lockForUpdate()->firstOrFail();

                $allQuotes = EnquiryQuotation::query()
                    ->where('company_id', $company->id)
                    ->where('enquiry_id', $enquiryFresh->id)
                    ->whereNull('deleted_at')
                    ->get();

                foreach ($allQuotes as $row) {
                    $status = $row->id === $quotation->id
                        ? QuotationSelectionStatus::Preferred
                        : QuotationSelectionStatus::NotSelected;
                    $row->forceFill(['selection_status' => $status])->save();
                }

                EnquiryQuotationSelectionHistory::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'enquiry_id' => $enquiryFresh->id,
                    'quotation_id' => $quotation->id,
                    'action' => 'preferred',
                    'actor_user_id' => $actor->id,
                    'remark' => $remark,
                    'selected_at' => now(),
                ]);

                $previousStatus = $enquiryFresh->status;
                if ($previousStatus !== EnquiryStatus::Selected) {
                    EnquiryStatusHistory::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'enquiry_id' => $enquiryFresh->id,
                        'from_status' => $previousStatus,
                        'to_status' => EnquiryStatus::Selected,
                        'actor_user_id' => $actor->id,
                        'remark' => $remark ?? 'Preferred quotation selected',
                        'changed_at' => now(),
                    ]);
                    $enquiryFresh->forceFill(['status' => EnquiryStatus::Selected])->save();
                }

                return $enquiryFresh->fresh(['quotations']);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function requestBroadcast(
        Company $company,
        Branch $branch,
        Enquiry $enquiry,
        User $actor,
        array $payload,
    ): EnquiryQuotationBroadcastRequest {
        $this->assertMutableEnquiry($company, $branch, $enquiry);

        $transporterIds = $payload['transporter_party_ids'] ?? null;
        if (! is_array($transporterIds) || $transporterIds === []) {
            throw ValidationException::withMessages(['transporter_party_ids' => 'Select at least one transporter.']);
        }

        $channels = $payload['channels'] ?? null;
        if (! is_array($channels) || $channels === []) {
            throw ValidationException::withMessages(['channels' => 'Select at least one channel.']);
        }

        $allowedChannels = ['whatsapp', 'sms', 'email'];
        foreach ($channels as $channel) {
            if (! in_array($channel, $allowedChannels, true)) {
                throw ValidationException::withMessages(['channels' => 'Invalid broadcast channel.']);
            }
        }

        $idempotencyKey = isset($payload['idempotency_key'])
            ? trim((string) $payload['idempotency_key'])
            : null;
        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        if ($idempotencyKey !== null) {
            $existing = EnquiryQuotationBroadcastRequest::query()
                ->where('company_id', $company->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing->load('targets');
            }
        }

        $validatedTransporters = [];
        foreach (array_values(array_unique(array_map('strval', $transporterIds))) as $partyId) {
            $this->assertTransporterParty($company, $partyId);
            $validatedTransporters[] = $partyId;
        }

        $batchId = (string) Str::uuid();

        try {
            return $this->auditedTransaction->run(function () use (
                $company,
                $branch,
                $enquiry,
                $actor,
                $validatedTransporters,
                $channels,
                $batchId,
                $idempotencyKey,
            ): EnquiryQuotationBroadcastRequest {
                return $this->withTenant($company, $branch, function () use (
                    $company,
                    $enquiry,
                    $actor,
                    $validatedTransporters,
                    $channels,
                    $batchId,
                    $idempotencyKey,
                ): EnquiryQuotationBroadcastRequest {
                    $request = EnquiryQuotationBroadcastRequest::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'enquiry_id' => $enquiry->id,
                        'batch_id' => $batchId,
                        'channels' => $channels,
                        'status' => BroadcastRequestStatus::Queued,
                        'correlation_id' => (string) Str::uuid(),
                        'idempotency_key' => $idempotencyKey,
                        'actor_user_id' => $actor->id,
                        'requested_at' => now(),
                    ]);

                    foreach ($validatedTransporters as $partyId) {
                        EnquiryQuotationBroadcastTarget::query()->create([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'broadcast_request_id' => $request->id,
                            'transporter_party_id' => $partyId,
                        ]);
                    }

                    $this->outbox->enqueue(
                        eventType: 'quotation.broadcast.requested',
                        payload: [
                            'broadcast_request_id' => $request->id,
                            'enquiry_id' => $enquiry->id,
                            'batch_id' => $batchId,
                            'channels' => $channels,
                            'target_count' => count($validatedTransporters),
                            'status' => BroadcastRequestStatus::Queued->value,
                        ],
                        correlationId: $request->correlation_id,
                    );

                    $this->audit->write(
                        AuditAction::Created,
                        EnquiryQuotationBroadcastRequest::class,
                        $request->id,
                        remark: 'Quotation broadcast request queued',
                        before: null,
                        after: [
                            'enquiry_id' => $enquiry->id,
                            'target_count' => count($validatedTransporters),
                        ],
                    );

                    return $request->load('targets');
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey !== null) {
                $existing = EnquiryQuotationBroadcastRequest::query()
                    ->where('company_id', $company->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing !== null) {
                    return $existing->load('targets');
                }
            }

            throw $e;
        }
    }

    private function transitionToQuotationsReceived(Company $company, Enquiry $enquiry, User $actor): void
    {
        $enquiry->refresh();

        if (in_array($enquiry->status, [
            EnquiryStatus::Converted,
            EnquiryStatus::Rejected,
            EnquiryStatus::Cancelled,
            EnquiryStatus::Selected,
            EnquiryStatus::QuotationsReceived,
        ], true)) {
            return;
        }

        $from = $enquiry->status;
        $enquiry->forceFill(['status' => EnquiryStatus::QuotationsReceived])->save();

        EnquiryStatusHistory::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'enquiry_id' => $enquiry->id,
            'from_status' => $from,
            'to_status' => EnquiryStatus::QuotationsReceived,
            'actor_user_id' => $actor->id,
            'remark' => 'Quotation recorded',
            'changed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeQuotationPayload(
        Company $company,
        Enquiry $enquiry,
        array $payload,
        bool $forUpdate,
    ): array {
        $transporterId = $payload['transporter_party_id'] ?? null;
        if ($transporterId === null || $transporterId === '') {
            if (! $forUpdate) {
                throw ValidationException::withMessages(['transporter_party_id' => 'Transporter is required.']);
            }
        } else {
            $this->assertTransporterParty($company, (string) $transporterId);
        }

        $freight = $payload['quoted_freight'] ?? null;
        if ($freight === null || ! is_numeric($freight) || (float) $freight <= 0) {
            throw ValidationException::withMessages(['quoted_freight' => 'Quoted freight must be a positive amount.']);
        }

        $advance = $payload['advance_amount'] ?? null;
        if ($advance !== null && $advance !== '' && (! is_numeric($advance) || (float) $advance < 0)) {
            throw ValidationException::withMessages(['advance_amount' => 'Advance amount must be nonnegative.']);
        }

        $extra = $payload['extra_charges_amount'] ?? null;
        if ($extra !== null && $extra !== '' && (! is_numeric($extra) || (float) $extra < 0)) {
            throw ValidationException::withMessages(['extra_charges_amount' => 'Extra charges must be nonnegative.']);
        }

        $vehicleQty = $payload['vehicle_quantity'] ?? $enquiry->vehicle_quantity;
        if (! is_numeric($vehicleQty) || (int) $vehicleQty < 1) {
            throw ValidationException::withMessages(['vehicle_quantity' => 'Vehicle quantity must be at least 1.']);
        }

        $validUntil = null;
        if (! empty($payload['valid_until'])) {
            try {
                $validUntil = Carbon::parse((string) $payload['valid_until']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['valid_until' => 'Valid until date is invalid.']);
            }
        }

        $data = [
            'transporter_party_id' => $transporterId ?? null,
            'quoted_freight' => number_format((float) $freight, 2, '.', ''),
            'rate_basis' => $this->optionalString($payload['rate_basis'] ?? null),
            'vehicle_type_id' => $payload['vehicle_type_id'] ?? $enquiry->vehicle_type_id,
            'vehicle_quantity' => (int) $vehicleQty,
            'advance_amount' => $advance !== null && $advance !== ''
                ? number_format((float) $advance, 2, '.', '')
                : null,
            'payment_terms' => $this->optionalString($payload['payment_terms'] ?? null),
            'expected_delivery_duration' => $this->optionalString($payload['expected_delivery_duration'] ?? null),
            'valid_until' => $validUntil,
            'extra_charges_amount' => $extra !== null && $extra !== ''
                ? number_format((float) $extra, 2, '.', '')
                : null,
            'extra_charges_description' => $this->optionalString($payload['extra_charges_description'] ?? null),
            'remarks' => $this->optionalString($payload['remarks'] ?? null),
        ];

        if ($forUpdate) {
            unset($data['transporter_party_id']);
        }

        return $data;
    }

    private function assertTransporterParty(Company $company, string $partyId): void
    {
        $party = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($partyId)
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages(['transporter_party_id' => 'Transporter party was not found.']);
        }

        $hasRole = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $party->id)
            ->where('role', PartyRoleType::Transporter->value)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasRole) {
            throw ValidationException::withMessages(['transporter_party_id' => 'Party must have an active Transporter role.']);
        }
    }

    private function assertMutableEnquiry(Company $company, Branch $branch, Enquiry $enquiry): void
    {
        if ($enquiry->company_id !== $company->id) {
            throw new EnquiryAuthorizationException('Enquiry does not belong to this company.');
        }

        if ($enquiry->branch_id !== null && $enquiry->branch_id !== $branch->id) {
            throw new EnquiryAuthorizationException('Enquiry does not belong to this branch.');
        }

        if (in_array($enquiry->status, [
            EnquiryStatus::Converted,
            EnquiryStatus::Rejected,
            EnquiryStatus::Cancelled,
        ], true)) {
            throw new EnquiryDomainException('Enquiry cannot be modified in its current status.');
        }

        $this->ensureTenantMatches($company, $branch);
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new EnquiryDomainException('Branch does not belong to company.');
        }

        $tenant = TenantContextHolder::require();
        if ($tenant->companyId !== $company->id
            || ($tenant->branchId !== null && $tenant->branchId !== $branch->id)) {
            throw new EnquiryAuthorizationException('Tenant context mismatch.');
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(Company $company, Branch $branch, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: $branch->id,
            ownerId: (string) $company->owner_user_id,
            isInternal: false,
            isOwnerScope: false,
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::inject($previous);
            }
        }
    }
}
