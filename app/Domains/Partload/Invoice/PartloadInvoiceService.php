<?php

namespace App\Domains\Partload\Invoice;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\Exceptions\PartloadDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Domains\Tax\EffectiveGstConfigurationResolver;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\LorryReceipt;
use App\Models\PartloadDeliveryReceipt;
use App\Models\PartloadInvoice;
use App\Models\PartloadInvoiceLine;
use App\Models\PartloadInvoicePayment;
use App\Models\Party;
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
 * Partload customer invoices & customer receipt settlements for TBB / Due DR Bilties.
 */
final class PartloadInvoiceService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
        private readonly EffectiveGstConfigurationResolver $gstResolver = new EffectiveGstConfigurationResolver,
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
        private readonly ?CompanyCapabilityResolver $capabilities = null,
    ) {}

    /**
     * @return LengthAwarePaginator<int, PartloadInvoice>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $paymentStatus = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = PartloadInvoice::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['billingCustomer', 'lines.lorryReceipt'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at');

        if (filled($paymentStatus)) {
            $builder->where('payment_status', $paymentStatus);
        }

        return $builder->paginate($perPage);
    }

    public function findForBranch(Company $company, Branch $branch, string $invoiceId): PartloadInvoice
    {
        $this->ensureTenantMatches($company, $branch);

        $invoice = PartloadInvoice::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['billingCustomer', 'lines.lorryReceipt', 'lines.deliveryReceipt', 'payments'])
            ->find($invoiceId);

        if ($invoice === null) {
            throw new PartloadDomainException('Partload Invoice was not found in this branch.');
        }

        return $invoice;
    }

    /**
     * Eligible Partload Bilties (TBB bookings or Due Delivery Receipts) not yet on an issued Partload Invoice.
     *
     * @return Collection<int, LorryReceipt>
     */
    public function listEligibleLorryReceipts(
        Company $company,
        Branch $branch,
        ?string $billingCustomerId = null,
    ): Collection {
        $this->ensureTenantMatches($company, $branch);

        $invoicedIds = PartloadInvoiceLine::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('invoice', fn ($q) => $q->whereNull('deleted_at')->where('status', 'issued'))
            ->pluck('lorry_receipt_id')
            ->filter();

        $builder = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('workflow_context', LrWorkflowContext::Partload->value)
            ->whereNull('deleted_at')
            ->whereNull('cancelled_at')
            ->whereIn('status', [LrStatus::Pending->value, LrStatus::InProgress->value, LrStatus::Delivered->value])
            ->where(function ($query): void {
                $query->where(function ($tbb): void {
                    $tbb->where('freight_payment_term', PartloadFreightPaymentTerm::ToBeBilled->value)
                        ->whereNotNull('billing_customer_party_id');
                })->orWhereHas('partloadDeliveryReceipt', function ($dr): void {
                    $dr->whereNull('deleted_at')
                        ->where('payment_mode', PartloadDeliveryPaymentMode::Due->value)
                        ->whereNotNull('payment_due_from_party_id');
                });
            })
            ->with(['billingCustomer', 'partloadDeliveryReceipt.dueFromParty', 'fromStation', 'toStation'])
            ->orderBy('lr_date')
            ->orderBy('lr_number');

        if ($invoicedIds->isNotEmpty()) {
            $builder->whereNotIn('id', $invoicedIds->all());
        }

        $items = $builder->limit(250)->get();

        if (filled($billingCustomerId)) {
            $items = $items->filter(
                fn (LorryReceipt $lr): bool => $this->resolveCustomerIdForReceipt($lr) === $billingCustomerId
            )->values();
        }

        return $items;
    }

    /**
     * @param  array{
     *     lorry_receipt_ids: list<string>,
     *     invoice_date?: string|null,
     * }  $payload
     */
    public function create(Company $company, Branch $branch, User $actor, array $payload): PartloadInvoice
    {
        $this->assertEntitled($company, mutate: true);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_CREATE);
        $this->ensureTenantMatches($company, $branch);

        $lrIds = array_values(array_unique(array_filter(
            $payload['lorry_receipt_ids'],
            static fn (string $id): bool => $id !== '',
        )));

        if ($lrIds === []) {
            throw ValidationException::withMessages(['lorry_receipt_ids' => 'Select at least one Partload Bilty.']);
        }

        $invoiceDate = filled($payload['invoice_date'] ?? null)
            ? CarbonImmutable::parse((string) $payload['invoice_date'])->toDateString()
            : CarbonImmutable::now($company->timezone ?? 'Asia/Kolkata')->toDateString();

        return $this->auditedTransaction->run(function () use ($company, $branch, $actor, $lrIds, $invoiceDate): PartloadInvoice {
            return $this->withTenant($company, $branch, function () use ($company, $branch, $actor, $lrIds, $invoiceDate): PartloadInvoice {
                $receipts = LorryReceipt::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branch->id)
                    ->where('workflow_context', LrWorkflowContext::Partload->value)
                    ->whereIn('id', $lrIds)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->with(['partloadDeliveryReceipt', 'additionalCharges'])
                    ->get();

                if ($receipts->count() !== count($lrIds)) {
                    throw ValidationException::withMessages([
                        'lorry_receipt_ids' => 'One or more Partload Bilties were not found in this branch.',
                    ]);
                }

                $alreadyInvoiced = PartloadInvoiceLine::query()
                    ->where('company_id', $company->id)
                    ->whereIn('lorry_receipt_id', $lrIds)
                    ->whereNull('deleted_at')
                    ->whereHas('invoice', fn ($q) => $q->whereNull('deleted_at')->where('status', 'issued'))
                    ->exists();

                if ($alreadyInvoiced) {
                    throw new PartloadConflictException('One or more selected Bilties are already invoiced.');
                }

                $customerId = null;
                $lines = [];
                $taxableTotal = '0.00';

                foreach ($receipts as $idx => $lr) {
                    if ($lr->cancelled_at !== null || $lr->status === LrStatus::Cancelled || $lr->status === LrStatus::Invoiced) {
                        throw new PartloadConflictException("Bilty {$lr->lr_number} is not eligible for invoicing.");
                    }

                    $resolvedCustomer = $this->resolveCustomerIdForReceipt($lr);
                    if ($resolvedCustomer === null) {
                        throw ValidationException::withMessages([
                            'lorry_receipt_ids' => "Bilty {$lr->lr_number} has no billable customer (TBB or Due DR).",
                        ]);
                    }

                    if ($customerId === null) {
                        $customerId = $resolvedCustomer;
                    } elseif ($customerId !== $resolvedCustomer) {
                        throw ValidationException::withMessages([
                            'lorry_receipt_ids' => 'All selected Partload Bilties must share the same Billing Customer.',
                        ]);
                    }

                    /** @var PartloadDeliveryReceipt|null $dr */
                    $dr = $lr->partloadDeliveryReceipt;
                    if ($dr !== null && $dr->payment_mode === PartloadDeliveryPaymentMode::Due) {
                        $freight = number_format((float) $dr->freight, 2, '.', '');
                        $charges = bcsub(number_format((float) $dr->final_amount, 2, '.', ''), $freight, 2);
                        $lineTotal = number_format((float) $dr->final_amount, 2, '.', '');
                    } else {
                        $freight = number_format((float) $lr->freight_total, 2, '.', '');
                        $charges = number_format((float) $lr->additional_charges_total, 2, '.', '');
                        $lineTotal = bcadd($freight, $charges, 2);
                    }

                    $taxableTotal = bcadd($taxableTotal, $lineTotal, 2);

                    $lines[] = [
                        'lorry_receipt_id' => $lr->id,
                        'partload_delivery_receipt_id' => $dr?->id,
                        'description' => "LR {$lr->lr_number}",
                        'freight_amount' => $freight,
                        'charges_amount' => $charges,
                        'line_total' => $lineTotal,
                        'position' => $idx + 1,
                    ];
                }

                $billingCustomer = Party::query()->whereKey((string) $customerId)->firstOrFail();
                $gst = $this->calculateGst($company, $branch, $billingCustomer, $taxableTotal);

                $allocation = $this->numbers->allocateNext(
                    companyId: $company->id,
                    documentType: DocumentNumberType::PartloadInvoice,
                    scopeType: DocumentNumberScopeType::Company,
                    branchId: null,
                );

                $invoice = PartloadInvoice::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'billing_customer_party_id' => $billingCustomer->id,
                    'invoice_number' => $allocation['number'],
                    'status' => 'issued',
                    'payment_status' => 'unpaid',
                    'invoice_date' => $invoiceDate,
                    'taxable_total' => $taxableTotal,
                    'gst_amount' => $gst['gst_amount'],
                    'invoice_total' => $gst['invoice_total'],
                    'amount_received' => '0.00',
                    'customer_tds_amount' => '0.00',
                    'balance_due' => $gst['invoice_total'],
                    'gst_rate_snapshot' => $gst['snapshot'],
                    'created_by_user_id' => $actor->id,
                ]);

                foreach ($lines as $line) {
                    PartloadInvoiceLine::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'partload_invoice_id' => $invoice->id,
                        ...$line,
                    ]);

                    LorryReceipt::query()
                        ->where('id', $line['lorry_receipt_id'])
                        ->where('company_id', $company->id)
                        ->update(['status' => LrStatus::Invoiced->value]);
                }

                $this->audit->write(
                    AuditAction::Created,
                    PartloadInvoice::class,
                    $invoice->id,
                    remark: 'Create Partload customer invoice',
                    before: null,
                    after: ['invoice_number' => $invoice->invoice_number, 'invoice_total' => $invoice->invoice_total],
                );

                $fresh = $invoice->fresh(['billingCustomer', 'lines.lorryReceipt', 'lines.deliveryReceipt', 'payments']) ?? $invoice;
                $this->accounting->postPartloadInvoiceIssued($fresh, $actor);

                return $fresh;
            });
        });
    }

    /**
     * @param  array{
     *     amount_received: numeric-string|float|int,
     *     tds_amount?: numeric-string|float|int|null,
     *     deduction_amount?: numeric-string|float|int|null,
     *     payment_date?: string|null,
     *     payment_mode?: string|null,
     *     bank_cash_account_id: string,
     *     reference?: string|null,
     *     remark?: string|null,
     * }  $payload
     */
    public function recordPayment(PartloadInvoice $invoice, User $actor, array $payload): PartloadInvoicePayment
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->assertEntitled($company, mutate: true);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_EDIT);

        return $this->auditedTransaction->run(function () use ($invoice, $company, $branch, $actor, $payload): PartloadInvoicePayment {
            return $this->withTenant($company, $branch, function () use ($invoice, $company, $branch, $actor, $payload): PartloadInvoicePayment {
                $locked = PartloadInvoice::query()->whereKey($invoice->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();

                if ($locked->status !== 'issued') {
                    throw new PartloadConflictException('Payments can only be recorded against issued invoices.');
                }

                $received = number_format((float) $payload['amount_received'], 2, '.', '');
                $tds = number_format((float) ($payload['tds_amount'] ?? 0), 2, '.', '');
                $deduction = number_format((float) ($payload['deduction_amount'] ?? 0), 2, '.', '');

                if (bccomp($received, '0.00', 2) !== 1) {
                    throw ValidationException::withMessages(['amount_received' => 'Received amount must be greater than zero.']);
                }
                if (bccomp($tds, '0.00', 2) === -1 || bccomp($deduction, '0.00', 2) === -1) {
                    throw ValidationException::withMessages(['tds_amount' => 'TDS and deductions cannot be negative.']);
                }

                $settled = bcadd($received, bcadd($tds, $deduction, 2), 2);
                $balanceDue = number_format((float) $locked->balance_due, 2, '.', '');
                if (bccomp($settled, $balanceDue, 2) === 1) {
                    throw new PartloadConflictException("Settlement ({$settled}) exceeds balance due ({$balanceDue}).");
                }

                $accountId = (string) $payload['bank_cash_account_id'];
                $account = BankCashAccount::query()
                    ->where('company_id', $company->id)
                    ->whereKey($accountId)
                    ->whereNull('deleted_at')
                    ->first();
                if ($account === null || ($account->branch_id !== null && $account->branch_id !== $branch->id)) {
                    throw ValidationException::withMessages(['bank_cash_account_id' => 'Bank/Cash account is not available in this branch.']);
                }

                $paymentMode = (string) ($payload['payment_mode'] ?? 'bank');
                if (! in_array($paymentMode, ['cash', 'bank', 'cheque', 'upi', 'neft'], true)) {
                    $paymentMode = 'bank';
                }

                $payment = PartloadInvoicePayment::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'partload_invoice_id' => $locked->id,
                    'amount_received' => $received,
                    'tds_amount' => $tds,
                    'deduction_amount' => $deduction,
                    'payment_date' => filled($payload['payment_date'] ?? null)
                        ? CarbonImmutable::parse((string) $payload['payment_date'])->toDateString()
                        : now()->toDateString(),
                    'payment_mode' => $paymentMode,
                    'bank_cash_account_id' => $accountId,
                    'reference' => filled($payload['reference'] ?? null) ? trim((string) $payload['reference']) : null,
                    'remark' => filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : null,
                    'created_by_user_id' => $actor->id,
                ]);

                $newReceived = bcadd(number_format((float) $locked->amount_received, 2, '.', ''), $received, 2);
                $newTds = bcadd(number_format((float) $locked->customer_tds_amount, 2, '.', ''), $tds, 2);
                $newBalance = bcsub($balanceDue, $settled, 2);
                $newStatus = bccomp($newBalance, '0.00', 2) === 0 ? 'paid' : 'partial';

                $locked->forceFill([
                    'amount_received' => $newReceived,
                    'customer_tds_amount' => $newTds,
                    'balance_due' => $newBalance,
                    'payment_status' => $newStatus,
                ])->save();

                $this->audit->write(
                    AuditAction::Created,
                    PartloadInvoicePayment::class,
                    $payment->id,
                    remark: 'Partload customer invoice payment recorded',
                    before: null,
                    after: $payment->toArray(),
                );

                $this->accounting->postPartloadInvoicePayment($payment, $locked->fresh() ?? $locked, $actor);

                return $payment;
            });
        });
    }

    public function cancel(PartloadInvoice $invoice, User $actor, string $remark): PartloadInvoice
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->assertEntitled($company, mutate: true);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_CANCEL);

        $remark = trim($remark);
        if ($remark === '') {
            throw ValidationException::withMessages(['cancellation_remark' => 'Cancellation reason is required.']);
        }

        return $this->auditedTransaction->run(function () use ($invoice, $company, $branch, $actor, $remark): PartloadInvoice {
            return $this->withTenant($company, $branch, function () use ($invoice, $actor, $remark): PartloadInvoice {
                $locked = PartloadInvoice::query()->whereKey($invoice->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();

                if ($locked->status === 'cancelled') {
                    throw new PartloadConflictException('Invoice is already cancelled.');
                }

                if ($locked->payments()->whereNull('deleted_at')->exists()) {
                    throw new PartloadConflictException('Invoices with recorded receipts cannot be cancelled.');
                }

                $before = $locked->toArray();

                $lines = PartloadInvoiceLine::query()
                    ->where('partload_invoice_id', $locked->id)
                    ->whereNull('deleted_at')
                    ->get();

                foreach ($lines as $line) {
                    if ($line->lorry_receipt_id !== null) {
                        $lr = LorryReceipt::query()->find($line->lorry_receipt_id);
                        if ($lr !== null && $lr->status === LrStatus::Invoiced) {
                            $lr->forceFill([
                                'status' => $lr->delivered_at !== null ? LrStatus::Delivered : LrStatus::InProgress,
                            ])->save();
                        }
                    }
                    $line->delete();
                }

                $locked->forceFill([
                    'status' => 'cancelled',
                    'payment_status' => 'unpaid',
                    'cancelled_at' => now(),
                    'cancellation_remark' => $remark,
                ])->save();

                $this->audit->write(
                    AuditAction::Cancelled,
                    PartloadInvoice::class,
                    $locked->id,
                    remark: $remark,
                    before: $before,
                    after: ['status' => 'cancelled'],
                    cancellationReason: $remark,
                    cancellationAt: $locked->cancelled_at,
                );

                $this->accounting->postPartloadInvoiceCancelled($locked, $actor, $remark);

                return $locked->fresh(['billingCustomer', 'lines', 'payments']) ?? $locked;
            });
        });
    }

    private function resolveCustomerIdForReceipt(LorryReceipt $lr): ?string
    {
        if ($lr->partloadDeliveryReceipt !== null
            && $lr->partloadDeliveryReceipt->payment_mode === PartloadDeliveryPaymentMode::Due
            && filled($lr->partloadDeliveryReceipt->payment_due_from_party_id)
        ) {
            return (string) $lr->partloadDeliveryReceipt->payment_due_from_party_id;
        }

        if ($lr->freight_payment_term === PartloadFreightPaymentTerm::ToBeBilled
            && filled($lr->billing_customer_party_id)
        ) {
            return (string) $lr->billing_customer_party_id;
        }

        return null;
    }

    /**
     * @param  numeric-string  $taxableTotal
     * @return array{gst_amount: numeric-string, invoice_total: numeric-string, snapshot: array<string, mixed>}
     */
    private function calculateGst(Company $company, Branch $branch, Party $billingCustomer, string $taxableTotal): array
    {
        $effective = $this->gstResolver->resolve($company, $branch);
        $snapshot = is_array($effective->gstRateSnapshot) ? $effective->gstRateSnapshot : [];
        $ratePercent = '0.0000';

        if (filled($snapshot['rate_percent'] ?? null)) {
            $ratePercent = number_format((float) $snapshot['rate_percent'], 4, '.', '');
        } elseif (filled($effective->gstRateId)) {
            $rate = GstRate::query()->find($effective->gstRateId);
            if ($rate !== null) {
                $snapshot = $rate->toSnapshot();
                $ratePercent = number_format((float) $rate->rate_percent, 4, '.', '');
            }
        }

        $registration = $effective->registrationType;
        $taxLiability = $effective->taxLiability ?? TaxLiability::Fcm;

        $taxableForGst = $registration !== GstRegistrationType::Unregistered
            && $registration !== GstRegistrationType::Composition
            && bccomp($ratePercent, '0.0000', 4) === 1;

        $gstAmount = '0.00';
        $cgst = '0.00';
        $sgst = '0.00';
        $igst = '0.00';
        $supplyType = null;

        if ($taxableForGst) {
            $supplierState = strtoupper(trim((string) ($effective->supplierState ?? '')));
            $customerState = strtoupper(trim((string) ($billingCustomer->state ?? '')));
            $intraState = $supplierState !== '' && $customerState !== '' && $supplierState === $customerState;
            $supplyType = $intraState ? 'intra_state' : 'inter_state';

            if ($intraState) {
                $halfRate = bcdiv($ratePercent, '2', 4);
                $cgst = bcmul($taxableTotal, bcdiv($halfRate, '100', 6), 2);
                $sgst = bcmul($taxableTotal, bcdiv($halfRate, '100', 6), 2);
                $gstAmount = bcadd($cgst, $sgst, 2);
            } else {
                $igst = bcmul($taxableTotal, bcdiv($ratePercent, '100', 6), 2);
                $gstAmount = $igst;
            }
        }

        $invoiceTotal = $taxLiability === TaxLiability::Rcm
            ? $taxableTotal
            : bcadd($taxableTotal, $gstAmount, 2);

        return [
            'gst_amount' => $gstAmount,
            'invoice_total' => $invoiceTotal,
            'snapshot' => array_merge($snapshot, [
                'registration_type' => $registration?->value,
                'tax_liability' => $taxLiability->value,
                'supply_type' => $supplyType,
                'cgst_amount' => $cgst,
                'sgst_amount' => $sgst,
                'igst_amount' => $igst,
            ]),
        ];
    }

    private function assertEntitled(Company $company, bool $mutate): void
    {
        $resolver = $this->capabilities ?? app(CompanyCapabilityResolver::class);
        $allowed = $mutate
            ? $resolver->allowsMutate($company, CompanyCapabilities::PARTLOAD_INVOICES)
            : $resolver->allowsRead($company, CompanyCapabilities::PARTLOAD_INVOICES);

        if (! $allowed) {
            throw new PartloadAuthorizationException('Partload module is not entitled for this company.');
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            throw new PartloadAuthorizationException('You are not authorized for Partload invoicing.');
        }
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new PartloadDomainException('Branch does not belong to company.');
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
