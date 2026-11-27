<?php

namespace App\Domains\Enquiry;

use App\Domains\Enquiry\Enums\EnquirySource;
use App\Domains\Enquiry\Enums\EnquiryStatus;
use App\Domains\Enquiry\Exceptions\EnquiryAuthorizationException;
use App\Domains\Enquiry\Exceptions\EnquiryConflictException;
use App\Domains\Enquiry\Exceptions\EnquiryDomainException;
use App\Domains\Enquiry\Support\EnquiryIntakeSupport;
use App\Domains\Enquiry\Support\EnquiryPayloadFingerprint;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Enquiry;
use App\Models\EnquiryExternalReference;
use App\Models\EnquiryGoodsRow;
use App\Models\EnquiryStatusHistory;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use App\Support\Weight\OperationalWeight;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared enquiry create/update orchestration for manual Branch and website intake.
 */
final class EnquiryService
{
    public function __construct(
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly OutboxWriter $outbox = new OutboxWriter,
        private readonly EnquiryIntakeSupport $intake = new EnquiryIntakeSupport,
        private readonly CompanyCapabilityResolver $capabilities = new CompanyCapabilityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createManual(Company $company, Branch $branch, User $actor, array $payload): Enquiry
    {
        if ($branch->company_id !== $company->id) {
            throw new EnquiryDomainException('Branch does not belong to company.');
        }

        $this->ensureTenantMatches($company, $branch);

        $normalized = $this->normalizeManualPayload($company, $payload);

        return $this->persistNewEnquiry(
            company: $company,
            branch: $branch,
            source: EnquirySource::Manual,
            actorUserId: $actor->id,
            normalized: $normalized,
            externalReference: null,
            fingerprint: null,
            correlationId: null,
        );
    }

    /**
     * Website intake: company from credential; branch always null; no Party auto-create.
     *
     * @param  array<string, mixed>  $payload
     * @return array{enquiry: Enquiry, created: bool}
     */
    public function createFromWebsite(
        Company $company,
        array $payload,
        ?string $correlationId = null,
        ?Branch $branch = null,
    ): array {
        if (! $this->capabilities->allowsRead($company, CompanyCapabilities::ENQUIRIES)) {
            throw new EnquiryAuthorizationException('Enquiries capability is not enabled for this company.');
        }

        if ($branch !== null && $branch->company_id !== $company->id) {
            throw new EnquiryDomainException('Receiving branch does not belong to company.');
        }

        $this->ensureTenantMatches($company, $branch);

        $normalized = $this->normalizeWebsitePayload($company, $payload);
        $externalReference = $normalized['external_reference'];
        $fingerprint = EnquiryPayloadFingerprint::hash($normalized);

        $existing = EnquiryExternalReference::query()
            ->where('company_id', $company->id)
            ->where('external_reference', $externalReference)
            ->first();

        if ($existing !== null) {
            if ($existing->request_fingerprint !== null
                && ! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                throw new EnquiryConflictException(
                    'External reference already exists for this company with a different payload.'
                );
            }

            /** @var Enquiry $enquiry */
            $enquiry = Enquiry::query()
                ->where('company_id', $company->id)
                ->whereKey($existing->enquiry_id)
                ->firstOrFail();

            return ['enquiry' => $enquiry, 'created' => false];
        }

        try {
            $enquiry = AuditWriter::usingSystemActor('enquiry-website-webhook', function () use (
                $company,
                $branch,
                $normalized,
                $externalReference,
                $fingerprint,
                $correlationId,
            ): Enquiry {
                return $this->persistNewEnquiry(
                    company: $company,
                    branch: $branch,
                    source: EnquirySource::Website,
                    actorUserId: null,
                    normalized: $normalized,
                    externalReference: $externalReference,
                    fingerprint: $fingerprint,
                    correlationId: $correlationId,
                );
            });
        } catch (UniqueConstraintViolationException $e) {
            $raced = EnquiryExternalReference::query()
                ->where('company_id', $company->id)
                ->where('external_reference', $externalReference)
                ->first();

            if ($raced === null) {
                throw $e;
            }

            if ($raced->request_fingerprint !== null
                && ! hash_equals((string) $raced->request_fingerprint, $fingerprint)) {
                throw new EnquiryConflictException(
                    'External reference already exists for this company with a different payload.',
                    previous: $e,
                );
            }

            /** @var Enquiry $enquiry */
            $enquiry = Enquiry::query()
                ->where('company_id', $company->id)
                ->whereKey($raced->enquiry_id)
                ->firstOrFail();

            return ['enquiry' => $enquiry, 'created' => false];
        }

        return ['enquiry' => $enquiry, 'created' => true];
    }

    /**
     * Narrow manual edit: header fields + goods replacement. No assignment/quotation/conversion.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateManual(
        Company $company,
        Branch $branch,
        Enquiry $enquiry,
        User $actor,
        array $payload,
        string $remark,
    ): Enquiry {
        if ($enquiry->company_id !== $company->id) {
            throw new EnquiryAuthorizationException('Enquiry does not belong to this company.');
        }

        if ($enquiry->branch_id !== $branch->id) {
            throw new EnquiryAuthorizationException('Enquiry does not belong to this branch.');
        }

        $this->ensureTenantMatches($company, $branch);

        $remark = trim($remark);
        if ($remark === '') {
            throw ValidationException::withMessages(['remark' => 'A remark is required for enquiry edits.']);
        }

        if (in_array($enquiry->status, [
            EnquiryStatus::Converted,
            EnquiryStatus::Rejected,
            EnquiryStatus::Cancelled,
        ], true)) {
            throw new EnquiryDomainException('Enquiry cannot be edited in its current status.');
        }

        $enquiry->loadMissing('goodsRows');

        $normalized = $this->normalizeManualPayload($company, array_merge([
            'party_name' => $enquiry->party_name,
            'customer_party_id' => $enquiry->customer_party_id,
            'consignor_party_id' => $enquiry->consignor_party_id,
            'consignee_party_id' => $enquiry->consignee_party_id,
            'from_state_id' => $enquiry->from_state_id,
            'from_city_id' => $enquiry->from_city_id,
            'to_state_id' => $enquiry->to_state_id,
            'to_city_id' => $enquiry->to_city_id,
            'pickup_location' => $enquiry->pickup_location,
            'delivery_location' => $enquiry->delivery_location,
            'vehicle_type_id' => $enquiry->vehicle_type_id,
            'vehicle_quantity' => $enquiry->vehicle_quantity,
            'expected_loading_date' => $this->dateToString($enquiry->expected_loading_date),
            'instructions' => $enquiry->instructions,
            'enquiry_date' => $this->dateToString($enquiry->enquiry_date),
            'goods_type_id' => $enquiry->goodsRows->first()?->goods_type_id,
            'weight_mt' => $enquiry->goodsRows->first()?->actual_weight !== null
                ? OperationalWeight::kgToMt((string) $enquiry->goodsRows->first()->actual_weight)
                : null,
        ], $payload));

        return $this->auditedTransaction->run(function () use ($company, $branch, $enquiry, $normalized, $remark): Enquiry {
            return $this->withTenant($company, $branch, function () use ($enquiry, $normalized, $remark): Enquiry {
                $before = $enquiry->fresh(['goodsRows'])?->toArray();

                $enquiry->forceFill([
                    'enquiry_date' => $normalized['enquiry_date'],
                    'party_name' => $normalized['party_name'],
                    'customer_party_id' => $normalized['customer_party_id'],
                    'consignor_party_id' => $normalized['consignor_party_id'],
                    'consignee_party_id' => $normalized['consignee_party_id'],
                    'from_state_id' => $normalized['from_state_id'],
                    'from_city_id' => $normalized['from_city_id'],
                    'to_state_id' => $normalized['to_state_id'],
                    'to_city_id' => $normalized['to_city_id'],
                    'pickup_location' => $normalized['pickup_location'],
                    'delivery_location' => $normalized['delivery_location'],
                    'vehicle_type_id' => $normalized['vehicle_type_id'],
                    'vehicle_quantity' => $normalized['vehicle_quantity'],
                    'expected_loading_date' => $normalized['expected_loading_date'],
                    'instructions' => $normalized['instructions'],
                    'pending_master_resolution' => $normalized['pending_master_resolution'],
                ])->save();

                // Soft-delete prior goods rows (DEC-003). Active uniqueness is enforced by a
                // partial unique index on (enquiry_id, position) WHERE deleted_at IS NULL.
                EnquiryGoodsRow::query()
                    ->where('enquiry_id', $enquiry->id)
                    ->where('company_id', $enquiry->company_id)
                    ->delete();

                $this->persistGoodsRows($enquiry, $normalized['goods']);

                $fresh = $enquiry->fresh(['goodsRows']);

                $this->audit->write(
                    AuditAction::Updated,
                    Enquiry::class,
                    $enquiry->id,
                    remark: $remark,
                    before: $before,
                    after: $fresh?->toArray(),
                );

                return $fresh;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function persistNewEnquiry(
        Company $company,
        ?Branch $branch,
        EnquirySource $source,
        ?int $actorUserId,
        array $normalized,
        ?string $externalReference,
        ?string $fingerprint,
        ?string $correlationId,
    ): Enquiry {
        return $this->auditedTransaction->run(function () use (
            $company,
            $branch,
            $source,
            $actorUserId,
            $normalized,
            $externalReference,
            $fingerprint,
            $correlationId,
        ): Enquiry {
            return $this->withTenant($company, $branch, function () use (
                $company,
                $branch,
                $source,
                $actorUserId,
                $normalized,
                $externalReference,
                $fingerprint,
                $correlationId,
            ): Enquiry {
                $allocation = $this->numbers->allocateNext(
                    companyId: $company->id,
                    documentType: DocumentNumberType::Enquiry,
                    scopeType: DocumentNumberScopeType::Company,
                    branchId: null,
                );

                $enquiry = new Enquiry;
                $enquiry->forceFill([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch?->id,
                    'source' => $source,
                    'enquiry_number' => $allocation['number'],
                    'enquiry_date' => $normalized['enquiry_date'],
                    'party_name' => $normalized['party_name'] ?? null,
                    'customer_party_id' => $normalized['customer_party_id'] ?? null,
                    'consignor_party_id' => $normalized['consignor_party_id'] ?? null,
                    'consignee_party_id' => $normalized['consignee_party_id'] ?? null,
                    'from_state_id' => $normalized['from_state_id'] ?? null,
                    'from_city_id' => $normalized['from_city_id'] ?? null,
                    'to_state_id' => $normalized['to_state_id'] ?? null,
                    'to_city_id' => $normalized['to_city_id'] ?? null,
                    'external_customer_name' => $normalized['external_customer_name'] ?? null,
                    'external_customer_phone' => $normalized['external_customer_phone'] ?? null,
                    'external_customer_email' => $normalized['external_customer_email'] ?? null,
                    'external_business_name' => $normalized['external_business_name'] ?? null,
                    'external_contact_person_name' => $normalized['external_contact_person_name'] ?? null,
                    'pickup_location' => $normalized['pickup_location'],
                    'delivery_location' => $normalized['delivery_location'],
                    'vehicle_type_id' => $normalized['vehicle_type_id'],
                    'vehicle_quantity' => $normalized['vehicle_quantity'],
                    'expected_loading_date' => $normalized['expected_loading_date'],
                    'instructions' => $normalized['instructions'],
                    'pending_master_resolution' => $normalized['pending_master_resolution'] ?? null,
                    'assigned_user_id' => null,
                    'status' => EnquiryStatus::New,
                    'created_by_user_id' => $actorUserId,
                    'external_reference' => $externalReference,
                ]);
                $enquiry->save();

                $allocation['allocation']->forceFill([
                    'allocated_to_id' => $enquiry->id,
                    'allocated_to_type' => Enquiry::class,
                ])->save();

                $this->persistGoodsRows($enquiry, $normalized['goods']);

                EnquiryStatusHistory::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'enquiry_id' => $enquiry->id,
                    'from_status' => null,
                    'to_status' => EnquiryStatus::New,
                    'actor_user_id' => $actorUserId,
                    'remark' => 'Initial status',
                    'changed_at' => now(),
                ]);

                if ($externalReference !== null) {
                    EnquiryExternalReference::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'external_reference' => $externalReference,
                        'enquiry_id' => $enquiry->id,
                        'source' => $source->value,
                        'request_fingerprint' => $fingerprint,
                    ]);
                }

                $this->audit->write(
                    AuditAction::Created,
                    Enquiry::class,
                    $enquiry->id,
                    remark: $source === EnquirySource::Website
                        ? 'Website enquiry created'
                        : 'Manual enquiry created',
                    before: null,
                    after: [
                        'id' => $enquiry->id,
                        'enquiry_number' => $enquiry->enquiry_number,
                        'source' => $source->value,
                        'status' => EnquiryStatus::New->value,
                        'branch_id' => $branch?->id,
                        'external_reference' => $externalReference,
                    ],
                    correlationId: $correlationId,
                );

                $this->outbox->enqueue(
                    eventType: 'enquiry.created',
                    payload: [
                        'enquiry_id' => $enquiry->id,
                        'enquiry_number' => $enquiry->enquiry_number,
                        'source' => $source->value,
                        'status' => EnquiryStatus::New->value,
                        'branch_id' => $branch?->id,
                        'has_external_reference' => $externalReference !== null,
                    ],
                    correlationId: $correlationId,
                );

                return $enquiry->fresh(['goodsRows', 'statusHistories']);
            });
        });
    }

    /**
     * @param  list<array<string, mixed>>  $goods
     */
    private function persistGoodsRows(Enquiry $enquiry, array $goods): void
    {
        foreach ($goods as $index => $row) {
            EnquiryGoodsRow::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $enquiry->company_id,
                'enquiry_id' => $enquiry->id,
                'position' => $index + 1,
                'goods_type_id' => $row['goods_type_id'] ?? null,
                'package_type_id' => $row['package_type_id'] ?? null,
                'goods_description' => $row['goods_description'] ?? null,
                'package_count' => $row['package_count'] ?? null,
                'actual_weight' => $row['actual_weight'] ?? null,
                'charged_weight' => $row['charged_weight'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeManualPayload(Company $company, array $payload): array
    {
        $route = $this->intake->resolveManualRouteAndParties($company, $payload);
        $vehicle = $this->intake->resolveVehicleType($company, $payload, required: true);
        $goods = $this->intake->normalizeManualGoods($company, $payload);

        $pickupDate = $this->normalizeDate(
            $payload['pickup_date'] ?? $payload['expected_loading_date'] ?? null,
            'expected_loading_date',
        );

        return [
            'enquiry_date' => $this->normalizeDate($payload['enquiry_date'] ?? null, 'enquiry_date')
                ?? Carbon::now(config('app.timezone'))->toDateString(),
            'party_name' => $route['party_name'],
            'customer_party_id' => $route['customer_party_id'],
            'consignor_party_id' => $route['consignor_party_id'],
            'consignee_party_id' => $route['consignee_party_id'],
            'from_state_id' => $route['from_state_id'],
            'from_city_id' => $route['from_city_id'],
            'to_state_id' => $route['to_state_id'],
            'to_city_id' => $route['to_city_id'],
            'external_customer_name' => null,
            'external_customer_phone' => null,
            'external_customer_email' => null,
            'external_business_name' => null,
            'external_contact_person_name' => null,
            'pickup_location' => $route['pickup_location'],
            'delivery_location' => $route['delivery_location'],
            'vehicle_type_id' => $vehicle['vehicle_type_id'],
            'vehicle_quantity' => $this->normalizeVehicleQuantity($payload, defaultWhenMissing: 1),
            'expected_loading_date' => $pickupDate,
            'instructions' => $this->optionalString($payload['notes'] ?? $payload['instructions'] ?? null),
            'pending_master_resolution' => $vehicle['pending'] === [] ? null : $vehicle['pending'],
            'goods' => $goods,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeWebsitePayload(Company $company, array $payload): array
    {
        $externalReference = trim((string) ($payload['external_reference'] ?? ''));
        if ($externalReference === '') {
            throw ValidationException::withMessages(['external_reference' => 'External reference is required.']);
        }

        if (strlen($externalReference) > 128) {
            throw ValidationException::withMessages(['external_reference' => 'External reference is too long.']);
        }

        $vehicle = $this->intake->resolveVehicleType($company, $payload, required: false);
        $goodsPayload = $this->intake->resolveWebsiteGoods($company, $payload);
        $route = $this->intake->resolveWebsiteRoute($company, $payload);

        $pending = array_merge($vehicle['pending'], $goodsPayload['pending'], $route['pending']);
        $pending = $pending === [] ? null : $pending;

        $businessName = $this->optionalString($payload['business_name'] ?? null)
            ?? $this->optionalString($payload['customer_name'] ?? null);
        if ($businessName === null) {
            throw ValidationException::withMessages(['business_name' => 'Business or customer name is required.']);
        }

        return [
            'external_reference' => $externalReference,
            'enquiry_date' => Carbon::now(config('app.timezone'))->toDateString(),
            'party_name' => null,
            'customer_party_id' => null,
            'consignor_party_id' => null,
            'consignee_party_id' => null,
            'from_state_id' => $route['from_state_id'],
            'from_city_id' => $route['from_city_id'],
            'to_state_id' => $route['to_state_id'],
            'to_city_id' => $route['to_city_id'],
            'external_customer_name' => $businessName,
            'external_customer_phone' => $this->optionalString($payload['contact_number'] ?? $payload['customer_phone'] ?? null),
            'external_customer_email' => $this->optionalEmail($payload['email'] ?? $payload['customer_email'] ?? null),
            'external_business_name' => $this->optionalString($payload['business_name'] ?? null) ?? $businessName,
            'external_contact_person_name' => $this->optionalString($payload['contact_person_name'] ?? null),
            'pickup_location' => $route['pickup_location'] ?? '',
            'delivery_location' => $route['delivery_location'] ?? '',
            'vehicle_type_id' => $vehicle['vehicle_type_id'],
            'vehicle_quantity' => $this->normalizeVehicleQuantity($payload, defaultWhenMissing: 1),
            'expected_loading_date' => $this->normalizeDate(
                $payload['pickup_date'] ?? $payload['expected_loading_date'] ?? null,
                'expected_loading_date',
            ),
            'instructions' => $this->optionalString($payload['notes'] ?? $payload['instructions'] ?? null),
            'pending_master_resolution' => $pending,
            'goods' => $goodsPayload['goods'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeVehicleQuantity(array $payload, int $defaultWhenMissing = 1): int
    {
        if (array_key_exists('vehicle_types', $payload) && is_array($payload['vehicle_types'])) {
            throw ValidationException::withMessages(['vehicle_type' => 'Exactly one vehicle type is required; arrays are not accepted.']);
        }

        if (! array_key_exists('vehicle_quantity', $payload) || $payload['vehicle_quantity'] === null || $payload['vehicle_quantity'] === '') {
            return max(1, $defaultWhenMissing);
        }

        $quantity = $payload['vehicle_quantity'];
        if (! is_numeric($quantity) || (int) $quantity != $quantity) {
            throw ValidationException::withMessages(['vehicle_quantity' => 'Vehicle quantity must be an integer.']);
        }

        $quantity = (int) $quantity;
        if ($quantity < 1) {
            throw ValidationException::withMessages(['vehicle_quantity' => 'Vehicle quantity must be at least 1.']);
        }

        return $quantity;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function optionalEmail(mixed $value): ?string
    {
        $email = $this->optionalString($value);
        if ($email === null) {
            return null;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['customer_email' => 'Customer email is invalid.']);
        }

        return $email;
    }

    private function normalizeDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Date format is invalid.']);
        }
    }

    private function dateToString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private function ensureTenantMatches(Company $company, ?Branch $branch): void
    {
        $tenant = TenantContextHolder::require();
        if ($tenant->companyId !== $company->id) {
            throw new EnquiryAuthorizationException('Tenant company context mismatch.');
        }

        if ($branch !== null && $tenant->branchId !== null && $tenant->branchId !== $branch->id) {
            throw new EnquiryAuthorizationException('Tenant branch context mismatch.');
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(Company $company, ?Branch $branch, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: $branch?->id,
            ownerId: (string) $company->owner_user_id,
            isInternal: false,
            isOwnerScope: $branch === null,
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
