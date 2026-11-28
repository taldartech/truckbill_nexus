<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
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
use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Domains\Tax\TransporterTdsCalculationService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\LorryHireGoodsLine;
use App\Models\LorryHireTdsSnapshot;
use App\Models\LorryReceipt;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Database\CaseInsensitiveLike;
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
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
        private readonly LorryHireGoodsLineBuilder $goodsLines = new LorryHireGoodsLineBuilder,
        private readonly LorryHireCustomAmountService $customAmounts = new LorryHireCustomAmountService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, LorryHire>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $status = null,
        int $perPage = 15,
        bool $pendingMemo = false,
        ?string $search = null,
        ?string $lrNumber = null,
        ?string $customerPartyId = null,
        ?string $transporterPartyId = null,
        ?string $vehicleId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        bool $payableOnly = false,
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
                'lorryReceipt.delivery',
                'vehicle',
                'transporter',
                'goodsLines',
                'vouchers',
                'bookBalanceEntries',
                'adjustments',
            ])
            ->orderByDesc('created_at');

        if (filled($status)) {
            $builder->where('status', $status);
        }

        if ($pendingMemo) {
            $builder->where('status', '!=', LorryHireStatus::Cancelled->value)
                ->whereHas('lorryReceipt', fn ($receipt) => $receipt->whereDoesntHave(
                    'delivery',
                    fn ($delivery) => $delivery->whereNotNull('memo_number')->whereNull('deleted_at'),
                ));
        }

        if ($payableOnly) {
            LorryHireRemainingPayableScope::applyPositiveRemaining($builder);
        }

        if (filled($customerPartyId)) {
            $builder->whereHas(
                'lorryReceipt',
                fn ($receipt) => $receipt->where('billing_customer_party_id', $customerPartyId),
            );
        }

        if (filled($transporterPartyId)) {
            $builder->where('transporter_party_id', $transporterPartyId);
        }

        if (filled($vehicleId)) {
            $builder->where('vehicle_id', $vehicleId);
        }

        if (filled($startDate) || filled($endDate)) {
            $start = filled($startDate)
                ? CarbonImmutable::parse($startDate)->startOfDay()
                : CarbonImmutable::parse((string) $endDate)->subDays(15)->startOfDay();
            $end = filled($endDate)
                ? CarbonImmutable::parse($endDate)->endOfDay()
                : CarbonImmutable::now()->endOfDay();

            if ($end->lessThan($start)) {
                $end = $start->endOfDay();
            }

            $maxEnd = $start->addMonths(3)->endOfDay();
            if ($end->greaterThan($maxEnd)) {
                $end = $maxEnd;
            }

            $builder->whereHas(
                'lorryReceipt',
                fn ($receipt) => $receipt->whereBetween('lr_date', [$start, $end]),
            );
        }

        $lrFilter = trim((string) $lrNumber);
        if ($lrFilter !== '') {
            $builder->whereHas(
                'lorryReceipt',
                fn ($receipt) => CaseInsensitiveLike::whereContains($receipt, 'lr_number', $lrFilter),
            );
        }

        $searchFilter = trim((string) $search);
        if ($searchFilter !== '') {
            $builder->where(function ($query) use ($searchFilter): void {
                CaseInsensitiveLike::whereContains($query, 'hire_number', $searchFilter);
                $query->orWhereHas('lorryReceipt', function ($receipt) use ($searchFilter): void {
                    CaseInsensitiveLike::whereAnyContains(
                        $receipt,
                        ['lr_number', 'from_text', 'to_text'],
                        $searchFilter,
                    );
                });
                $query->orWhereHas('lorryReceipt.billingCustomer', function ($customer) use ($searchFilter): void {
                    CaseInsensitiveLike::whereContains($customer, 'name', $searchFilter);
                });
                $query->orWhereHas('vehicle', function ($vehicle) use ($searchFilter): void {
                    CaseInsensitiveLike::whereContains($vehicle, 'registration_number', $searchFilter);
                });
                $query->orWhereHas('transporter', function ($transporter) use ($searchFilter): void {
                    CaseInsensitiveLike::whereContains($transporter, 'name', $searchFilter);
                });
            });
        }

        return $builder->paginate(min(100, max(1, $perPage)));
    }

    /**
     * @return array{
     *     customers: list<array{value: string, label: string}>,
     *     transporters: list<array{value: string, label: string}>,
     *     vehicles: list<array{value: string, label: string}>
     * }
     */
    public function registerFilterOptions(Company $company, Branch $branch): array
    {
        $this->ensureTenantMatches($company, $branch);

        $base = LorryHire::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at');

        $receiptIds = (clone $base)->select('lorry_receipt_id');

        $customerIds = LorryReceipt::query()
            ->whereIn('id', $receiptIds)
            ->whereNotNull('billing_customer_party_id')
            ->distinct()
            ->pluck('billing_customer_party_id');

        $transporterIds = (clone $base)
            ->whereNotNull('transporter_party_id')
            ->distinct()
            ->pluck('transporter_party_id');

        $vehicleIds = (clone $base)
            ->whereNotNull('vehicle_id')
            ->distinct()
            ->pluck('vehicle_id');

        $customers = Party::query()
            ->whereIn('id', $customerIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Party $party): array => [
                'value' => $party->id,
                'label' => (string) $party->name,
            ])
            ->values()
            ->all();

        $transporters = Party::query()
            ->whereIn('id', $transporterIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Party $party): array => [
                'value' => $party->id,
                'label' => (string) $party->name,
            ])
            ->values()
            ->all();

        $vehicles = Vehicle::query()
            ->whereIn('id', $vehicleIds)
            ->orderBy('registration_number')
            ->get(['id', 'registration_number'])
            ->map(fn (Vehicle $vehicle): array => [
                'value' => $vehicle->id,
                'label' => (string) $vehicle->registration_number,
            ])
            ->values()
            ->all();

        return [
            'customers' => $customers,
            'transporters' => $transporters,
            'vehicles' => $vehicles,
        ];
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
                'customAmounts',
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
     *     tds_override_rate?: string|null,
     *     tds_override_remark?: string|null,
     *     goods_lines?: list<array{
     *         lr_goods_row_id: string,
     *         rate_type?: string,
     *         rate: string|float|int,
     *     }>,
     *     custom_amounts?: list<array{id?: string|null, description?: string, amount?: string|float|int}>
     * }  $payload
     *
     * Payee and loading weight are never read from the payload: the Payee is the Bilty's
     * persisted intended payee and loading weight is derived from the Bilty goods rows.
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
        $transporter = $this->inheritedPayee($lorryReceipt, $company, $branch);

        $computedLines = $this->goodsLines->build($lorryReceipt, $payload['goods_lines'] ?? []);
        $freightTotal = $this->goodsLines->freightTotal($computedLines);

        $tdsContext = $this->tdsContextForPayee($transporter);

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
        // Legacy freight booking create: Custom Amount rows are edit-only.
        /** @var list<array<string, mixed>> $customRows */
        $customRows = [];
        $outstanding = $this->calculations->netPayable(
            $freightTotal,
            $tdsAmount,
            '0.00',
            collect(),
        );

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
                $computedLines,
                $freightTotal,
                $tdsPercent,
                $tdsAmount,
                $outstanding,
                $tdsResult,
                $hireDate,
                $payload,
                $customRows,
            ): LorryHire {
                return $this->withTenant($company, $branch, function () use (
                    $lorryReceipt,
                    $actor,
                    $company,
                    $branch,
                    $vehicle,
                    $transporter,
                    $computedLines,
                    $freightTotal,
                    $tdsPercent,
                    $tdsAmount,
                    $outstanding,
                    $tdsResult,
                    $hireDate,
                    $payload,
                    $customRows,
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
                        scopeType: DocumentNumberScopeType::Company,
                        branchId: null,
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
                            'rate_semantics' => $line['rate_semantics']->value,
                            'rate' => $line['rate'],
                            'is_fixed_amount' => $line['is_fixed_amount'],
                            'freight_amount' => $line['freight_amount'],
                            'loading_weight' => $line['loading_weight'],
                        ]);
                    }

                    if ($customRows !== []) {
                        $customTotal = $this->customAmounts->sync($hire, $customRows);
                        $summary = $this->calculations->summarize($hire->fresh(['customAmounts', 'adjustments', 'vouchers', 'bookBalanceEntries']));
                        $hire->forceFill([
                            'outstanding_amount' => $summary['outstanding_amount'],
                        ])->save();
                        $this->accounting->postLorryHireCustomAmountsRecognized($customTotal, $hire, $actor);
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
                        'override_state' => $tdsResult->overrideState,
                        'explanation' => $tdsResult->explanation,
                        'snapshotted_at' => CarbonImmutable::now('UTC'),
                    ]);

                    if ($lorryReceipt->status === LrStatus::Pending) {
                        $lorryReceipt->forceFill([
                            'status' => LrStatus::InProgress,
                        ])->save();
                    }

                    $this->accounting->postLorryHireRecognized($hire, $actor);

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
     * Hire Payee is the Bilty's persisted intended payee — never re-selected or overridden on Hire create.
     *
     * @throws ValidationException when the Bilty payee is missing or not usable for a Hire.
     */
    public function inheritedPayee(LorryReceipt $lorryReceipt, Company $company, Branch $branch): Party
    {
        $intendedId = filled($lorryReceipt->intended_payee_party_id)
            ? (string) $lorryReceipt->intended_payee_party_id
            : null;

        if ($intendedId === null) {
            throw ValidationException::withMessages([
                'payee' => 'This Bilty has no intended Payee. Correct the Bilty Payee before creating Lorry Hire.',
            ]);
        }

        $party = Party::query()
            ->visibleInBranch($company->id, $branch->id)
            ->whereKey($intendedId)
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages([
                'payee' => 'The Bilty intended Payee is not available for transactions in this branch. Correct the Bilty Payee before creating Lorry Hire.',
            ]);
        }

        if ($this->transporterRoleFor($party) === null) {
            throw ValidationException::withMessages([
                'payee' => 'The Bilty intended Payee must have an active Transporter role. Correct the Bilty before creating Lorry Hire.',
            ]);
        }

        return $party;
    }

    /**
     * Applicable transporter TDS preview for the inherited payee (no override).
     *
     * @return array{applicable_percent: string, explanation: string}|null
     */
    public function tdsPreviewForPayee(Party $payee): ?array
    {
        try {
            $result = $this->tds->calculate($this->tdsContextForPayee($payee));
        } catch (TaxDomainException) {
            return null;
        }

        return [
            'applicable_percent' => number_format((float) $result->applicableRate, 3, '.', ''),
            'explanation' => (string) $result->explanation,
        ];
    }

    public function defaultHireDate(Company $company): string
    {
        return CarbonImmutable::now($this->companyTimezone($company))->format('Y-m-d');
    }

    private function transporterRoleFor(Party $party): ?PartyRole
    {
        return PartyRole::query()
            ->where('company_id', $party->company_id)
            ->where('party_id', $party->id)
            ->where('role', PartyRoleType::Transporter->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->first();
    }

    /**
     * @return array{
     *     pan_status: string|null,
     *     pan_fourth_char: string|null,
     *     declaration_valid: bool,
     *     goods_vehicle_count: int|null
     * }
     */
    private function tdsContextForPayee(Party $payee): array
    {
        $role = $this->transporterRoleFor($payee);
        $roleAttrs = is_array($role?->role_attributes) ? $role->role_attributes : [];

        return $this->tds->buildContextFromTransporter(['pan' => $payee->pan], $roleAttrs);
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
