<?php

namespace App\Domains\Consignment;

use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Company\MasterDataVisibilityResolver;
use App\Domains\Consignment\Dependency\CompositeLrDependencyGuard;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\Exceptions\LorryReceiptAuthorizationException;
use App\Domains\Consignment\Exceptions\LorryReceiptConflictException;
use App\Domains\Consignment\Exceptions\LorryReceiptDomainException;
use App\Domains\Consignment\Freight\FreightCalculator;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Numbering\Exceptions\DocumentNumberConflictException;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentNumberAllocation;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\LrAdditionalCharge;
use App\Models\LrEwayBill;
use App\Models\LrGoodsRow;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Unified Lorry Receipt create/update/cancel/soft-delete orchestration (Phase 4 Batch E).
 */
final class LorryReceiptService
{
    public function __construct(
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
        private readonly FreightCalculator $freight = new FreightCalculator,
        private readonly MasterDataVisibilityResolver $visibility = new MasterDataVisibilityResolver,
        private readonly ?CompositeLrDependencyGuard $dependencyGuard = null,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly LrFinancialImmutabilityService $financialImmutability = new LrFinancialImmutabilityService,
    ) {}

    private function dependencyGuard(): CompositeLrDependencyGuard
    {
        return $this->dependencyGuard ?? app(CompositeLrDependencyGuard::class);
    }

    /**
     * @return LengthAwarePaginator<int, LorryReceipt>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $query = null,
        ?string $status = null,
        ?string $workflowContext = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['goodsRows', 'ewayBills', 'additionalCharges', 'consignor', 'consignee'])
            ->orderByDesc('lr_date')
            ->orderByDesc('created_at');

        $q = trim((string) $query);
        if ($q !== '') {
            $builder->where(function ($inner) use ($q): void {
                $inner->where('lr_number', 'ilike', '%'.$q.'%')
                    ->orWhere('from_text', 'ilike', '%'.$q.'%')
                    ->orWhere('to_text', 'ilike', '%'.$q.'%');
            });
        }

        if ($status !== null && $status !== '') {
            $statusEnum = LrStatus::tryFrom($status);
            if ($statusEnum === null) {
                throw ValidationException::withMessages(['status' => 'Invalid LR status filter.']);
            }
            $builder->where('status', $statusEnum->value);
        }

        if ($workflowContext !== null && $workflowContext !== '') {
            $contextEnum = LrWorkflowContext::tryFrom($workflowContext);
            if ($contextEnum === null) {
                throw ValidationException::withMessages(['workflow_context' => 'Invalid workflow context filter.']);
            }
            $builder->where('workflow_context', $contextEnum->value);
        }

        return $builder->paginate(min(100, max(1, $perPage)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(Company $company, Branch $branch, User $actor, array $payload): LorryReceipt
    {
        if ($branch->company_id !== $company->id) {
            throw new LorryReceiptDomainException('Branch does not belong to company.');
        }

        $this->ensureTenantMatches($company, $branch);
        $this->assertLifecycleAllowsMutation($company);

        $normalized = $this->normalizePayload($company, $payload, forUpdate: false, lockedContext: null);
        $this->assertContextEntitled($company, $normalized['workflow_context']);

        return $this->auditedTransaction->run(function () use ($company, $branch, $actor, $normalized): LorryReceipt {
            return $this->withTenant($company, $branch, function () use ($company, $branch, $actor, $normalized): LorryReceipt {
                [$lrNumber, $allocation] = $this->allocateNumber($company, $branch, $normalized);

                $lr = new LorryReceipt;
                $lr->forceFill([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'workflow_context' => $normalized['workflow_context'],
                    'lr_number' => $lrNumber,
                    'lr_date' => $normalized['lr_date'],
                    'from_text' => $normalized['from_text'],
                    'to_text' => $normalized['to_text'],
                    'from_station_id' => $normalized['from_station_id'],
                    'to_station_id' => $normalized['to_station_id'],
                    'consignor_party_id' => $normalized['consignor_party_id'],
                    'consignee_party_id' => $normalized['consignee_party_id'],
                    'vehicle_id' => $normalized['vehicle_id'],
                    'billing_customer_party_id' => $normalized['billing_customer_party_id'],
                    'status' => LrStatus::Pending,
                    'goods_value' => $normalized['goods_value'],
                    'freight_total' => $normalized['freight_total'],
                    'additional_charges_total' => $normalized['additional_charges_total'],
                    'created_by_user_id' => $actor->id,
                ]);
                $lr->save();

                $allocation->forceFill([
                    'allocated_to_id' => $lr->id,
                    'allocated_to_type' => LorryReceipt::class,
                ])->save();

                $this->persistGoodsRows($lr, $normalized['goods']);
                $this->persistEwayBills($lr, $normalized['eway_bills']);
                $this->persistAdditionalCharges($lr, $normalized['additional_charges']);

                $this->audit->write(
                    AuditAction::Created,
                    LorryReceipt::class,
                    $lr->id,
                    remark: null,
                    before: null,
                    after: [
                        'id' => $lr->id,
                        'lr_number' => $lr->lr_number,
                        'workflow_context' => $lr->workflow_context->value,
                        'status' => LrStatus::Pending->value,
                        'freight_total' => $lr->freight_total,
                    ],
                );

                return $lr->fresh(['goodsRows', 'ewayBills', 'additionalCharges']) ?? $lr;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(LorryReceipt $lorryReceipt, User $actor, array $payload): LorryReceipt
    {
        $company = Company::query()->whereKey($lorryReceipt->company_id)->firstOrFail();
        $branch = Branch::query()->whereKey($lorryReceipt->branch_id)->firstOrFail();

        $this->ensureTenantMatches($company, $branch);
        $this->assertBelongsToBranch($lorryReceipt, $company, $branch);

        if ($lorryReceipt->status !== LrStatus::Pending) {
            throw new LorryReceiptDomainException('Only pending Lorry Receipts can be edited.');
        }

        $this->assertLifecycleAllowsMutation($company);

        $lockedContext = $lorryReceipt->workflow_context;
        if (array_key_exists('workflow_context', $payload)) {
            $requested = trim((string) $payload['workflow_context']);
            if ($requested !== '' && $requested !== $lockedContext->value) {
                throw ValidationException::withMessages([
                    'workflow_context' => 'Workflow context cannot be changed after create.',
                ]);
            }
        }

        $payload['workflow_context'] = $lockedContext->value;
        $normalized = $this->normalizePayload($company, $payload, forUpdate: true, lockedContext: $lockedContext);
        $this->financialImmutability->assertFinancialMutationAllowed($lorryReceipt, $normalized);

        return $this->auditedTransaction->run(function () use ($company, $branch, $lorryReceipt, $normalized): LorryReceipt {
            return $this->withTenant($company, $branch, function () use ($lorryReceipt, $normalized): LorryReceipt {
                $before = [
                    'lr_number' => $lorryReceipt->lr_number,
                    'status' => $lorryReceipt->status->value,
                    'freight_total' => $lorryReceipt->freight_total,
                ];

                $lorryReceipt->forceFill([
                    'workflow_context' => $normalized['workflow_context'],
                    'lr_date' => $normalized['lr_date'],
                    'from_text' => $normalized['from_text'],
                    'to_text' => $normalized['to_text'],
                    'from_station_id' => $normalized['from_station_id'],
                    'to_station_id' => $normalized['to_station_id'],
                    'consignor_party_id' => $normalized['consignor_party_id'],
                    'consignee_party_id' => $normalized['consignee_party_id'],
                    // vehicle_id / workflow_context remain immutable after create
                    'billing_customer_party_id' => $normalized['billing_customer_party_id'],
                    'goods_value' => $normalized['goods_value'],
                    'freight_total' => $normalized['freight_total'],
                    'additional_charges_total' => $normalized['additional_charges_total'],
                ])->save();

                $this->replaceGoodsRows($lorryReceipt, $normalized['goods']);
                $this->replaceEwayBills($lorryReceipt, $normalized['eway_bills']);
                $this->replaceAdditionalCharges($lorryReceipt, $normalized['additional_charges']);

                $this->audit->write(
                    AuditAction::Updated,
                    LorryReceipt::class,
                    $lorryReceipt->id,
                    remark: 'Lorry Receipt updated',
                    before: $before,
                    after: [
                        'lr_number' => $lorryReceipt->lr_number,
                        'status' => $lorryReceipt->status->value,
                        'freight_total' => $lorryReceipt->freight_total,
                    ],
                );

                return $lorryReceipt->fresh(['goodsRows', 'ewayBills', 'additionalCharges']) ?? $lorryReceipt;
            });
        });
    }

    public function cancel(LorryReceipt $lorryReceipt, User $actor, string $remark): LorryReceipt
    {
        $company = Company::query()->whereKey($lorryReceipt->company_id)->firstOrFail();
        $branch = Branch::query()->whereKey($lorryReceipt->branch_id)->firstOrFail();

        $this->ensureTenantMatches($company, $branch);
        $this->assertBelongsToBranch($lorryReceipt, $company, $branch);

        $remark = trim($remark);
        if ($remark === '') {
            throw ValidationException::withMessages(['remark' => 'A cancellation remark is required.']);
        }

        if ($lorryReceipt->status === LrStatus::Cancelled) {
            throw new LorryReceiptConflictException('Lorry Receipt is already cancelled.');
        }

        if ($lorryReceipt->status === LrStatus::Delivered || $lorryReceipt->delivered_at !== null) {
            throw new LorryReceiptDomainException('Delivered Lorry Receipts cannot be cancelled.');
        }

        $blockers = $this->dependencyGuard()->blockersForCancel($lorryReceipt);
        if ($blockers !== []) {
            throw new LorryReceiptConflictException(
                'Lorry Receipt cannot be cancelled: '.$blockers[0]->message
            );
        }

        return $this->auditedTransaction->run(function () use ($company, $branch, $lorryReceipt, $remark): LorryReceipt {
            return $this->withTenant($company, $branch, function () use ($lorryReceipt, $remark): LorryReceipt {
                $before = ['status' => $lorryReceipt->status->value];

                $lorryReceipt->forceFill([
                    'status' => LrStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancellation_remark' => $remark,
                ])->save();

                $this->audit->write(
                    AuditAction::Cancelled,
                    LorryReceipt::class,
                    $lorryReceipt->id,
                    remark: $remark,
                    before: $before,
                    after: ['status' => LrStatus::Cancelled->value],
                    cancellationReason: $remark,
                    cancellationAt: $lorryReceipt->cancelled_at,
                );

                return $lorryReceipt->fresh() ?? $lorryReceipt;
            });
        });
    }

    public function softDelete(LorryReceipt $lorryReceipt, User $actor): void
    {
        $company = Company::query()->whereKey($lorryReceipt->company_id)->firstOrFail();
        $branch = Branch::query()->whereKey($lorryReceipt->branch_id)->firstOrFail();

        $this->ensureTenantMatches($company, $branch);
        $this->assertBelongsToBranch($lorryReceipt, $company, $branch);

        if ($lorryReceipt->status !== LrStatus::Pending) {
            throw new LorryReceiptDomainException('Only pending Lorry Receipts can be soft-deleted.');
        }

        $blockers = $this->dependencyGuard()->blockersForSoftDelete($lorryReceipt);
        if ($blockers !== []) {
            throw new LorryReceiptConflictException(
                'Lorry Receipt cannot be deleted: '.$blockers[0]->message
            );
        }

        $this->auditedTransaction->run(function () use ($company, $branch, $lorryReceipt): void {
            $this->withTenant($company, $branch, function () use ($lorryReceipt): void {
                $before = [
                    'lr_number' => $lorryReceipt->lr_number,
                    'status' => $lorryReceipt->status->value,
                ];

                LorryHire::query()
                    ->where('company_id', $lorryReceipt->company_id)
                    ->where('lorry_receipt_id', $lorryReceipt->id)
                    ->where('status', LorryHireStatus::Cancelled->value)
                    ->whereNull('deleted_at')
                    ->each(fn (LorryHire $hire) => $hire->delete());

                $lorryReceipt->goodsRows()->each(fn (LrGoodsRow $row) => $row->delete());
                $lorryReceipt->ewayBills()->each(fn (LrEwayBill $row) => $row->delete());
                $lorryReceipt->additionalCharges()->each(fn (LrAdditionalCharge $row) => $row->delete());
                $lorryReceipt->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    LorryReceipt::class,
                    $lorryReceipt->id,
                    remark: 'Lorry Receipt soft-deleted',
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(
        Company $company,
        array $payload,
        bool $forUpdate = false,
        ?LrWorkflowContext $lockedContext = null,
    ): array {
        $context = $lockedContext;
        if ($context === null) {
            $contextValue = (string) ($payload['workflow_context'] ?? '');
            $context = LrWorkflowContext::tryFrom($contextValue);
        }

        if ($context === null) {
            throw ValidationException::withMessages([
                'workflow_context' => 'Workflow context must be broker, own_fleet, or partload.',
            ]);
        }

        $fromText = $this->optionalString($payload['from_text'] ?? null);
        $toText = $this->optionalString($payload['to_text'] ?? null);
        $fromStationId = $this->optionalUuid($payload['from_station_id'] ?? null);
        $toStationId = $this->optionalUuid($payload['to_station_id'] ?? null);
        $consignorId = $this->optionalUuid($payload['consignor_party_id'] ?? null);
        $consigneeId = $this->optionalUuid($payload['consignee_party_id'] ?? null);
        $vehicleId = $this->optionalUuid($payload['vehicle_id'] ?? null);
        $billingCustomerId = $this->optionalUuid($payload['billing_customer_party_id'] ?? null);

        [
            $fromText,
            $toText,
            $fromStationId,
            $toStationId,
            $consignorId,
            $consigneeId,
        ] = $this->enforceContextFieldShape(
            $context,
            $fromText,
            $toText,
            $fromStationId,
            $toStationId,
            $consignorId,
            $consigneeId,
        );

        $this->assertVehicleForContext($company, $context, $vehicleId, $forUpdate);
        $this->assertBillingCustomer($company, $billingCustomerId);

        $this->assertWorkflowContextFields(
            $company,
            $context,
            $fromText,
            $toText,
            $fromStationId,
            $toStationId,
            $consignorId,
            $consigneeId,
        );

        $goods = $this->normalizeGoodsRows($company, $payload['goods'] ?? null);
        $ewayBills = $this->normalizeEwayBills($payload['eway_bills'] ?? []);
        $charges = $this->normalizeAdditionalCharges($payload['additional_charges'] ?? []);

        $freightTotal = '0.00';
        $goodsValueSum = '0.00';
        $hasGoodsValue = false;
        foreach ($goods as $row) {
            $freightTotal = bcadd($freightTotal, $row['freight_amount'], 2);
            if ($row['goods_value'] !== null) {
                $goodsValueSum = bcadd($goodsValueSum, $row['goods_value'], 2);
                $hasGoodsValue = true;
            }
        }

        $chargesTotal = '0.00';
        foreach ($charges as $charge) {
            $chargesTotal = bcadd($chargesTotal, $charge['amount'], 2);
        }

        $goodsValueOverride = array_key_exists('goods_value', $payload)
            ? $this->optionalMoney($payload['goods_value'], 'goods_value')
            : null;

        $resolvedGoodsValue = $goodsValueOverride;
        if ($resolvedGoodsValue === null && $hasGoodsValue) {
            $resolvedGoodsValue = $goodsValueSum;
        }

        $numberMode = strtolower(trim((string) ($payload['number_mode'] ?? 'auto')));
        if (! in_array($numberMode, ['auto', 'manual'], true)) {
            throw ValidationException::withMessages(['number_mode' => 'Number mode must be auto or manual.']);
        }

        if ($forUpdate) {
            $numberMode = 'auto';
        }

        $manualNumber = null;
        if ($numberMode === 'manual') {
            $manualNumber = trim((string) ($payload['lr_number'] ?? ''));
            if ($manualNumber === '') {
                throw ValidationException::withMessages(['lr_number' => 'Manual LR number is required.']);
            }
        }

        return [
            'workflow_context' => $context,
            'number_mode' => $numberMode,
            'manual_number' => $manualNumber,
            'lr_date' => $this->normalizeDate($payload['lr_date'] ?? null, 'lr_date')
                ?? Carbon::now(config('app.timezone'))->toDateString(),
            'from_text' => $fromText,
            'to_text' => $toText,
            'from_station_id' => $fromStationId,
            'to_station_id' => $toStationId,
            'consignor_party_id' => $consignorId,
            'consignee_party_id' => $consigneeId,
            'vehicle_id' => $vehicleId,
            'billing_customer_party_id' => $billingCustomerId,
            'goods' => $goods,
            'eway_bills' => $ewayBills,
            'additional_charges' => $charges,
            'freight_total' => number_format((float) $freightTotal, 2, '.', ''),
            'additional_charges_total' => number_format((float) $chargesTotal, 2, '.', ''),
            'goods_value' => $resolvedGoodsValue,
        ];
    }

    private function assertVehicleForContext(
        Company $company,
        LrWorkflowContext $context,
        ?string $vehicleId,
        bool $forUpdate,
    ): void {
        if ($context === LrWorkflowContext::Partload) {
            if ($vehicleId !== null && ! $forUpdate) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Vehicle is not used for Partload LRs.',
                ]);
            }

            return;
        }

        // Broker / OwnFleet require a vehicle on create. Updates keep the persisted vehicle.
        if ($forUpdate) {
            return;
        }

        if ($vehicleId === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle is required for Broker and OwnFleet LRs.',
            ]);
        }

        $exists = Vehicle::query()
            ->where('company_id', $company->id)
            ->whereKey($vehicleId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Selected vehicle is invalid for this company.',
            ]);
        }
    }

    private function assertBillingCustomer(Company $company, ?string $partyId): void
    {
        if ($partyId === null) {
            return;
        }

        $party = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($partyId)
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages([
                'billing_customer_party_id' => 'Billing Customer is invalid for this company.',
            ]);
        }

        $hasRole = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $party->id)
            ->where('role', PartyRoleType::Customer->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->exists();

        if (! $hasRole) {
            throw ValidationException::withMessages([
                'billing_customer_party_id' => 'Billing Customer must have an active Customer role.',
            ]);
        }
    }

    /**
     * Reject fields that do not belong to the resolved workflow context and clear
     * hidden counterparts to null for persistence.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string, 5: ?string}
     */
    private function enforceContextFieldShape(
        LrWorkflowContext $context,
        ?string $fromText,
        ?string $toText,
        ?string $fromStationId,
        ?string $toStationId,
        ?string $consignorId,
        ?string $consigneeId,
    ): array {
        $errors = [];

        if ($context === LrWorkflowContext::Broker) {
            if ($fromStationId !== null) {
                $errors['from_station_id'] = 'From station is not used for Broker LRs.';
            }
            if ($toStationId !== null) {
                $errors['to_station_id'] = 'To station is not used for Broker LRs.';
            }
            $fromStationId = null;
            $toStationId = null;
        } else {
            if ($fromText !== null) {
                $errors['from_text'] = 'From text is not used for OwnFleet/Partload LRs.';
            }
            if ($toText !== null) {
                $errors['to_text'] = 'To text is not used for OwnFleet/Partload LRs.';
            }
            $fromText = null;
            $toText = null;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$fromText, $toText, $fromStationId, $toStationId, $consignorId, $consigneeId];
    }

    private function assertLifecycleAllowsMutation(Company $company): void
    {
        if (! $this->entitlementGate->allowsBranchMutation($company)) {
            $decision = $this->entitlementGate->evaluate($company);
            throw new LorryReceiptAuthorizationException(
                $decision['restriction_reason'] ?? 'Branch mutations are not permitted for the current subscription lifecycle status.'
            );
        }
    }

    private function assertContextEntitled(Company $company, LrWorkflowContext $context): void
    {
        if (! $this->entitlements->isEntitled($company, $context)) {
            throw new LorryReceiptAuthorizationException(
                'Workflow context is not entitled by the company subscription.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{0: string, 1: DocumentNumberAllocation}
     */
    private function allocateNumber(Company $company, Branch $branch, array $normalized): array
    {
        $scopeType = $this->visibility->isShared($company)
            ? DocumentNumberScopeType::Company
            : DocumentNumberScopeType::Branch;
        $scopeBranchId = $scopeType === DocumentNumberScopeType::Branch ? $branch->id : null;

        try {
            if ($normalized['number_mode'] === 'manual') {
                $allocation = $this->numbers->allocateManual(
                    companyId: $company->id,
                    documentType: DocumentNumberType::LorryReceipt,
                    scopeType: $scopeType,
                    number: (string) $normalized['manual_number'],
                    branchId: $scopeBranchId,
                );

                return [(string) $normalized['manual_number'], $allocation];
            }

            $result = $this->numbers->allocateNext(
                companyId: $company->id,
                documentType: DocumentNumberType::LorryReceipt,
                scopeType: $scopeType,
                branchId: $scopeBranchId,
            );

            return [$result['number'], $result['allocation']];
        } catch (DocumentNumberConflictException $e) {
            throw new LorryReceiptConflictException($e->getMessage(), previous: $e);
        }
    }

    private function assertWorkflowContextFields(
        Company $company,
        LrWorkflowContext $context,
        ?string $fromText,
        ?string $toText,
        ?string $fromStationId,
        ?string $toStationId,
        ?string $consignorId,
        ?string $consigneeId,
    ): void {
        if ($fromStationId !== null) {
            $this->assertStation($company, $fromStationId, 'from_station_id');
        }
        if ($toStationId !== null) {
            $this->assertStation($company, $toStationId, 'to_station_id');
        }
        if ($consignorId !== null) {
            $this->assertParty($company, $consignorId, 'consignor_party_id');
        }
        if ($consigneeId !== null) {
            $this->assertParty($company, $consigneeId, 'consignee_party_id');
        }

        match ($context) {
            LrWorkflowContext::Broker => $this->assertBrokerFields($consignorId, $consigneeId),
            LrWorkflowContext::OwnFleet => $this->assertOwnFleetFields($fromStationId, $toStationId),
            LrWorkflowContext::Partload => $this->assertPartloadFields(
                $fromStationId,
                $toStationId,
                $consignorId,
                $consigneeId,
            ),
        };
    }

    private function assertBrokerFields(?string $consignorId, ?string $consigneeId): void
    {
        $errors = [];
        if ($consignorId === null) {
            $errors['consignor_party_id'] = 'Consignor is required for Broker LRs.';
        }
        if ($consigneeId === null) {
            $errors['consignee_party_id'] = 'Consignee is required for Broker LRs.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertOwnFleetFields(?string $fromStationId, ?string $toStationId): void
    {
        $errors = [];
        if ($fromStationId === null) {
            $errors['from_station_id'] = 'From station is required for OwnFleet LRs.';
        }
        if ($toStationId === null) {
            $errors['to_station_id'] = 'To station is required for OwnFleet LRs.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertPartloadFields(
        ?string $fromStationId,
        ?string $toStationId,
        ?string $consignorId,
        ?string $consigneeId,
    ): void {
        $errors = [];
        if ($fromStationId === null) {
            $errors['from_station_id'] = 'From station is required for Partload LRs.';
        }
        if ($toStationId === null) {
            $errors['to_station_id'] = 'To station is required for Partload LRs.';
        }
        if ($consignorId === null) {
            $errors['consignor_party_id'] = 'Consignor is required for Partload LRs.';
        }
        if ($consigneeId === null) {
            $errors['consignee_party_id'] = 'Consignee is required for Partload LRs.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeGoodsRows(Company $company, mixed $rows): array
    {
        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages(['goods' => 'At least one goods row is required.']);
        }

        $normalized = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(["goods.{$index}" => 'Goods row must be an object.']);
            }

            $rateTypeValue = (string) ($row['freight_rate_type'] ?? '');
            $rateType = FreightRateType::tryFrom($rateTypeValue);
            if ($rateType === null) {
                throw ValidationException::withMessages([
                    "goods.{$index}.freight_rate_type" => 'Invalid freight rate type.',
                ]);
            }

            $goodsTypeId = $this->optionalUuid($row['goods_type_id'] ?? null);
            $packageTypeId = $this->optionalUuid($row['package_type_id'] ?? null);
            if ($goodsTypeId !== null) {
                $this->assertCatalogueItem($company, $goodsTypeId, OwnerCatalogueType::GoodsType, "goods.{$index}.goods_type_id");
            }
            if ($packageTypeId !== null) {
                $this->assertCatalogueItem($company, $packageTypeId, OwnerCatalogueType::PackageType, "goods.{$index}.package_type_id");
            }

            $packageCount = $this->optionalDecimal($row['package_count'] ?? null, "goods.{$index}.package_count", 3);
            $actualWeight = $this->optionalDecimal($row['actual_weight'] ?? null, "goods.{$index}.actual_weight", 3);
            $chargedWeight = $this->optionalDecimal($row['charged_weight'] ?? null, "goods.{$index}.charged_weight", 3);
            $rate = $this->requireDecimal($row['freight_rate'] ?? null, "goods.{$index}.freight_rate", 4);

            try {
                $freightAmount = $this->freight->compute(
                    $rateType,
                    $rate,
                    $packageCount,
                    $actualWeight,
                    $chargedWeight,
                );
            } catch (LorryReceiptDomainException $e) {
                throw ValidationException::withMessages([
                    "goods.{$index}.freight_rate" => $e->getMessage(),
                ]);
            }

            $normalized[] = [
                'goods_type_id' => $goodsTypeId,
                'package_type_id' => $packageTypeId,
                'goods_description' => $this->optionalString($row['goods_description'] ?? null),
                'package_count' => $packageCount,
                'actual_weight' => $actualWeight,
                'charged_weight' => $chargedWeight,
                'freight_rate_type' => $rateType,
                'freight_rate' => $rate,
                'freight_amount' => $freightAmount,
                'goods_value' => $this->optionalMoney($row['goods_value'] ?? null, "goods.{$index}.goods_value"),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeEwayBills(mixed $rows): array
    {
        if ($rows === null || $rows === '') {
            return [];
        }

        if (! is_array($rows)) {
            throw ValidationException::withMessages(['eway_bills' => 'E-way bills must be an array.']);
        }

        $normalized = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(["eway_bills.{$index}" => 'E-way bill must be an object.']);
            }

            $number = trim((string) ($row['ewb_number'] ?? ''));
            if ($number === '') {
                throw ValidationException::withMessages(["eway_bills.{$index}.ewb_number" => 'EWB number is required.']);
            }

            $validUntil = $this->normalizeDate($row['valid_until'] ?? null, "eway_bills.{$index}.valid_until");
            if ($validUntil === null) {
                throw ValidationException::withMessages(["eway_bills.{$index}.valid_until" => 'Valid until is required.']);
            }

            $normalized[] = [
                'ewb_number' => $number,
                'ewb_date' => $this->normalizeDate($row['ewb_date'] ?? null, "eway_bills.{$index}.ewb_date"),
                'valid_until' => $validUntil,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{description: string, amount: numeric-string}>
     */
    private function normalizeAdditionalCharges(mixed $rows): array
    {
        if ($rows === null || $rows === '') {
            return [];
        }

        if (! is_array($rows)) {
            throw ValidationException::withMessages(['additional_charges' => 'Additional charges must be an array.']);
        }

        $normalized = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(["additional_charges.{$index}" => 'Charge must be an object.']);
            }

            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                throw ValidationException::withMessages([
                    "additional_charges.{$index}.description" => 'Charge description is required.',
                ]);
            }

            $amount = $this->requireDecimal($row['amount'] ?? null, "additional_charges.{$index}.amount", 2);
            if (bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    "additional_charges.{$index}.amount" => 'Charge amount must be greater than zero.',
                ]);
            }

            $normalized[] = [
                'description' => $description,
                'amount' => $amount,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $goods
     */
    private function persistGoodsRows(LorryReceipt $lr, array $goods): void
    {
        foreach ($goods as $index => $row) {
            LrGoodsRow::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $lr->company_id,
                'lorry_receipt_id' => $lr->id,
                'position' => $index + 1,
                'goods_type_id' => $row['goods_type_id'],
                'package_type_id' => $row['package_type_id'],
                'goods_description' => $row['goods_description'],
                'package_count' => $row['package_count'],
                'actual_weight' => $row['actual_weight'],
                'charged_weight' => $row['charged_weight'],
                'freight_rate_type' => $row['freight_rate_type'],
                'freight_rate' => $row['freight_rate'],
                'freight_amount' => $row['freight_amount'],
                'goods_value' => $row['goods_value'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $goods
     */
    private function replaceGoodsRows(LorryReceipt $lr, array $goods): void
    {
        $lr->goodsRows()->each(fn (LrGoodsRow $row) => $row->delete());
        $this->persistGoodsRows($lr, $goods);
    }

    /**
     * @param  list<array<string, mixed>>  $bills
     */
    private function persistEwayBills(LorryReceipt $lr, array $bills): void
    {
        foreach ($bills as $bill) {
            LrEwayBill::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $lr->company_id,
                'lorry_receipt_id' => $lr->id,
                'ewb_number' => $bill['ewb_number'],
                'ewb_date' => $bill['ewb_date'],
                'valid_until' => $bill['valid_until'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $bills
     */
    private function replaceEwayBills(LorryReceipt $lr, array $bills): void
    {
        $lr->ewayBills()->each(fn (LrEwayBill $row) => $row->delete());
        $this->persistEwayBills($lr, $bills);
    }

    /**
     * @param  list<array{description: string, amount: numeric-string}>  $charges
     */
    private function persistAdditionalCharges(LorryReceipt $lr, array $charges): void
    {
        foreach ($charges as $charge) {
            LrAdditionalCharge::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $lr->company_id,
                'lorry_receipt_id' => $lr->id,
                'description' => $charge['description'],
                'amount' => $charge['amount'],
            ]);
        }
    }

    /**
     * @param  list<array{description: string, amount: numeric-string}>  $charges
     */
    private function replaceAdditionalCharges(LorryReceipt $lr, array $charges): void
    {
        $lr->additionalCharges()->each(fn (LrAdditionalCharge $row) => $row->delete());
        $this->persistAdditionalCharges($lr, $charges);
    }

    private function assertStation(Company $company, string $stationId, string $field): void
    {
        $exists = Station::query()
            ->whereKey($stationId)
            ->where('owner_user_id', $company->owner_user_id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([$field => 'Station is invalid for this company.']);
        }
    }

    private function assertParty(Company $company, string $partyId, string $field): void
    {
        $exists = Party::query()
            ->whereKey($partyId)
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([$field => 'Party is invalid for this company.']);
        }
    }

    private function assertCatalogueItem(
        Company $company,
        string $itemId,
        OwnerCatalogueType $type,
        string $field,
    ): void {
        $exists = OwnerCatalogueItem::query()
            ->whereKey($itemId)
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', $type->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([$field => 'Catalogue item is invalid.']);
        }
    }

    private function assertBelongsToBranch(LorryReceipt $lr, Company $company, Branch $branch): void
    {
        if ($lr->company_id !== $company->id || $lr->branch_id !== $branch->id) {
            throw new LorryReceiptAuthorizationException('Lorry Receipt does not belong to this branch.');
        }
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function optionalUuid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = trim((string) $value);

        return $id === '' ? null : $id;
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

    private function optionalDecimal(mixed $value, string $field, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->requireDecimal($value, $field, $scale);
    }

    /**
     * @return numeric-string
     */
    private function requireDecimal(mixed $value, string $field, int $scale): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'Must be a number.']);
        }

        if ((float) $value < 0) {
            throw ValidationException::withMessages([$field => 'Must be nonnegative.']);
        }

        return number_format((float) $value, $scale, '.', '');
    }

    private function optionalMoney(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->requireDecimal($value, $field, 2);
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        $tenant = TenantContextHolder::require();
        if ($tenant->companyId !== $company->id) {
            throw new LorryReceiptAuthorizationException('Tenant company context mismatch.');
        }

        if ($tenant->branchId !== null && $tenant->branchId !== $branch->id) {
            throw new LorryReceiptAuthorizationException('Tenant branch context mismatch.');
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
