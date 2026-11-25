<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Tax\TransporterTdsCalculationService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\LorryHireGoodsLine;
use App\Models\LorryHireTdsSnapshot;
use App\Models\LorryReceipt;
use App\Models\LrGoodsRow;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Broker Lorry Hire orchestration — Market vehicle Broker LR → hire + TDS snapshot (PRD §13).
 */
final class LorryHireService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
        private readonly TransporterTdsCalculationService $tds = new TransporterTdsCalculationService,
        private readonly LorryHireCalculationService $calculations = new LorryHireCalculationService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, LorryHire>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = LorryHire::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with([
                'lorryReceipt.billingCustomer',
                'lorryReceipt.fromStation',
                'lorryReceipt.toStation',
                'vehicle',
                'transporter',
                'goodsLines',
            ])
            ->orderByDesc('created_at');

        if (filled($status)) {
            $builder->where('status', $status);
        }

        return $builder->paginate($perPage);
    }

    public function findForBranch(Company $company, Branch $branch, string $hireId): LorryHire
    {
        $this->ensureTenantMatches($company, $branch);

        $hire = LorryHire::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereKey($hireId)
            ->whereNull('deleted_at')
            ->with([
                'lorryReceipt.goodsRows',
                'lorryReceipt.billingCustomer',
                'lorryReceipt.consignor',
                'lorryReceipt.consignee',
                'lorryReceipt.fromStation',
                'lorryReceipt.toStation',
                'vehicle',
                'transporter',
                'goodsLines.lrGoodsRow.goodsType',
                'tdsSnapshot',
                'vouchers',
                'adjustments',
            ])
            ->first();

        if ($hire === null) {
            throw new BrokerDomainException('Lorry Hire not found.');
        }

        return $hire;
    }

    public function hireForLorryReceipt(LorryReceipt $lorryReceipt): ?LorryHire
    {
        return LorryHire::query()
            ->where('company_id', $lorryReceipt->company_id)
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', LorryHireStatus::Cancelled->value)
            ->first();
    }

    /**
     * @param  array{
     *     hire_date?: string|null,
     *     remarks?: string|null,
     *     transporter_party_id?: string|null,
     *     tds_override_rate?: string|null,
     *     tds_override_remark?: string|null,
     *     goods_lines?: list<array{
     *         lr_goods_row_id: string,
     *         rate_type?: string,
     *         rate: string|float|int,
     *         is_fixed_amount?: bool
     *     }>,
     *     goods?: list<array{
     *         lr_goods_row_id: string,
     *         freight_rate_type?: string,
     *         freight_rate?: string|float|int,
     *         rate_type?: string,
     *         rate?: string|float|int,
     *         is_fixed_amount?: bool,
     *         loading_weight?: string|float|int|null
     *     }>
     * }  $payload
     */
    public function createFromLorryReceipt(
        LorryReceipt $lorryReceipt,
        User $actor,
        array $payload,
    ): LorryHire {
        $company = Company::query()->findOrFail($lorryReceipt->company_id);
        $branch = Branch::query()->findOrFail($lorryReceipt->branch_id);

        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);
        $this->assertLorryReceiptEligible($lorryReceipt);

        $vehicle = $this->resolveMarketVehicle($lorryReceipt, $company, $branch);
        $transporter = $this->resolveInheritedPayee(
            $lorryReceipt,
            $company,
            $branch,
            filled($payload['transporter_party_id'] ?? null)
                ? (string) $payload['transporter_party_id']
                : null,
        );

        if (array_key_exists('goods_lines', $payload) && $payload['goods_lines'] !== []) {
            $goodsLinesInput = $payload['goods_lines'];
        } elseif (array_key_exists('goods', $payload) && $payload['goods'] !== []) {
            $goodsLinesInput = $payload['goods'];
        } else {
            $goodsLinesInput = null;
        }

        if ($goodsLinesInput === null) {
            throw ValidationException::withMessages([
                'goods_lines' => 'At least one transporter freight goods line is required.',
            ]);
        }

        $computedLines = $this->computeGoodsLines($lorryReceipt, $goodsLinesInput);
        $freightTotal = '0.00';
        foreach ($computedLines as $line) {
            $freightTotal = bcadd($freightTotal, $line['freight_amount'], 2);
        }

        $role = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $transporter->id)
            ->where('role', PartyRoleType::Transporter->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->firstOrFail();

        $roleAttrs = is_array($role->role_attributes) ? $role->role_attributes : [];
        $declarationType = strtolower((string) ($roleAttrs['declaration_type'] ?? 'owner'));
        if (! in_array($declarationType, ['owner', 'agency'], true)) {
            $declarationType = 'owner';
        }

        $tdsContext = $this->tds->buildContextFromTransporter(
            [
                'pan' => $transporter->pan,
            ],
            $roleAttrs,
        );
        $tdsContext['declaration_type'] = $declarationType;

        $allowOverride = $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::BROKER_LORRY_HIRE_EDIT,
            $company,
            $branch,
        ) || $actor->id === $company->owner_user_id;

        $tdsResult = $this->tds->calculate(
            $tdsContext,
            overrideRate: filled($payload['tds_override_rate'] ?? null)
                ? (string) $payload['tds_override_rate']
                : null,
            overrideRemark: filled($payload['tds_override_remark'] ?? null)
                ? (string) $payload['tds_override_remark']
                : null,
            allowOverride: $allowOverride,
        );

        $tdsPercent = number_format((float) $tdsResult->applicableRate, 3, '.', '');
        $tdsAmount = $this->calculations->tdsAmountFromFreight($freightTotal, $tdsPercent);
        $outstanding = bcsub($freightTotal, $tdsAmount, 2);

        $hireDate = filled($payload['hire_date'] ?? null)
            ? CarbonImmutable::parse((string) $payload['hire_date'])->startOfDay()
            : CarbonImmutable::now($this->companyTimezone($company))->startOfDay();

        try {
            return $this->auditedTransaction->run(function () use (
                $lorryReceipt,
                $actor,
                $company,
                $branch,
                $vehicle,
                $transporter,
                $declarationType,
                $computedLines,
                $freightTotal,
                $tdsPercent,
                $tdsAmount,
                $outstanding,
                $tdsResult,
                $hireDate,
                $payload,
            ): LorryHire {
                return $this->withTenant($company, $branch, function () use (
                    $lorryReceipt,
                    $actor,
                    $company,
                    $branch,
                    $vehicle,
                    $transporter,
                    $declarationType,
                    $computedLines,
                    $freightTotal,
                    $tdsPercent,
                    $tdsAmount,
                    $outstanding,
                    $tdsResult,
                    $hireDate,
                    $payload,
                ): LorryHire {
                    $existing = LorryHire::query()
                        ->where('company_id', $company->id)
                        ->where('lorry_receipt_id', $lorryReceipt->id)
                        ->whereNull('deleted_at')
                        ->where('status', '!=', LorryHireStatus::Cancelled->value)
                        ->lockForUpdate()
                        ->exists();

                    if ($existing) {
                        throw new BrokerConflictException('This Broker LR already has an active Lorry Hire.');
                    }

                    $allocation = $this->numbers->allocateNext(
                        companyId: $company->id,
                        documentType: DocumentNumberType::LorryHire,
                        scopeType: DocumentNumberScopeType::Branch,
                        branchId: $branch->id,
                    );

                    $hire = LorryHire::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'lorry_receipt_id' => $lorryReceipt->id,
                        'hire_number' => $allocation['number'],
                        'hire_date' => $hireDate,
                        'vehicle_id' => $vehicle->id,
                        'transporter_party_id' => $transporter->id,
                        'declaration_type' => $declarationType,
                        'status' => LorryHireStatus::Pending,
                        'freight_total' => $freightTotal,
                        'tds_percent' => $tdsPercent,
                        'tds_amount' => $tdsAmount,
                        'paid_amount' => '0.00',
                        'outstanding_amount' => $outstanding,
                        'remarks' => filled($payload['remarks'] ?? null) ? trim((string) $payload['remarks']) : null,
                        'created_by_user_id' => $actor->id,
                    ]);

                    foreach ($computedLines as $line) {
                        LorryHireGoodsLine::query()->create([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'lorry_hire_id' => $hire->id,
                            'lr_goods_row_id' => $line['lr_goods_row_id'],
                            'rate_type' => $line['rate_type']->value,
                            'rate_semantics' => $line['rate_semantics'] instanceof FreightRateSemantics
                                ? $line['rate_semantics']->value
                                : (string) $line['rate_semantics'],
                            'rate' => $line['rate'],
                            'is_fixed_amount' => $line['is_fixed_amount'],
                            'freight_amount' => $line['freight_amount'],
                            'loading_weight' => $line['loading_weight'],
                        ]);
                    }

                    LorryHireTdsSnapshot::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'lorry_hire_id' => $hire->id,
                        'freight_basis' => $freightTotal,
                        'calculated_rate' => $tdsResult->calculatedRate,
                        'applicable_rate' => $tdsResult->applicableRate,
                        'tds_amount' => $tdsAmount,
                        'matched_rule_id' => $tdsResult->matchedRuleId,
                        'rule_code' => $tdsResult->ruleCode,
                        'rule_priority' => $tdsResult->rulePriority,
                        'pan_status' => $tdsResult->panStatus,
                        'declaration_status' => $tdsResult->declarationStatus,
                        'goods_vehicle_count' => $tdsResult->goodsVehicleCount,
                        'declaration_type' => $declarationType,
                        'override_state' => $tdsResult->overrideState,
                        'explanation' => $tdsResult->explanation,
                        'snapshotted_at' => CarbonImmutable::now('UTC'),
                    ]);

                    if ($lorryReceipt->status === LrStatus::Pending) {
                        $lorryReceipt->forceFill([
                            'status' => LrStatus::InProgress,
                        ])->save();
                    }

                    $this->audit->write(
                        AuditAction::Created,
                        LorryHire::class,
                        $hire->id,
                        remark: 'Create Broker Lorry Hire from LR '.$lorryReceipt->lr_number,
                        before: null,
                        after: [
                            'lorry_receipt_id' => $lorryReceipt->id,
                            'hire_number' => $hire->hire_number,
                            'freight_total' => $freightTotal,
                            'tds_amount' => $tdsAmount,
                            'status' => LorryHireStatus::Pending->value,
                            'lorry_receipt_status' => LrStatus::InProgress->value,
                        ],
                    );

                    return $hire->fresh([
                        'lorryReceipt',
                        'vehicle',
                        'transporter',
                        'goodsLines',
                        'tdsSnapshot',
                    ]) ?? $hire;
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'lorry_hires_company_lr_active_unique')
                || str_contains($message, 'lorry_receipt_id')) {
                throw new BrokerConflictException('This Broker LR already has an active Lorry Hire.', previous: $e);
            }

            throw new BrokerConflictException(
                'Could not create Lorry Hire due to a unique constraint conflict.',
                previous: $e,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $goodsLinesInput
     * @return list<array{
     *     lr_goods_row_id: string,
     *     rate_type: FreightRateType,
     *     rate: string,
     *     is_fixed_amount: bool,
     *     freight_amount: numeric-string,
     *     loading_weight: numeric-string
     * }>
     */
    private function computeGoodsLines(LorryReceipt $lorryReceipt, array $goodsLinesInput): array
    {
        $lorryReceipt->loadMissing('goodsRows');
        $rowsById = $lorryReceipt->goodsRows->keyBy('id');
        $seen = [];
        $computed = [];

        foreach ($goodsLinesInput as $index => $input) {
            $rowId = (string) ($input['lr_goods_row_id'] ?? '');
            if ($rowId === '' || ! $rowsById->has($rowId)) {
                throw ValidationException::withMessages([
                    "goods_lines.{$index}.lr_goods_row_id" => 'Goods line must reference an LR goods row.',
                ]);
            }
            if (isset($seen[$rowId])) {
                throw ValidationException::withMessages([
                    "goods_lines.{$index}.lr_goods_row_id" => 'Duplicate LR goods row on Lorry Hire.',
                ]);
            }
            $seen[$rowId] = true;

            /** @var LrGoodsRow $row */
            $row = $rowsById->get($rowId);
            $isFixed = (bool) ($input['is_fixed_amount'] ?? false);
            $rateTypeValue = (string) (
                $input['freight_rate_type']
                ?? $input['rate_type']
                ?? ($isFixed ? FreightRateType::FixedAmount->value : $row->freight_rate_type->value)
            );
            $rateType = FreightRateType::tryFrom($rateTypeValue);
            if ($rateType === null) {
                throw ValidationException::withMessages([
                    "goods_lines.{$index}.freight_rate_type" => 'Invalid transporter freight rate type.',
                ]);
            }
            if ($isFixed || $rateType === FreightRateType::FixedAmount) {
                $rateType = FreightRateType::FixedAmount;
                $isFixed = true;
            }

            $rateRaw = $input['freight_rate'] ?? $input['rate'] ?? null;
            $rate = $rateRaw !== null && $rateRaw !== '' ? (string) $rateRaw : '';
            if ($rate === '' || ! is_numeric($rate)) {
                throw ValidationException::withMessages([
                    "goods_lines.{$index}.freight_rate" => 'Transporter rate is required.',
                ]);
            }

            $loadingWeight = filled($input['loading_weight'] ?? null)
                ? $this->formattedWeight((string) $input['loading_weight'])
                : $this->loadingWeightFromGoodsRow($row);

            // Authoritative semantics come from the linked Bilty goods row — never trust client overrides.
            $semantics = $row->rate_semantics ?? FreightRateSemantics::WeightKg;

            $freightAmount = $this->calculations->goodsLineFreight([
                'rate_type' => $rateType,
                'rate' => $rate,
                'is_fixed_amount' => $isFixed,
                'package_count' => $row->package_count,
                'actual_weight' => $loadingWeight,
                'charged_weight' => $loadingWeight,
                'rate_semantics' => $semantics,
            ]);

            $computed[] = [
                'lr_goods_row_id' => $rowId,
                'rate_type' => $rateType,
                'rate' => number_format((float) $rate, 4, '.', ''),
                'is_fixed_amount' => $isFixed,
                'freight_amount' => $freightAmount,
                'loading_weight' => $loadingWeight,
                'rate_semantics' => $semantics,
            ];
        }

        return $computed;
    }

    /**
     * @return numeric-string
     */
    private function loadingWeightFromGoodsRow(LrGoodsRow $row): string
    {
        $charged = $row->charged_weight;
        if ($charged !== null) {
            $chargedString = (string) $charged;
            if (is_numeric($chargedString) && bccomp($chargedString, '0', 3) > 0) {
                return $this->formattedWeight($chargedString);
            }
        }

        $actual = $row->actual_weight;
        if ($actual !== null) {
            return $this->formattedWeight((string) $actual);
        }

        return '0.000';
    }

    /**
     * @return numeric-string
     */
    private function formattedWeight(string $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function resolveMarketVehicle(LorryReceipt $lorryReceipt, Company $company, Branch $branch): Vehicle
    {
        if ($lorryReceipt->vehicle_id === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Broker LR must have a Market vehicle.',
            ]);
        }

        $vehicle = Vehicle::query()
            ->where('company_id', $company->id)
            ->whereKey($lorryReceipt->vehicle_id)
            ->whereNull('deleted_at')
            ->first();

        if ($vehicle === null || ! $vehicle->is_active) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle is not available in this company.',
            ]);
        }

        if ($vehicle->ownership !== VehicleOwnership::Market) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Lorry Hire requires a Market vehicle.',
            ]);
        }

        $vehicleVisible = Vehicle::query()
            ->whereKey($vehicle->id)
            ->visibleInBranch($company->id, $branch->id)
            ->exists();

        if (! $vehicleVisible) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle is not visible in this branch.',
            ]);
        }

        return $vehicle;
    }

    /**
     * Hire Payee is inherited from the Bilty intended payee — not re-selected on Hire create.
     * Client-submitted transporter_party_id may only match the inherited payee (forgery rejected).
     */
    private function resolveInheritedPayee(
        LorryReceipt $lorryReceipt,
        Company $company,
        Branch $branch,
        ?string $submittedTransporterPartyId,
    ): Party {
        $intendedId = filled($lorryReceipt->intended_payee_party_id)
            ? (string) $lorryReceipt->intended_payee_party_id
            : null;

        if ($intendedId === null) {
            throw ValidationException::withMessages([
                'transporter_party_id' => 'This Bilty has no intended Payee. Correct the Bilty Payee before creating Lorry Hire.',
            ]);
        }

        if ($submittedTransporterPartyId !== null
            && $submittedTransporterPartyId !== ''
            && $submittedTransporterPartyId !== $intendedId) {
            throw ValidationException::withMessages([
                'transporter_party_id' => 'Lorry Hire Payee must match the Bilty intended Payee. Edit the Bilty to correct the Payee.',
            ]);
        }

        $party = Party::query()
            ->visibleInBranch($company->id, $branch->id)
            ->whereKey($intendedId)
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages([
                'transporter_party_id' => 'The Bilty intended Payee is not available for transactions in this branch. Correct the Bilty Payee before creating Lorry Hire.',
            ]);
        }

        $hasRole = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $party->id)
            ->where('role', PartyRoleType::Transporter->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->exists();

        if (! $hasRole) {
            throw ValidationException::withMessages([
                'transporter_party_id' => 'The Bilty intended Payee must have an active Transporter role. Correct the Bilty before creating Lorry Hire.',
            ]);
        }

        return $party;
    }

    private function assertCanCreate(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LORRY_HIRE_CREATE, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to create Broker Lorry Hires.');
        }
    }

    private function assertBrokerEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Broker)) {
            throw new BrokerDomainException('Broker module is not entitled for this company.');
        }
    }

    private function assertLorryReceiptEligible(LorryReceipt $lorryReceipt): void
    {
        if ($lorryReceipt->trashed()) {
            throw new BrokerDomainException('Cannot create a Lorry Hire for a deleted LR.');
        }

        if ($lorryReceipt->workflow_context !== LrWorkflowContext::Broker) {
            throw ValidationException::withMessages([
                'lorry_receipt_id' => 'Only Broker Lorry Receipts can create a Lorry Hire.',
            ]);
        }

        $existingHire = LorryHire::query()
            ->where('company_id', $lorryReceipt->company_id)
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', LorryHireStatus::Cancelled->value)
            ->exists();

        if ($existingHire) {
            throw new BrokerConflictException('This Broker LR already has an active Lorry Hire.');
        }

        if ($lorryReceipt->status !== LrStatus::Pending) {
            throw ValidationException::withMessages([
                'lorry_receipt_id' => 'Lorry Hire can only be created from a pending Broker LR.',
            ]);
        }
    }

    public function lorryHireForLorryReceipt(LorryReceipt $lorryReceipt): ?LorryHire
    {
        return $this->hireForLorryReceipt($lorryReceipt);
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new BrokerDomainException('Branch does not belong to company.');
        }
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = $company->timezone ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Kolkata';
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
