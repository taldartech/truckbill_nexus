<?php

namespace App\Domains\OwnFleet\Invoice;

use App\Domains\Company\MasterDataVisibilityResolver;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Numbering\Exceptions\DocumentNumberConflictException;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceChargeType;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceParticularsMode;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoicePaymentStatus;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceStatus;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceType;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceAuthorizationException;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceConflictException;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\LorryReceipt;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Trip;
use App\Models\TripInvoice;
use App\Models\TripInvoiceCharge;
use App\Models\TripInvoiceLine;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OwnFleet customer invoices (Rules §10 — sample / non-production workflow).
 */
final class TripInvoiceService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
        private readonly MasterDataVisibilityResolver $visibility = new MasterDataVisibilityResolver,
        private readonly TripInvoiceTdsResolver $tdsResolver = new TripInvoiceTdsResolver,
    ) {}

    /**
     * @return LengthAwarePaginator<int, TripInvoice>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $paymentStatus = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = TripInvoice::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['billingCustomer'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at');

        if (filled($paymentStatus)) {
            $builder->where('payment_status', $paymentStatus);
        }

        return $builder->paginate($perPage);
    }

    /**
     * Completed Trips with billing customer and no active invoice line.
     *
     * @return Collection<int, LorryReceipt>
     */
    public function listInvoiceEligibleLorryReceipts(Company $company, Branch $branch, ?string $billingCustomerId = null): Collection
    {
        $this->ensureTenantMatches($company, $branch);

        $invoicedIds = TripInvoiceLine::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('invoice', function ($query): void {
                $query->whereNull('deleted_at')
                    ->where('status', TripInvoiceStatus::Pending);
            })
            ->pluck('lorry_receipt_id');

        $builder = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('workflow_context', LrWorkflowContext::OwnFleet)
            ->whereNull('deleted_at')
            ->whereNotNull('billing_customer_party_id')
            ->whereNotIn('id', $invoicedIds)
            ->whereHas('trip', function ($query): void {
                $query->where('status', TripStatus::Completed)
                    ->whereNull('deleted_at');
            })
            ->with(['trip', 'billingCustomer']);

        if (filled($billingCustomerId)) {
            $builder->where('billing_customer_party_id', $billingCustomerId);
        }

        return $builder->orderBy('lr_date')->get();
    }

    /**
     * @param  array{
     *     invoice_date?: string,
     *     particulars_mode?: string,
     *     number_mode?: string,
     *     manual_number?: string,
     *     charges?: list<array{charge_type: string, description: string, amount: numeric-string|float|int}>
     * }  $payload
     */
    public function createIndividual(
        Company $company,
        Branch $branch,
        User $actor,
        string $lorryReceiptId,
        array $payload,
    ): TripInvoice {
        return $this->createInvoice(
            company: $company,
            branch: $branch,
            actor: $actor,
            lorryReceiptIds: [$lorryReceiptId],
            invoiceType: TripInvoiceType::Individual,
            payload: $payload,
        );
    }

    /**
     * @param  list<string>  $lorryReceiptIds
     * @param  array{
     *     invoice_date?: string,
     *     particulars_mode?: string,
     *     number_mode?: string,
     *     manual_number?: string,
     *     charges?: list<array{charge_type: string, description: string, amount: numeric-string|float|int}>
     * }  $payload
     */
    public function createConsolidated(
        Company $company,
        Branch $branch,
        User $actor,
        array $lorryReceiptIds,
        array $payload,
    ): TripInvoice {
        $lorryReceiptIds = array_values(array_unique($lorryReceiptIds));
        if (count($lorryReceiptIds) < 2) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'Select at least two Lorry Receipts for a consolidated invoice.',
            ]);
        }

        return $this->createInvoice(
            company: $company,
            branch: $branch,
            actor: $actor,
            lorryReceiptIds: $lorryReceiptIds,
            invoiceType: TripInvoiceType::Consolidated,
            payload: $payload,
        );
    }

    public function cancel(TripInvoice $invoice, User $actor, string $remark): TripInvoice
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->assertCanCancel($actor, $company, $branch);
        $this->assertOwnFleetEntitled($company);

        $remark = trim($remark);
        if ($remark === '') {
            throw ValidationException::withMessages(['cancellation_remark' => 'Cancellation reason is required.']);
        }

        return $this->auditedTransaction->run(function () use ($invoice, $company, $branch, $remark): TripInvoice {
            return $this->withTenant($company, $branch, function () use ($invoice, $remark): TripInvoice {
                $locked = TripInvoice::query()->whereKey($invoice->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();

                if ($locked->status !== TripInvoiceStatus::Pending) {
                    throw new TripInvoiceConflictException('Only pending invoices can be cancelled.');
                }

                if ($locked->payments()->exists()) {
                    throw new TripInvoiceConflictException('Invoices with payments cannot be cancelled.');
                }

                $before = $locked->toArray();
                $locked->forceFill([
                    'status' => TripInvoiceStatus::Cancelled,
                    'cancelled_at' => CarbonImmutable::now('UTC'),
                    'cancellation_remark' => $remark,
                ])->save();

                TripInvoiceLine::query()
                    ->where('trip_invoice_id', $locked->id)
                    ->whereNull('deleted_at')
                    ->each(fn (TripInvoiceLine $line) => $line->delete());

                $this->audit->write(
                    AuditAction::Updated,
                    TripInvoice::class,
                    $locked->id,
                    remark: 'Cancel OwnFleet trip invoice',
                    before: $before,
                    after: $locked->fresh()?->toArray(),
                );

                return $locked->fresh(['lines', 'charges', 'payments', 'billingCustomer']) ?? $locked;
            });
        });
    }

    /**
     * @param  array{
     *     edit_remark: string,
     *     charges?: list<array{charge_type: string, description: string, amount: numeric-string|float|int}>
     * }  $payload
     */
    public function updateCharges(TripInvoice $invoice, User $actor, array $payload): TripInvoice
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->assertCanEditCharges($actor, $company, $branch);
        $this->assertOwnFleetEntitled($company);

        $remark = trim((string) ($payload['edit_remark'] ?? ''));
        if ($remark === '') {
            throw ValidationException::withMessages(['edit_remark' => 'Edit reason is required.']);
        }

        $charges = $this->normalizeCharges($payload['charges'] ?? []);

        return $this->auditedTransaction->run(function () use ($invoice, $company, $branch, $remark, $charges): TripInvoice {
            return $this->withTenant($company, $branch, function () use ($invoice, $remark, $charges): TripInvoice {
                $locked = TripInvoice::query()->whereKey($invoice->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();

                if ($locked->status !== TripInvoiceStatus::Pending) {
                    throw new TripInvoiceConflictException('Only pending invoices allow additional charge edits.');
                }

                if ($locked->payments()->exists()) {
                    throw new TripInvoiceConflictException('Additional charges are locked after the first payment.');
                }

                if ($locked->charges_locked_at !== null) {
                    throw new TripInvoiceConflictException('Additional charges are locked.');
                }

                $baseFreight = $this->storedDecimal($locked->base_freight_total);
                $additional = '0.00';
                foreach ($charges as $charge) {
                    $additional = bcadd($additional, $charge['amount'], 2);
                }

                [$taxable, $gstAmount, $invoiceTotal, $gstSnapshot] = $this->computeTaxTotals(
                    Company::query()->findOrFail($locked->company_id),
                    $baseFreight,
                    $additional,
                );

                $before = $locked->toArray();

                TripInvoiceCharge::query()
                    ->where('trip_invoice_id', $locked->id)
                    ->whereNull('deleted_at')
                    ->each(fn (TripInvoiceCharge $row) => $row->delete());

                foreach ($charges as $charge) {
                    TripInvoiceCharge::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $locked->company_id,
                        'trip_invoice_id' => $locked->id,
                        ...$charge,
                    ]);
                }

                $balanceDue = bcsub($invoiceTotal, $this->storedDecimal($locked->settlement_total), 2);
                if (bccomp($balanceDue, '0', 2) === -1) {
                    $balanceDue = '0.00';
                }

                $locked->forceFill([
                    'additional_charges_total' => $additional,
                    'taxable_total' => $taxable,
                    'gst_amount' => $gstAmount,
                    'invoice_total' => $invoiceTotal,
                    'balance_due' => $balanceDue,
                    'gst_rate_snapshot' => $gstSnapshot ?? $locked->gst_rate_snapshot,
                ])->save();

                $this->audit->write(
                    AuditAction::Updated,
                    TripInvoice::class,
                    $locked->id,
                    remark: 'Update OwnFleet trip invoice additional charges: '.$remark,
                    before: $before,
                    after: $locked->fresh(['lines', 'charges'])?->toArray(),
                );

                return $locked->fresh(['lines', 'charges', 'payments', 'billingCustomer']) ?? $locked;
            });
        });
    }

    public function softDelete(TripInvoice $invoice, User $actor): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->assertCanDelete($actor, $company, $branch);
        $this->assertOwnFleetEntitled($company);

        $this->auditedTransaction->run(function () use ($invoice, $company, $branch): void {
            $this->withTenant($company, $branch, function () use ($invoice, $company, $branch): void {
                $locked = TripInvoice::query()->whereKey($invoice->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();

                if ($locked->status !== TripInvoiceStatus::Pending) {
                    throw new TripInvoiceConflictException('Only pending invoices can be deleted.');
                }

                if ($locked->payments()->exists()) {
                    throw new TripInvoiceConflictException('Invoices with payments cannot be deleted.');
                }

                if (bccomp($this->storedDecimal($locked->settlement_total), '0', 2) !== 0) {
                    throw new TripInvoiceConflictException('Only unpaid invoices can be deleted.');
                }

                $this->assertLatestDeletableInScope($locked, $company, $branch);

                $before = $locked->toArray();

                TripInvoiceLine::query()->where('trip_invoice_id', $locked->id)->whereNull('deleted_at')
                    ->each(fn (TripInvoiceLine $line) => $line->delete());
                TripInvoiceCharge::query()->where('trip_invoice_id', $locked->id)->whereNull('deleted_at')
                    ->each(fn (TripInvoiceCharge $row) => $row->delete());

                $locked->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    TripInvoice::class,
                    $locked->id,
                    remark: 'Soft-delete OwnFleet trip invoice',
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @param  list<string>  $lorryReceiptIds
     * @param  array<string, mixed>  $payload
     */
    private function createInvoice(
        Company $company,
        Branch $branch,
        User $actor,
        array $lorryReceiptIds,
        TripInvoiceType $invoiceType,
        array $payload,
    ): TripInvoice {
        $this->assertCanCreate($actor, $company, $branch);
        $this->assertOwnFleetEntitled($company);

        $particulars = TripInvoiceParticularsMode::tryFrom((string) ($payload['particulars_mode'] ?? ''))
            ?? TripInvoiceParticularsMode::SeparateLines;

        $invoiceDate = filled($payload['invoice_date'] ?? null)
            ? CarbonImmutable::parse((string) $payload['invoice_date'])->toDateString()
            : CarbonImmutable::now($company->timezone ?? 'Asia/Kolkata')->toDateString();

        $charges = $this->normalizeCharges($payload['charges'] ?? []);

        return $this->auditedTransaction->run(function () use (
            $company,
            $branch,
            $actor,
            $lorryReceiptIds,
            $invoiceType,
            $particulars,
            $invoiceDate,
            $charges,
            $payload,
        ): TripInvoice {
            return $this->withTenant($company, $branch, function () use (
                $company,
                $branch,
                $actor,
                $lorryReceiptIds,
                $invoiceType,
                $particulars,
                $invoiceDate,
                $charges,
                $payload,
            ): TripInvoice {
                $receipts = $this->resolveEligibleReceipts($company, $branch, $lorryReceiptIds);
                $billingCustomerId = (string) $receipts->first()->billing_customer_party_id;

                foreach ($receipts as $receipt) {
                    if ((string) $receipt->billing_customer_party_id !== $billingCustomerId) {
                        throw ValidationException::withMessages([
                            'lorry_receipt_ids' => 'All LRs must share the same Billing Customer.',
                        ]);
                    }
                }

                $billingCustomer = Party::query()
                    ->where('company_id', $company->id)
                    ->whereKey($billingCustomerId)
                    ->whereNull('deleted_at')
                    ->firstOrFail();

                $this->assertCustomerRole($company, $billingCustomer);

                [$invoiceNumber, $allocationId] = $this->allocateNumber($company, $branch, $payload);

                $baseFreight = bcadd('0', '0', 2);
                $lines = [];
                $sort = 0;
                foreach ($receipts as $receipt) {
                    $freight = $this->storedDecimal($receipt->freight_total);
                    $baseFreight = bcadd($baseFreight, $freight, 2);
                    $trip = Trip::query()->where('lorry_receipt_id', $receipt->id)->whereNull('deleted_at')->firstOrFail();
                    $lines[] = [
                        'lorry_receipt_id' => $receipt->id,
                        'trip_id' => $trip->id,
                        'sort_order' => $sort++,
                        'base_freight' => $freight,
                        'lr_number' => $receipt->lr_number,
                    ];
                }

                $additional = bcadd('0', '0', 2);
                foreach ($charges as $charge) {
                    $additional = bcadd($additional, $charge['amount'], 2);
                }

                [$taxable, $gstAmount, $invoiceTotal, $gstSnapshot] = $this->computeTaxTotals($company, $baseFreight, $additional);
                $tdsRate = $this->tdsResolver->resolvePercent($billingCustomer);

                $invoice = TripInvoice::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'invoice_number' => $invoiceNumber,
                    'document_number_allocation_id' => $allocationId,
                    'billing_customer_party_id' => $billingCustomer->id,
                    'invoice_type' => $invoiceType,
                    'particulars_mode' => $particulars,
                    'status' => TripInvoiceStatus::Pending,
                    'payment_status' => TripInvoicePaymentStatus::Pending,
                    'invoice_date' => $invoiceDate,
                    'workflow_state' => 'pending_sample',
                    'base_freight_total' => $baseFreight,
                    'additional_charges_total' => $additional,
                    'taxable_total' => $taxable,
                    'gst_amount' => $gstAmount,
                    'invoice_total' => $invoiceTotal,
                    'amount_received_total' => '0.00',
                    'tds_received_total' => '0.00',
                    'deductions_total' => '0.00',
                    'settlement_total' => '0.00',
                    'balance_due' => $invoiceTotal,
                    'invoice_tds_rate_percent' => $tdsRate,
                    'tds_basis_freight' => $baseFreight,
                    'gst_rate_snapshot' => $gstSnapshot,
                    'billing_customer_snapshot' => $this->customerSnapshot($billingCustomer, $tdsRate),
                    'created_by_user_id' => $actor->id,
                ]);

                foreach ($lines as $line) {
                    TripInvoiceLine::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'trip_invoice_id' => $invoice->id,
                        ...$line,
                    ]);
                }

                foreach ($charges as $charge) {
                    TripInvoiceCharge::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'trip_invoice_id' => $invoice->id,
                        ...$charge,
                    ]);
                }

                $this->audit->write(
                    AuditAction::Created,
                    TripInvoice::class,
                    $invoice->id,
                    remark: 'Create OwnFleet trip invoice',
                    before: null,
                    after: $invoice->fresh(['lines', 'charges'])?->toArray(),
                );

                return $invoice->fresh(['lines', 'charges', 'payments', 'billingCustomer']) ?? $invoice;
            });
        });
    }

    /**
     * @param  list<string>  $lorryReceiptIds
     * @return Collection<int, LorryReceipt>
     */
    private function resolveEligibleReceipts(Company $company, Branch $branch, array $lorryReceiptIds): Collection
    {
        if ($lorryReceiptIds === []) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'At least one Lorry Receipt is required.',
            ]);
        }

        $receipts = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereIn('id', $lorryReceiptIds)
            ->whereNull('deleted_at')
            ->get();

        if ($receipts->count() !== count($lorryReceiptIds)) {
            throw new TripInvoiceDomainException('One or more Lorry Receipts were not found.');
        }

        foreach ($receipts as $receipt) {
            $this->assertReceiptInvoiceEligible($receipt);
        }

        return $receipts->sortBy('lr_date')->values();
    }

    private function assertReceiptInvoiceEligible(LorryReceipt $receipt): void
    {
        if ($receipt->workflow_context !== LrWorkflowContext::OwnFleet) {
            throw new TripInvoiceDomainException('Only OwnFleet Lorry Receipts can be invoiced here.');
        }

        if ($receipt->billing_customer_party_id === null) {
            throw ValidationException::withMessages([
                'billing_customer_party_id' => 'Billing Customer is required on the Lorry Receipt.',
            ]);
        }

        $trip = Trip::query()
            ->where('lorry_receipt_id', $receipt->id)
            ->whereNull('deleted_at')
            ->first();

        if ($trip === null || $trip->status !== TripStatus::Completed) {
            throw new TripInvoiceConflictException(
                'Invoice eligibility uses completed Trip status (LR delivered flag is not updated on Trip completion).',
            );
        }

        $activeLine = TripInvoiceLine::query()
            ->where('company_id', $receipt->company_id)
            ->where('lorry_receipt_id', $receipt->id)
            ->whereNull('deleted_at')
            ->whereHas('invoice', function ($query): void {
                $query->whereNull('deleted_at')
                    ->where('status', TripInvoiceStatus::Pending);
            })
            ->exists();

        if ($activeLine) {
            throw new TripInvoiceConflictException('This Lorry Receipt is already on an active invoice.');
        }
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<array{charge_type: TripInvoiceChargeType, description: string, amount: numeric-string}>
     */
    private function normalizeCharges(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = TripInvoiceChargeType::tryFrom((string) ($row['charge_type'] ?? ''));
            if ($type === null) {
                throw ValidationException::withMessages([
                    "charges.{$index}.charge_type" => 'Invalid additional charge type.',
                ]);
            }
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                throw ValidationException::withMessages([
                    "charges.{$index}.description" => 'Description is required.',
                ]);
            }
            $amount = $this->money($row['amount'] ?? null, "charges.{$index}.amount");
            if (bccomp($amount, '0', 2) !== 1) {
                throw ValidationException::withMessages([
                    "charges.{$index}.amount" => 'Additional charge amount must be positive.',
                ]);
            }

            $normalized[] = [
                'charge_type' => $type,
                'description' => $description,
                'amount' => $amount,
            ];
        }

        return $normalized;
    }

    /**
     * @param  numeric-string  $baseFreight
     * @param  numeric-string  $additional
     * @return array{numeric-string, numeric-string, numeric-string, array<string, mixed>|null}
     */
    private function computeTaxTotals(Company $company, string $baseFreight, string $additional): array
    {
        $taxable = bcadd($baseFreight, $additional, 2);
        $snapshot = $company->gst_rate_snapshot;
        $gstAmount = '0.00';

        if (is_array($snapshot) && filled($snapshot['rate_percent'] ?? null)) {
            $gstAmount = bcmul($taxable, bcdiv((string) $snapshot['rate_percent'], '100', 6), 2);
        } elseif (filled($company->gst_rate_id)) {
            $rate = GstRate::query()->find($company->gst_rate_id);
            if ($rate !== null) {
                $snapshot = $rate->toSnapshot();
                $gstAmount = bcmul($taxable, bcdiv((string) $rate->rate_percent, '100', 6), 2);
            }
        }

        $invoiceTotal = bcadd($taxable, $gstAmount, 2);

        return [$taxable, $gstAmount, $invoiceTotal, $snapshot];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string|null}
     */
    private function allocateNumber(Company $company, Branch $branch, array $payload): array
    {
        $scopeType = $this->visibility->isShared($company)
            ? DocumentNumberScopeType::Company
            : DocumentNumberScopeType::Branch;
        $scopeBranchId = $scopeType === DocumentNumberScopeType::Branch ? $branch->id : null;
        $mode = (string) ($payload['number_mode'] ?? 'auto');

        try {
            if ($mode === 'manual') {
                $manual = trim((string) ($payload['manual_number'] ?? ''));
                if ($manual === '') {
                    throw ValidationException::withMessages(['manual_number' => 'Manual invoice number is required.']);
                }
                $allocation = $this->numbers->allocateManual(
                    companyId: $company->id,
                    documentType: DocumentNumberType::TripInvoice,
                    scopeType: $scopeType,
                    number: $manual,
                    branchId: $scopeBranchId,
                );

                return [$manual, $allocation->id];
            }

            $result = $this->numbers->allocateNext(
                companyId: $company->id,
                documentType: DocumentNumberType::TripInvoice,
                scopeType: $scopeType,
                branchId: $scopeBranchId,
            );

            return [$result['number'], $result['allocation']->id];
        } catch (DocumentNumberConflictException $e) {
            throw new TripInvoiceConflictException($e->getMessage(), previous: $e);
        }
    }

    private function assertLatestDeletableInScope(TripInvoice $invoice, Company $company, Branch $branch): void
    {
        $query = TripInvoice::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at');

        if (! $this->visibility->isShared($company)) {
            $query->where('branch_id', $branch->id);
        }

        $latest = $query->orderByDesc('created_at')->first();
        if ($latest === null || $latest->id !== $invoice->id) {
            throw new TripInvoiceConflictException('Only the latest eligible invoice in scope can be deleted.');
        }
    }

    /**
     * @return array{
     *     party_id: string,
     *     name: string,
     *     gstin: string|null,
     *     pan: string|null,
     *     state: string|null,
     *     invoice_tds_rate_percent: string,
     * }
     */
    private function customerSnapshot(Party $party, string $tdsRate): array
    {
        return [
            'party_id' => $party->id,
            'name' => $party->name,
            'gstin' => $party->gstin,
            'pan' => $party->pan,
            'state' => $party->state,
            'invoice_tds_rate_percent' => $tdsRate,
        ];
    }

    private function assertCustomerRole(Company $company, Party $party): void
    {
        $hasRole = PartyRole::query()
            ->where('party_id', $party->id)
            ->where('company_id', $company->id)
            ->where('role', PartyRoleType::Customer->value)
            ->whereNull('deactivated_at')
            ->exists();

        if (! $hasRole) {
            throw ValidationException::withMessages([
                'billing_customer_party_id' => 'Billing Customer must have an active Customer role.',
            ]);
        }
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value, string $field): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'A valid amount is required.']);
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @return numeric-string
     */
    private function storedDecimal(mixed $value, int $scale = 2): string
    {
        if (! is_numeric($value)) {
            throw new TripInvoiceDomainException('Stored decimal value is not numeric.');
        }

        return number_format((float) $value, $scale, '.', '');
    }

    private function assertCanCreate(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CREATE, $company, $branch)) {
            throw new TripInvoiceAuthorizationException('Not authorized to create OwnFleet invoices.');
        }
    }

    private function assertCanCancel(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CANCEL, $company, $branch)) {
            throw new TripInvoiceAuthorizationException('Not authorized to cancel OwnFleet invoices.');
        }
    }

    private function assertCanEditCharges(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_EDIT, $company, $branch)) {
            throw new TripInvoiceAuthorizationException('Not authorized to edit OwnFleet invoice charges.');
        }
    }

    private function assertCanDelete(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_DELETE, $company, $branch)) {
            throw new TripInvoiceAuthorizationException('Not authorized to delete OwnFleet invoices.');
        }
    }

    private function assertOwnFleetEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::OwnFleet)) {
            throw new TripInvoiceDomainException('OwnFleet module is not entitled for this company.');
        }
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new TripInvoiceDomainException('Branch does not belong to company.');
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
