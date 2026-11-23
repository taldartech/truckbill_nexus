<?php

namespace App\Domains\Enquiry;

use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Enquiry\Enums\EnquiryStatus;
use App\Domains\Enquiry\Enums\QuotationSelectionStatus;
use App\Domains\Enquiry\Exceptions\EnquiryAuthorizationException;
use App\Domains\Enquiry\Exceptions\EnquiryConflictException;
use App\Domains\Enquiry\Exceptions\EnquiryDomainException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Enquiry;
use App\Models\EnquiryConversion;
use App\Models\EnquiryConversionLorryReceipt;
use App\Models\EnquiryQuotation;
use App\Models\EnquiryStatusHistory;
use App\Models\LorryReceipt;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Whole-enquiry conversion to N Unified LRs — Option C allocation (Phase 4 Batch F).
 */
final class EnquiryConversionService
{
    public function __construct(
        private readonly LorryReceiptService $lorryReceipts,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly OutboxWriter $outbox = new OutboxWriter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Company $company, Branch $branch, Enquiry $enquiry): array
    {
        $this->assertEnquiryVisible($company, $branch, $enquiry);
        $enquiry->loadMissing(['goodsRows', 'quotations']);

        $preferred = $this->preferredQuotation($enquiry);
        $n = max(1, (int) $enquiry->vehicle_quantity);
        $suggested = $this->suggestEqualVehicleAllocations($enquiry, $n);
        $freightPerVehicle = $this->splitFreightAcrossVehicles($preferred, $n);

        return [
            'enquiry_id' => $enquiry->id,
            'vehicle_quantity' => $n,
            'status' => $enquiry->status->value,
            'eligible' => $this->eligibilityErrors($enquiry, $preferred) === [],
            'eligibility_errors' => $this->eligibilityErrors($enquiry, $preferred),
            'preferred_quotation_id' => $preferred?->id,
            'suggested_vehicles' => $suggested,
            'freight_per_vehicle' => $freightPerVehicle,
            'reconciliation_totals' => $this->reconciliationTotals($enquiry, $suggested, $freightPerVehicle),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{conversion: EnquiryConversion, lorry_receipts: list<LorryReceipt>}
     */
    public function convert(
        Company $company,
        Branch $branch,
        Enquiry $enquiry,
        User $actor,
        array $payload,
    ): array {
        $this->assertEnquiryVisible($company, $branch, $enquiry);

        $idempotencyKey = isset($payload['idempotency_key'])
            ? trim((string) $payload['idempotency_key'])
            : null;
        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        if ($idempotencyKey !== null) {
            $existing = EnquiryConversion::query()
                ->where('company_id', $company->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return [
                    'conversion' => $existing->load('lorryReceiptLinks'),
                    'lorry_receipts' => $this->lrsForConversion($existing),
                ];
            }
        }

        $workflowContext = LrWorkflowContext::tryFrom((string) ($payload['workflow_context'] ?? ''));
        if ($workflowContext === null) {
            throw ValidationException::withMessages(['workflow_context' => 'Workflow context is required.']);
        }

        $enquiry->loadMissing(['goodsRows', 'quotations']);
        $preferred = $this->preferredQuotation($enquiry);
        $errors = $this->eligibilityErrors($enquiry, $preferred, $workflowContext);
        if ($errors !== []) {
            throw ValidationException::withMessages(['conversion' => implode(' ', $errors)]);
        }

        $vehicles = $payload['vehicles'] ?? null;
        if (! is_array($vehicles) || count($vehicles) !== (int) $enquiry->vehicle_quantity) {
            throw ValidationException::withMessages([
                'vehicles' => 'Exactly '.$enquiry->vehicle_quantity.' arranged vehicle allocation(s) are required.',
            ]);
        }

        $this->assertAllocationsReconcile($enquiry, array_values($vehicles));

        $freightPerVehicle = $this->splitFreightAcrossVehicles($preferred, count($vehicles));
        $remark = trim((string) ($payload['remark'] ?? 'Enquiry converted to Unified LR(s).'));

        return $this->auditedTransaction->run(function () use (
            $company,
            $branch,
            $enquiry,
            $actor,
            $vehicles,
            $workflowContext,
            $freightPerVehicle,
            $idempotencyKey,
            $remark,
            $payload,
        ): array {
            return $this->withTenant($company, $branch, function () use (
                $company,
                $branch,
                $enquiry,
                $actor,
                $vehicles,
                $workflowContext,
                $freightPerVehicle,
                $idempotencyKey,
                $remark,
                $payload,
            ): array {
                /** @var Enquiry $locked */
                $locked = Enquiry::query()->whereKey($enquiry->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === EnquiryStatus::Converted) {
                    $conversion = EnquiryConversion::query()
                        ->where('enquiry_id', $locked->id)
                        ->first();
                    if ($conversion !== null) {
                        throw new EnquiryConflictException('Enquiry is already converted.');
                    }
                }

                $existingConversion = EnquiryConversion::query()
                    ->where('enquiry_id', $locked->id)
                    ->first();
                if ($existingConversion !== null) {
                    throw new EnquiryConflictException('Enquiry is already converted.');
                }

                $conversion = EnquiryConversion::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'enquiry_id' => $locked->id,
                    'idempotency_key' => $idempotencyKey,
                    'converted_by_user_id' => $actor->id,
                    'converted_at' => now(),
                ]);

                $lrPayloadBase = $this->baseLrPayload($locked, $workflowContext, $payload);
                $createdLrs = [];

                foreach (array_values($vehicles) as $index => $vehicleAlloc) {
                    $goods = $this->buildGoodsRowsForVehicle(
                        $locked,
                        $vehicleAlloc,
                        $freightPerVehicle[$index] ?? '0.00',
                    );

                    $lr = $this->lorryReceipts->create($company, $branch, $actor, array_merge(
                        $lrPayloadBase,
                        ['goods' => $goods],
                    ));

                    EnquiryConversionLorryReceipt::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'enquiry_conversion_id' => $conversion->id,
                        'lorry_receipt_id' => $lr->id,
                        'position' => $index + 1,
                    ]);

                    $createdLrs[] = $lr;

                    $this->outbox->enqueue(
                        eventType: 'lr.created',
                        payload: [
                            'lorry_receipt_id' => $lr->id,
                            'lr_number' => $lr->lr_number,
                            'enquiry_id' => $locked->id,
                            'conversion_id' => $conversion->id,
                            'position' => $index + 1,
                        ],
                    );
                }

                $fromStatus = $locked->status;
                $locked->forceFill([
                    'status' => EnquiryStatus::Converted,
                    'converted_at' => now(),
                ])->save();

                EnquiryStatusHistory::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'enquiry_id' => $locked->id,
                    'from_status' => $fromStatus,
                    'to_status' => EnquiryStatus::Converted,
                    'actor_user_id' => $actor->id,
                    'remark' => $remark,
                    'changed_at' => now(),
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    EnquiryConversion::class,
                    $conversion->id,
                    remark: $remark,
                    before: null,
                    after: [
                        'enquiry_id' => $locked->id,
                        'lr_count' => count($createdLrs),
                    ],
                );

                $this->outbox->enqueue(
                    eventType: 'enquiry.converted',
                    payload: [
                        'enquiry_id' => $locked->id,
                        'conversion_id' => $conversion->id,
                        'lr_ids' => collect($createdLrs)->pluck('id')->all(),
                        'vehicle_quantity' => $locked->vehicle_quantity,
                    ],
                );

                return [
                    'conversion' => $conversion->load('lorryReceiptLinks'),
                    'lorry_receipts' => $createdLrs,
                ];
            });
        });
    }

    /**
     * @return list<string>
     */
    private function splitFreightAcrossVehicles(?EnquiryQuotation $preferred, int $n): array
    {
        if ($n < 1) {
            return [];
        }

        $total = $this->decimalMoney(null);
        if ($preferred !== null) {
            $total = bcadd(
                $this->decimalMoney($preferred->quoted_freight),
                $this->decimalMoney($preferred->extra_charges_amount),
                2,
            );
        }

        if (bccomp($total, '0.00', 2) <= 0) {
            return array_fill(0, $n, '0.00');
        }

        $base = bcdiv($total, (string) $n, 2);
        $parts = array_fill(0, $n, $base);
        $allocated = bcmul($base, (string) $n, 2);
        $remainder = bcsub($total, $allocated, 2);
        if (bccomp($remainder, '0.00', 2) !== 0) {
            $parts[$n - 1] = bcadd($parts[$n - 1], $remainder, 2);
        }

        return array_values($parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggestEqualVehicleAllocations(Enquiry $enquiry, int $n): array
    {
        $vehicles = [];
        $goodsRows = $enquiry->goodsRows;

        for ($v = 0; $v < $n; $v++) {
            $goods = [];
            foreach ($goodsRows as $row) {
                $goods[] = [
                    'goods_row_id' => $row->id,
                    'position' => $row->position,
                    'package_count' => $this->splitDecimal($row->package_count, $n, $v),
                    'actual_weight' => $this->splitDecimal($row->actual_weight, $n, $v),
                    'charged_weight' => $this->splitDecimal($row->charged_weight, $n, $v),
                ];
            }
            $vehicles[] = ['position' => $v + 1, 'goods' => $goods];
        }

        return $vehicles;
    }

    private function splitDecimal(string|float|int|null $value, int $n, int $index): ?string
    {
        if ($value === null) {
            return null;
        }

        $scale = 3;
        $normalized = $this->decimalQuantity($value);
        $base = bcdiv($normalized, (string) $n, $scale);
        if ($index === $n - 1) {
            $allocated = bcmul($base, (string) ($n - 1), $scale);

            return bcsub($normalized, $allocated, $scale);
        }

        return $base;
    }

    /**
     * @param  list<array<string, mixed>>  $vehicles
     */
    private function assertAllocationsReconcile(Enquiry $enquiry, array $vehicles): void
    {
        $totals = [];
        foreach ($enquiry->goodsRows as $row) {
            $totals[$row->id] = [
                'package_count' => $this->decimalQuantity($row->package_count),
                'actual_weight' => $this->decimalQuantity($row->actual_weight),
                'charged_weight' => $this->decimalQuantity($row->charged_weight),
            ];
        }

        $sums = [];
        foreach ($vehicles as $vehicle) {
            $goods = $vehicle['goods'] ?? null;
            if (! is_array($goods)) {
                throw ValidationException::withMessages(['vehicles' => 'Each vehicle requires goods allocations.']);
            }

            foreach ($goods as $alloc) {
                $rowId = (string) ($alloc['goods_row_id'] ?? '');
                if ($rowId === '' || ! array_key_exists($rowId, $totals)) {
                    throw ValidationException::withMessages(['vehicles' => 'Unknown goods row in allocation.']);
                }

                foreach (['package_count', 'actual_weight', 'charged_weight'] as $field) {
                    $val = $alloc[$field] ?? null;
                    if ($val === null || $val === '') {
                        continue;
                    }
                    if (! is_numeric($val)) {
                        throw ValidationException::withMessages(["vehicles.{$field}" => 'Allocation must be numeric.']);
                    }
                    $sums[$rowId][$field] = bcadd(
                        $sums[$rowId][$field] ?? '0',
                        number_format((float) $val, 3, '.', ''),
                        3,
                    );
                }
            }
        }

        foreach ($totals as $rowId => $expected) {
            foreach (['package_count', 'actual_weight', 'charged_weight'] as $field) {
                if (bccomp($expected[$field], '0.000', 3) === 0) {
                    continue;
                }
                $actual = $sums[$rowId][$field] ?? '0.000';
                if (bccomp($this->decimalQuantity($actual), $expected[$field], 3) !== 0) {
                    throw ValidationException::withMessages([
                        'vehicles' => 'Goods '.$field.' allocations must reconcile to enquiry totals.',
                    ]);
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $vehicles
     * @param  list<string>  $freightPerVehicle
     * @return array<string, mixed>
     */
    private function reconciliationTotals(Enquiry $enquiry, array $vehicles, array $freightPerVehicle): array
    {
        $chargedSum = '0.000';
        foreach ($vehicles as $vehicle) {
            foreach ($vehicle['goods'] as $row) {
                if ($row['charged_weight'] !== null) {
                    $chargedSum = bcadd($chargedSum, $this->decimalQuantity($row['charged_weight']), 3);
                }
            }
        }

        $freightSum = '0.00';
        foreach ($freightPerVehicle as $part) {
            $freightSum = bcadd($freightSum, $this->decimalMoney($part), 2);
        }

        return [
            'enquiry_vehicle_quantity' => $enquiry->vehicle_quantity,
            'allocated_vehicle_slots' => count($vehicles),
            'charged_weight_sum' => $chargedSum,
            'freight_sum' => $freightSum,
        ];
    }

    /**
     * @param  array<string, mixed>  $vehicleAlloc
     * @return list<array<string, mixed>>
     */
    private function buildGoodsRowsForVehicle(Enquiry $enquiry, array $vehicleAlloc, string $freightAmount): array
    {
        $goods = [];
        $goodsById = $enquiry->goodsRows->keyBy('id');

        foreach ($vehicleAlloc['goods'] as $allocIndex => $alloc) {
            $row = $goodsById->get((string) $alloc['goods_row_id']);
            if ($row === null) {
                continue;
            }

            $goods[] = [
                'goods_type_id' => $row->goods_type_id,
                'package_type_id' => $row->package_type_id,
                'goods_description' => $row->goods_description,
                'package_count' => $alloc['package_count'] ?? null,
                'actual_weight' => $alloc['actual_weight'] ?? null,
                'charged_weight' => $alloc['charged_weight'] ?? null,
                'freight_rate_type' => FreightRateType::FixedAmount->value,
                'freight_rate' => $allocIndex === 0 ? $freightAmount : '0',
            ];
        }

        if ($goods === []) {
            throw ValidationException::withMessages(['vehicles' => 'Each vehicle requires at least one goods allocation.']);
        }

        return $goods;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function baseLrPayload(Enquiry $enquiry, LrWorkflowContext $context, array $payload): array
    {
        $data = [
            'workflow_context' => $context->value,
            'number_mode' => 'auto',
            'lr_date' => now()->toDateString(),
            'from_text' => $enquiry->pickup_location,
            'to_text' => $enquiry->delivery_location,
        ];

        if ($enquiry->customer_party_id !== null) {
            $data['consignor_party_id'] = $enquiry->customer_party_id;
            $data['consignee_party_id'] = $enquiry->customer_party_id;
            $data['billing_customer_party_id'] = $enquiry->customer_party_id;
        }

        if ($context === LrWorkflowContext::Broker) {
            $vehicleId = $payload['vehicle_id'] ?? null;
            if ($vehicleId === null) {
                throw ValidationException::withMessages(['vehicle_id' => 'Market vehicle is required for broker conversion.']);
            }
            $vehicle = Vehicle::query()
                ->where('company_id', $enquiry->company_id)
                ->whereKey((string) $vehicleId)
                ->whereNull('deleted_at')
                ->first();
            if ($vehicle === null) {
                throw ValidationException::withMessages(['vehicle_id' => 'Vehicle was not found.']);
            }
            $data['vehicle_id'] = $vehicle->id;
        }

        if ($context === LrWorkflowContext::OwnFleet) {
            $vehicleId = $payload['vehicle_id'] ?? null;
            if ($vehicleId === null) {
                throw ValidationException::withMessages(['vehicle_id' => 'Own vehicle is required for OwnFleet conversion.']);
            }
            $data['vehicle_id'] = $vehicleId;
            $fromStation = $payload['from_station_id'] ?? null;
            $toStation = $payload['to_station_id'] ?? null;
            if ($fromStation === null || $toStation === null) {
                throw ValidationException::withMessages(['from_station_id' => 'Stations are required for OwnFleet conversion.']);
            }
            $data['from_station_id'] = $fromStation;
            $data['to_station_id'] = $toStation;
        }

        return $data;
    }

    private function preferredQuotation(Enquiry $enquiry): ?EnquiryQuotation
    {
        return $enquiry->quotations
            ->first(fn (EnquiryQuotation $q): bool => $q->selection_status === QuotationSelectionStatus::Preferred
                && $q->deleted_at === null);
    }

    /**
     * @return list<string>
     */
    private function eligibilityErrors(
        Enquiry $enquiry,
        ?EnquiryQuotation $preferred,
        ?LrWorkflowContext $workflowContext = null,
    ): array {
        $errors = [];

        if ($enquiry->status === EnquiryStatus::Converted) {
            $errors[] = 'Enquiry is already converted.';
        }

        if (in_array($enquiry->status, [EnquiryStatus::Rejected, EnquiryStatus::Cancelled], true)) {
            $errors[] = 'Enquiry is closed.';
        }

        if ($enquiry->branch_id === null) {
            $errors[] = 'Enquiry must be assigned to a branch before conversion.';
        }

        $isOwnFleet = $workflowContext === LrWorkflowContext::OwnFleet;
        if (! $isOwnFleet && $preferred === null) {
            $errors[] = 'A preferred quotation is required before conversion.';
        }

        if (! $isOwnFleet && $enquiry->status !== EnquiryStatus::Selected) {
            $errors[] = 'Enquiry status must be Selected before conversion.';
        }

        return $errors;
    }

    private function assertEnquiryVisible(Company $company, Branch $branch, Enquiry $enquiry): void
    {
        if ($enquiry->company_id !== $company->id) {
            throw new EnquiryAuthorizationException('Enquiry does not belong to this company.');
        }

        if ($enquiry->branch_id !== null && $enquiry->branch_id !== $branch->id) {
            throw new EnquiryAuthorizationException('Enquiry does not belong to this branch.');
        }

        $this->ensureTenantMatches($company, $branch);
    }

    /**
     * @return list<LorryReceipt>
     */
    private function lrsForConversion(EnquiryConversion $conversion): array
    {
        $links = $conversion->lorryReceiptLinks()->orderBy('position')->get();
        $ids = $links->pluck('lorry_receipt_id')->all();

        return array_values(LorryReceipt::query()->whereIn('id', $ids)->get()->all());
    }

    /**
     * @return numeric-string
     */
    private function decimalMoney(string|float|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @return numeric-string
     */
    private function decimalQuantity(string|float|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.000';
        }

        return number_format((float) $value, 3, '.', '');
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
