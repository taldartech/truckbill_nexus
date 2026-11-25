<?php

namespace App\Domains\Broker\Invoice;

use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\BrokerInvoiceStatus;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\Invoice\Enums\BrokerInvoicePaymentStatus;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Numbering\Exceptions\DocumentNumberConflictException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\BrokerAdjustment;
use App\Models\BrokerInvoice;
use App\Models\BrokerInvoiceLine;
use App\Models\BrokerInvoicePayment;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\Party;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Broker customer invoices — multi-LR consolidated billing after delivery (Rules §11 / PRD §13).
 */
final class BrokerInvoiceService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
    ) {}

    /**
     * @return LengthAwarePaginator<int, BrokerInvoice>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $paymentStatus = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = BrokerInvoice::query()
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
     * @return array{
     *     with_memo: Collection<int, LorryReceipt>,
     *     without_memo: Collection<int, LorryReceipt>
     * }
     */
    public function listEligibleLorryReceipts(Company $company, Branch $branch, ?string $billingCustomerId = null): array
    {
        $this->ensureTenantMatches($company, $branch);

        $receipts = $this->eligibleReceiptsQuery($company, $branch, $billingCustomerId)->get();

        $withMemo = new Collection;
        $withoutMemo = new Collection;

        foreach ($receipts as $receipt) {
            $memo = $receipt->delivery?->memo_number;
            if (filled($memo)) {
                $withMemo->push($receipt);
            } else {
                $withoutMemo->push($receipt);
            }
        }

        return [
            'with_memo' => $withMemo,
            'without_memo' => $withoutMemo,
        ];
    }

    /**
     * @param  list<string>  $lorryReceiptIds
     * @return array{
     *     lines: list<array{
     *         lorry_receipt_id: string,
     *         lr_number: string,
     *         freight_amount: string,
     *         charges_amount: string,
     *         shortage_damage_amount: string,
     *         line_total: string
     *     }>,
     *     freight_total: string,
     *     charges_total: string,
     *     shortage_damage_total: string,
     *     taxable_total: string,
     *     gst_amount: string,
     *     invoice_total: string,
     *     gst_rate_snapshot: array<string, mixed>|null
     * }
     */
    public function previewAmounts(Company $company, Branch $branch, array $lorryReceiptIds): array
    {
        $this->ensureTenantMatches($company, $branch);

        $receipts = $this->resolvePreviewReceipts($company, $branch, $lorryReceiptIds);
        $lines = [];
        $freightSum = '0.00';
        $chargesSum = '0.00';
        $shortageSum = '0.00';
        $taxableBase = '0.00';

        foreach ($receipts as $receipt) {
            $line = $this->computeLineAmounts($receipt);
            $lines[] = $line;
            $freightSum = bcadd($freightSum, $this->storedDecimal($line['freight_amount']), 2);
            $chargesSum = bcadd($chargesSum, $this->storedDecimal($line['charges_amount']), 2);
            $shortageSum = bcadd($shortageSum, $this->storedDecimal($line['shortage_damage_amount']), 2);
            $taxableBase = bcadd($taxableBase, $this->storedDecimal($line['line_total']), 2);
        }

        $billingCustomer = $receipts->first()?->billingCustomer;
        [$taxable, $gstAmount, $invoiceTotal, $snapshot] = $this->computeTaxTotals(
            $company,
            $taxableBase,
            '0.00',
            $billingCustomer,
        );

        return [
            'lines' => $lines,
            'freight_total' => $freightSum,
            'charges_total' => $chargesSum,
            'shortage_damage_total' => $shortageSum,
            'taxable_total' => $taxable,
            'gst_amount' => $gstAmount,
            'cgst_amount' => is_array($snapshot) ? ($snapshot['cgst_amount'] ?? '0.00') : '0.00',
            'sgst_amount' => is_array($snapshot) ? ($snapshot['sgst_amount'] ?? '0.00') : '0.00',
            'igst_amount' => is_array($snapshot) ? ($snapshot['igst_amount'] ?? '0.00') : '0.00',
            'cgst_rate' => is_array($snapshot) ? ($snapshot['cgst_rate'] ?? null) : null,
            'sgst_rate' => is_array($snapshot) ? ($snapshot['sgst_rate'] ?? null) : null,
            'igst_rate' => is_array($snapshot) ? ($snapshot['igst_rate'] ?? null) : null,
            'supply_type' => is_array($snapshot) ? ($snapshot['supply_type'] ?? null) : null,
            'tax_liability' => is_array($snapshot) ? ($snapshot['tax_liability'] ?? null) : null,
            'invoice_total' => $invoiceTotal,
            'gst_rate_snapshot' => is_array($snapshot) ? $snapshot : null,
        ];
    }

    /**
     * @param  array{
     *     billing_customer_party_id: string,
     *     lorry_receipt_ids: list<string>,
     *     invoice_date?: string|null,
     *     remark?: string|null
     * }  $payload
     */
    public function createInvoice(Company $company, Branch $branch, User $actor, array $payload): BrokerInvoice
    {
        $this->ensureTenantMatches($company, $branch);
        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);

        $lorryReceiptIds = array_values(array_unique(array_map('strval', $payload['lorry_receipt_ids'])));
        if ($lorryReceiptIds === []) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'Select at least one delivered Lorry Receipt.',
            ]);
        }

        $billingCustomerId = (string) $payload['billing_customer_party_id'];

        return $this->auditedTransaction->run(function () use ($company, $branch, $actor, $payload, $lorryReceiptIds, $billingCustomerId): BrokerInvoice {
            return $this->withTenant($company, $branch, function () use ($company, $branch, $actor, $payload, $lorryReceiptIds, $billingCustomerId): BrokerInvoice {
                $receipts = $this->resolveEligibleReceiptsForCreate($company, $branch, $lorryReceiptIds, $billingCustomerId);

                foreach ($receipts as $receipt) {
                    LorryReceipt::query()->whereKey($receipt->id)->lockForUpdate()->first();
                    $this->assertNotAlreadyInvoiced($company, $receipt->id);
                }

                $preview = $this->buildLinePayloads($receipts);
                $taxableBase = $preview['taxable_base'];
                $billingCustomer = Party::query()
                    ->where('company_id', $company->id)
                    ->whereKey($billingCustomerId)
                    ->whereNull('deleted_at')
                    ->first();
                [$taxable, $gstAmount, $invoiceTotal, $gstSnapshot] = $this->computeTaxTotals(
                    $company,
                    $taxableBase,
                    '0.00',
                    $billingCustomer,
                );

                try {
                    $allocation = $this->numbers->allocateNext(
                        companyId: $company->id,
                        documentType: DocumentNumberType::BrokerInvoice,
                        scopeType: DocumentNumberScopeType::Branch,
                        branchId: $branch->id,
                    );
                } catch (DocumentNumberConflictException $e) {
                    throw new BrokerConflictException($e->getMessage(), previous: $e);
                }

                $invoiceDate = filled($payload['invoice_date'] ?? null)
                    ? CarbonImmutable::parse((string) $payload['invoice_date'])->toDateString()
                    : CarbonImmutable::now('Asia/Kolkata')->toDateString();

                $firstReceipt = $receipts->first();
                $firstHire = $firstReceipt?->lorryHire;

                $invoice = BrokerInvoice::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'lorry_receipt_id' => $receipts->count() === 1 ? $firstReceipt?->id : null,
                    'lorry_hire_id' => $receipts->count() === 1 ? $firstHire?->id : null,
                    'billing_customer_party_id' => $billingCustomerId,
                    'invoice_number' => $allocation['number'],
                    'status' => BrokerInvoiceStatus::Issued,
                    'payment_status' => BrokerInvoicePaymentStatus::Unpaid->value,
                    'invoice_date' => $invoiceDate,
                    'taxable_total' => $taxable,
                    'gst_amount' => $gstAmount,
                    'invoice_total' => $invoiceTotal,
                    'amount_received' => '0.00',
                    'customer_tds_amount' => '0.00',
                    'balance_due' => $invoiceTotal,
                    'gst_rate_snapshot' => $gstSnapshot,
                    'created_by_user_id' => $actor->id,
                ]);

                $position = 1;
                foreach ($preview['lines'] as $line) {
                    BrokerInvoiceLine::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'broker_invoice_id' => $invoice->id,
                        'lorry_receipt_id' => $line['lorry_receipt_id'],
                        'lorry_hire_id' => $line['lorry_hire_id'],
                        'memo_number' => $line['memo_number'],
                        'pod_number' => $line['pod_number'],
                        'description' => 'LR '.$line['lr_number'],
                        'freight_amount' => $line['freight_amount'],
                        'charges_amount' => $line['charges_amount'],
                        'shortage_damage_amount' => $line['shortage_damage_amount'],
                        'line_total' => $line['line_total'],
                        'amount' => $line['line_total'],
                        'position' => $position,
                    ]);
                    LorryReceipt::query()
                        ->where('id', $line['lorry_receipt_id'])
                        ->where('company_id', $company->id)
                        ->where('status', LrStatus::Delivered->value)
                        ->update(['status' => LrStatus::Invoiced->value]);
                    $position++;
                }

                $this->audit->write(
                    AuditAction::Created,
                    BrokerInvoice::class,
                    $invoice->id,
                    remark: filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : 'Create Broker customer invoice',
                    before: null,
                    after: $invoice->fresh(['lines', 'billingCustomer'])?->toArray(),
                );

                return $invoice->fresh(['lines', 'payments', 'billingCustomer']) ?? $invoice;
            });
        });
    }

    /**
     * @param  array{
     *     amount_received: numeric-string|float|int,
     *     tds_amount?: numeric-string|float|int,
     *     deduction_amount?: numeric-string|float|int,
     *     payment_mode?: string|null,
     *     payment_date?: string|null,
     *     reference?: string|null,
     *     remark?: string|null,
     *     bank_cash_account_id?: string|null
     * }  $payload
     */
    public function recordPayment(BrokerInvoice $invoice, User $actor, array $payload): BrokerInvoicePayment
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);

        return $this->auditedTransaction->run(function () use ($invoice, $company, $branch, $actor, $payload): BrokerInvoicePayment {
            return $this->withTenant($company, $branch, function () use ($invoice, $company, $actor, $payload): BrokerInvoicePayment {
                $locked = BrokerInvoice::query()
                    ->whereKey($invoice->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status === BrokerInvoiceStatus::Cancelled) {
                    throw new BrokerConflictException('Payments cannot be recorded on cancelled invoices.');
                }

                $amountReceived = $this->money($payload['amount_received'], 'amount_received');
                $tdsAmount = $this->money($payload['tds_amount'] ?? '0', 'tds_amount');
                $deductionAmount = $this->money($payload['deduction_amount'] ?? '0', 'deduction_amount');

                foreach (['amount_received' => $amountReceived, 'tds_amount' => $tdsAmount, 'deduction_amount' => $deductionAmount] as $field => $val) {
                    if (bccomp($val, '0', 2) === -1) {
                        throw ValidationException::withMessages([$field => 'Value cannot be negative.']);
                    }
                }

                $settlement = bcadd($amountReceived, bcadd($tdsAmount, $deductionAmount, 2), 2);
                if (bccomp($settlement, '0', 2) !== 1) {
                    throw ValidationException::withMessages([
                        'amount_received' => 'Payment settlement must be greater than zero.',
                    ]);
                }

                $remaining = $this->storedDecimal($locked->balance_due);
                if (bccomp($settlement, $remaining, 2) === 1) {
                    throw new BrokerConflictException('Settlement cannot exceed the remaining balance.');
                }

                $paymentDate = filled($payload['payment_date'] ?? null)
                    ? CarbonImmutable::parse((string) $payload['payment_date'])->toDateString()
                    : CarbonImmutable::now('Asia/Kolkata')->toDateString();

                $payment = BrokerInvoicePayment::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'broker_invoice_id' => $locked->id,
                    'amount_received' => $amountReceived,
                    'tds_amount' => $tdsAmount,
                    'deduction_amount' => $deductionAmount,
                    'payment_date' => $paymentDate,
                    'payment_mode' => filled($payload['payment_mode'] ?? null) ? trim((string) $payload['payment_mode']) : null,
                    'reference_number' => filled($payload['reference'] ?? null) ? trim((string) $payload['reference']) : null,
                    'bank_cash_account_id' => filled($payload['bank_cash_account_id'] ?? null) ? (string) $payload['bank_cash_account_id'] : null,
                    'remark' => filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : null,
                    'created_by_user_id' => $actor->id,
                ]);

                $newReceived = bcadd($this->storedDecimal($locked->amount_received), $amountReceived, 2);
                $newTds = bcadd($this->storedDecimal($locked->customer_tds_amount), $tdsAmount, 2);
                $newBalance = bcsub($remaining, $settlement, 2);
                if (bccomp($newBalance, '0', 2) === -1) {
                    $newBalance = '0.00';
                }

                $paymentStatus = BrokerInvoicePaymentStatus::Partial;
                $invoiceStatus = BrokerInvoiceStatus::Issued;
                if (bccomp($newBalance, '0', 2) === 0) {
                    $paymentStatus = BrokerInvoicePaymentStatus::Paid;
                    $invoiceStatus = BrokerInvoiceStatus::Paid;
                } elseif (bccomp($newReceived, '0', 2) === 0 && bccomp($newTds, '0', 2) === 0) {
                    $paymentStatus = BrokerInvoicePaymentStatus::Unpaid;
                }

                $before = $locked->toArray();
                $locked->forceFill([
                    'amount_received' => $newReceived,
                    'customer_tds_amount' => $newTds,
                    'balance_due' => $newBalance,
                    'payment_status' => $paymentStatus->value,
                    'status' => $invoiceStatus,
                ])->save();

                $this->audit->write(
                    AuditAction::Updated,
                    BrokerInvoice::class,
                    $locked->id,
                    remark: 'Record Broker customer invoice payment',
                    before: $before,
                    after: $locked->fresh(['payments'])?->toArray(),
                );

                return $payment;
            });
        });
    }

    public function cancel(BrokerInvoice $invoice, User $actor, string $reason): BrokerInvoice
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->assertCanCancel($actor, $company, $branch);
        $this->assertBrokerEntitled($company);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['cancellation_remark' => 'Cancellation reason is required.']);
        }

        return $this->auditedTransaction->run(function () use ($invoice, $company, $branch, $reason): BrokerInvoice {
            return $this->withTenant($company, $branch, function () use ($invoice, $reason): BrokerInvoice {
                $locked = BrokerInvoice::query()
                    ->whereKey($invoice->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status === BrokerInvoiceStatus::Cancelled) {
                    throw new BrokerConflictException('Invoice is already cancelled.');
                }

                $before = $locked->toArray();

                $receiptIds = BrokerInvoiceLine::query()
                    ->where('broker_invoice_id', $locked->id)
                    ->whereNull('deleted_at')
                    ->pluck('lorry_receipt_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                BrokerInvoiceLine::query()
                    ->where('broker_invoice_id', $locked->id)
                    ->whereNull('deleted_at')
                    ->each(fn (BrokerInvoiceLine $line) => $line->delete());

                if ($receiptIds !== []) {
                    LorryReceipt::query()
                        ->whereIn('id', $receiptIds)
                        ->where('status', LrStatus::Invoiced->value)
                        ->update(['status' => LrStatus::Delivered->value]);
                }

                $locked->forceFill([
                    'status' => BrokerInvoiceStatus::Cancelled,
                    'payment_status' => BrokerInvoicePaymentStatus::Unpaid->value,
                    'cancelled_at' => CarbonImmutable::now('UTC'),
                    'cancellation_remark' => $reason,
                ])->save();

                $this->audit->write(
                    AuditAction::Updated,
                    BrokerInvoice::class,
                    $locked->id,
                    remark: 'Cancel Broker customer invoice: '.$reason,
                    before: $before,
                    after: $locked->fresh(['lines'])?->toArray(),
                );

                return $locked->fresh(['lines', 'payments', 'billingCustomer']) ?? $locked;
            });
        });
    }

    /**
     * @param  Collection<int, LorryReceipt>  $receipts
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     taxable_base: string
     * }
     */
    private function buildLinePayloads(Collection $receipts): array
    {
        $lines = [];
        $taxableBase = '0.00';

        foreach ($receipts as $receipt) {
            $amounts = $this->computeLineAmounts($receipt);
            $hire = $receipt->lorryHire;
            $lines[] = [
                'lorry_receipt_id' => $receipt->id,
                'lorry_hire_id' => $hire?->id,
                'lr_number' => $receipt->lr_number,
                'memo_number' => $receipt->delivery?->memo_number,
                'pod_number' => $receipt->delivery?->pod_number,
                ...$amounts,
            ];
            $taxableBase = bcadd($taxableBase, $this->storedDecimal($amounts['line_total']), 2);
        }

        return ['lines' => $lines, 'taxable_base' => $taxableBase];
    }

    /**
     * @return array{
     *     lorry_receipt_id: string,
     *     lr_number: string,
     *     freight_amount: string,
     *     charges_amount: string,
     *     shortage_damage_amount: string,
     *     line_total: string
     * }
     */
    private function computeLineAmounts(LorryReceipt $receipt): array
    {
        $freight = $this->storedDecimal($receipt->freight_total);
        $charges = $this->storedDecimal($receipt->additional_charges_total);
        $shortageDamage = $this->sumShortageDamageForHire($receipt->lorryHire);

        $subtotal = bcadd($this->storedDecimal($freight), $this->storedDecimal($charges), 2);
        $lineTotal = bcsub($subtotal, $this->storedDecimal($shortageDamage), 2);
        if (bccomp($lineTotal, '0', 2) === -1) {
            $lineTotal = '0.00';
        }

        return [
            'lorry_receipt_id' => $receipt->id,
            'lr_number' => (string) $receipt->lr_number,
            'freight_amount' => $freight,
            'charges_amount' => $charges,
            'shortage_damage_amount' => $shortageDamage,
            'line_total' => $lineTotal,
        ];
    }

    private function sumShortageDamageForHire(?LorryHire $hire): string
    {
        if ($hire === null) {
            return '0.00';
        }

        $sum = BrokerAdjustment::query()
            ->where('company_id', $hire->company_id)
            ->where('lorry_hire_id', $hire->id)
            ->whereNull('deleted_at')
            ->whereIn('type', [
                BrokerAdjustmentType::Shortage->value,
                BrokerAdjustmentType::Damage->value,
            ])
            ->sum('amount');

        return $this->money($sum);
    }

    /**
     * @param  list<string>  $lorryReceiptIds
     * @return Collection<int, LorryReceipt>
     */
    private function resolvePreviewReceipts(Company $company, Branch $branch, array $lorryReceiptIds): Collection
    {
        if ($lorryReceiptIds === []) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'Select at least one Lorry Receipt.',
            ]);
        }

        $receipts = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereIn('id', $lorryReceiptIds)
            ->whereNull('deleted_at')
            ->with(['lorryHire', 'delivery', 'billingCustomer'])
            ->get();

        if ($receipts->count() !== count($lorryReceiptIds)) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'One or more Lorry Receipts are invalid.',
            ]);
        }

        return $receipts;
    }

    /**
     * @param  list<string>  $lorryReceiptIds
     * @return Collection<int, LorryReceipt>
     */
    private function resolveEligibleReceiptsForCreate(
        Company $company,
        Branch $branch,
        array $lorryReceiptIds,
        string $billingCustomerId,
    ): Collection {
        $receipts = $this->resolvePreviewReceipts($company, $branch, $lorryReceiptIds);
        $customerIds = [];

        foreach ($receipts as $receipt) {
            $this->assertDeliveredBrokerReceipt($receipt);

            if ($receipt->billing_customer_party_id !== $billingCustomerId) {
                throw new BrokerConflictException('All selected LRs must share the same billing customer.');
            }

            $customerIds[$receipt->billing_customer_party_id ?? ''] = true;

            if ($receipt->lorryHire === null || $receipt->lorryHire->status === LorryHireStatus::Cancelled) {
                throw new BrokerConflictException('Each LR must have an active Lorry Hire.');
            }
        }

        if (count($customerIds) !== 1) {
            throw new BrokerConflictException('All selected LRs must share the same billing customer.');
        }

        return $receipts;
    }

    private function assertNotAlreadyInvoiced(Company $company, string $lorryReceiptId): void
    {
        $exists = BrokerInvoiceLine::query()
            ->where('company_id', $company->id)
            ->where('lorry_receipt_id', $lorryReceiptId)
            ->whereNull('deleted_at')
            ->whereHas('brokerInvoice', function ($query): void {
                $query->whereNull('deleted_at')
                    ->where('status', '!=', BrokerInvoiceStatus::Cancelled->value);
            })
            ->exists();

        if ($exists) {
            throw new BrokerConflictException('One or more LRs are already on an active customer invoice.');
        }
    }

    private function assertDeliveredBrokerReceipt(LorryReceipt $receipt): void
    {
        if ($receipt->workflow_context !== LrWorkflowContext::Broker) {
            throw new BrokerConflictException('Only Broker workflow LRs can be invoiced.');
        }

        if ($receipt->status !== LrStatus::Delivered || $receipt->delivered_at === null) {
            throw new BrokerConflictException('Only delivered LRs can be invoiced.');
        }
    }

    /**
     * @return Builder<LorryReceipt>
     */
    private function eligibleReceiptsQuery(Company $company, Branch $branch, ?string $billingCustomerId)
    {
        $invoicedIds = BrokerInvoiceLine::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('brokerInvoice', function ($query): void {
                $query->whereNull('deleted_at')
                    ->where('status', '!=', BrokerInvoiceStatus::Cancelled->value);
            })
            ->pluck('lorry_receipt_id');

        $builder = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('workflow_context', LrWorkflowContext::Broker->value)
            ->where('status', LrStatus::Delivered->value)
            ->whereNotNull('delivered_at')
            ->whereNull('deleted_at')
            ->whereNotIn('id', $invoicedIds)
            ->whereHas('lorryHire', function ($query): void {
                $query->whereNull('deleted_at')
                    ->where('status', '!=', LorryHireStatus::Cancelled->value);
            })
            ->with(['billingCustomer', 'lorryHire', 'delivery'])
            ->orderBy('lr_date')
            ->orderBy('lr_number');

        if (filled($billingCustomerId)) {
            $builder->where('billing_customer_party_id', $billingCustomerId);
        }

        return $builder;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: array<string, mixed>|null}
     */
    private function computeTaxTotals(
        Company $company,
        string $baseFreight,
        string $additional,
        ?Party $billingCustomer = null,
    ): array {
        $taxable = bcadd($this->storedDecimal($baseFreight), $this->storedDecimal($additional), 2);
        $snapshot = is_array($company->gst_rate_snapshot) ? $company->gst_rate_snapshot : [];
        $ratePercent = '0';

        if (filled($snapshot['rate_percent'] ?? null)) {
            $ratePercent = (string) $snapshot['rate_percent'];
        } elseif (filled($company->gst_rate_id)) {
            $rate = GstRate::query()->find($company->gst_rate_id);
            if ($rate !== null) {
                $snapshot = $rate->toSnapshot();
                $ratePercent = (string) $rate->rate_percent;
            }
        }

        $registration = $company->gst_registration_type;
        $taxLiability = $company->tax_liability ?? TaxLiability::Fcm;

        $cgst = '0.00';
        $sgst = '0.00';
        $igst = '0.00';
        $gstAmount = '0.00';
        $supplyType = null;
        $cgstRate = null;
        $sgstRate = null;
        $igstRate = null;

        // Company registration gates taxability. Customer GST flags do not blank Forward Charge.
        $taxableForGst = $registration === GstRegistrationType::Unregistered
            || $registration === GstRegistrationType::Composition
            || bccomp($ratePercent, '0', 4) <= 0
            ? false
            : true;

        if ($taxableForGst) {
            $companyState = strtoupper(trim((string) ($company->state ?? '')));
            $customerState = strtoupper(trim((string) ($billingCustomer?->state ?? '')));
            $intraState = $companyState !== ''
                && $customerState !== ''
                && $companyState === $customerState;

            $supplyType = $intraState ? 'intra_state' : 'inter_state';
            $halfRate = bcdiv($ratePercent, '2', 4);

            if ($intraState) {
                $cgstRate = $halfRate;
                $sgstRate = $halfRate;
                $cgst = bcmul($taxable, bcdiv($halfRate, '100', 6), 2);
                $sgst = bcmul($taxable, bcdiv($halfRate, '100', 6), 2);
                $gstAmount = bcadd($cgst, $sgst, 2);
            } else {
                $igstRate = $ratePercent;
                $igst = bcmul($taxable, bcdiv($ratePercent, '100', 6), 2);
                $gstAmount = $igst;
            }
        }

        // Forward Charge: GST adds to invoice total. RCM: tax shown but not collected in invoice total.
        $invoiceTotal = $taxLiability === TaxLiability::Rcm
            ? $taxable
            : bcadd($taxable, $gstAmount, 2);

        $snapshot = array_merge($snapshot, [
            'rate_percent' => $ratePercent,
            'tax_liability' => $taxLiability->value,
            'supply_type' => $supplyType,
            'cgst_rate' => $cgstRate,
            'sgst_rate' => $sgstRate,
            'igst_rate' => $igstRate,
            'cgst_amount' => $cgst,
            'sgst_amount' => $sgst,
            'igst_amount' => $igst,
            'gst_amount' => $gstAmount,
        ]);

        return [$taxable, $gstAmount, $invoiceTotal, $snapshot];
    }

    private function assertCanCreate(User $actor, Company $company, Branch $branch): void
    {
        if ($actor->id === $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_CREATE, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to create Broker customer invoices.');
        }
    }

    private function assertCanCancel(User $actor, Company $company, Branch $branch): void
    {
        if ($actor->id === $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_CANCEL, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to cancel Broker customer invoices.');
        }
    }

    private function assertBrokerEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Broker)) {
            throw new BrokerDomainException('Broker module is not entitled for this company.');
        }
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new BrokerDomainException('Branch does not belong to company.');
        }
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value, ?string $field = null): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            if ($field !== null) {
                throw ValidationException::withMessages([$field => 'Amount is invalid.']);
            }

            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @return numeric-string
     */
    private function storedDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
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
