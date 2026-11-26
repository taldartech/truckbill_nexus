<?php

namespace App\Domains\Subscription\Billing;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\PaymentIntent;
use App\Models\PlatformBillingProfile;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Automatic, immutable GST Subscription Tax Invoices and Credit Notes (separated from Customer GL).
 */
final class SubscriptionInvoiceService
{
    public function __construct(
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PlatformBillingProfileService $billingProfiles = new PlatformBillingProfileService,
    ) {}

    /**
     * Automatically issue an immutable GST tax invoice after a verified paid PaymentIntent.
     * Prevents duplicate issuance under concurrent callbacks/webhooks.
     *
     * @param  list<array<string, mixed>>|null  $lineItems
     */
    public function issueTaxInvoiceFromPayment(PaymentIntent $intent, ?array $lineItems = null): SubscriptionInvoice
    {
        if ($intent->verification_status !== 'verified' || $intent->status !== 'paid') {
            throw new SubscriptionDomainException('Tax invoices require a verified paid payment intent.');
        }

        $existing = SubscriptionInvoice::query()
            ->where('payment_intent_id', $intent->id)
            ->where('document_type', 'tax_invoice')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->tx->run(function () use ($intent, $lineItems): SubscriptionInvoice {
                $race = SubscriptionInvoice::query()
                    ->where('payment_intent_id', $intent->id)
                    ->where('document_type', 'tax_invoice')
                    ->lockForUpdate()
                    ->first();
                if ($race !== null) {
                    return $race;
                }

                $company = Company::query()->whereKey($intent->company_id)->firstOrFail();
                $now = CarbonImmutable::now('Asia/Kolkata');
                $fy = $this->financialYear($now);

                /** @var PlatformBillingProfile|null $profile */
                $profile = PlatformBillingProfile::query()
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                $meta = is_array($intent->meta) ? $intent->meta : [];
                $quote = is_array($meta['quote'] ?? null) ? $meta['quote'] : [];

                $resolvedLines = $lineItems ?? (is_array($quote['line_items'] ?? null) ? $quote['line_items'] : null);
                if ($resolvedLines === null || $resolvedLines === []) {
                    $resolvedLines = [[
                        'description' => $this->defaultDescriptionForPurpose($intent->purpose),
                        'quantity' => 1,
                        'amount' => (string) ($quote['taxable_amount'] ?? $intent->amount),
                        'currency' => $intent->currency,
                    ]];
                }

                $taxableAmount = DecimalMath::of((string) ($quote['taxable_amount'] ?? $intent->amount), 2);
                $subtotalAmount = DecimalMath::of((string) ($quote['subtotal_amount'] ?? $taxableAmount), 2);
                $discountAmount = DecimalMath::of((string) ($quote['discount_amount'] ?? '0.00'), 2);

                $tax = is_array($quote['tax'] ?? null)
                    ? $quote['tax']
                    : $this->billingProfiles->computeTaxForCompany($company, $taxableAmount, $profile);

                $cgst = DecimalMath::of((string) ($tax['cgst_amount'] ?? '0.00'), 2);
                $sgst = DecimalMath::of((string) ($tax['sgst_amount'] ?? '0.00'), 2);
                $igst = DecimalMath::of((string) ($tax['igst_amount'] ?? '0.00'), 2);
                $totalTax = DecimalMath::of((string) ($tax['total_tax_amount'] ?? DecimalMath::add($cgst, DecimalMath::add($sgst, $igst, 2), 2)), 2);
                $grandTotal = DecimalMath::of((string) $intent->amount, 2);

                // If legacy intent amount already included tax vs pre-tax, preserve authoritative payable.
                if (DecimalMath::compare(DecimalMath::add($taxableAmount, $totalTax, 2), $grandTotal, 2) !== 0 && ! isset($quote['tax'])) {
                    $taxableAmount = $grandTotal;
                    $cgst = '0.00';
                    $sgst = '0.00';
                    $igst = '0.00';
                    $totalTax = '0.00';
                }

                $invoiceNumber = $this->allocateSequentialNumber($profile, $fy, isCreditNote: false);

                $subscription = $intent->company_subscription_id !== null
                    ? CompanySubscription::query()->whereKey($intent->company_subscription_id)->first()
                    : null;

                $periodStart = filled($quote['service_period_start'] ?? null)
                    ? CarbonImmutable::parse((string) $quote['service_period_start'])->toDateString()
                    : $now->toDateString();
                $periodEnd = filled($quote['service_period_end'] ?? null)
                    ? CarbonImmutable::parse((string) $quote['service_period_end'])->toDateString()
                    : ($subscription?->common_renewal_date?->toDateString() ?? $now->addYear()->subDay()->toDateString());

                $supplierSnapshot = $profile?->toImmutableSnapshot();
                $customerSnapshot = is_array($quote['customer_snapshot'] ?? null)
                    ? $quote['customer_snapshot']
                    : $this->billingProfiles->customerSnapshot($company);

                $pricingSnapshot = [
                    'tenure' => $quote['tenure'] ?? $subscription?->tenure,
                    'purchase_type' => $quote['purchase_type'] ?? $intent->purpose,
                    'subtotal_amount' => $subtotalAmount,
                    'discount_amount' => $discountAmount,
                    'taxable_amount' => $taxableAmount,
                    'gst_rate_percent' => (string) ($tax['gst_rate_percent'] ?? '0.00'),
                    'cgst_amount' => $cgst,
                    'sgst_amount' => $sgst,
                    'igst_amount' => $igst,
                    'total_tax_amount' => $totalTax,
                    'grand_total' => $grandTotal,
                    'breakdown' => $quote['breakdown'] ?? $subscription?->pricing_breakdown,
                    'included_capacity' => $quote['included_capacity'] ?? null,
                ];

                $previous = TenantContextHolder::get();
                TenantContextHolder::set(new TenantContext(
                    companyId: $company->id,
                    branchId: null,
                    ownerId: (string) $company->owner_user_id,
                    isInternal: false,
                    isOwnerScope: true,
                ));

                try {
                    $invoice = SubscriptionInvoice::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'payment_intent_id' => $intent->id,
                        'company_subscription_id' => $intent->company_subscription_id,
                        'status' => 'issued',
                        'document_type' => 'tax_invoice',
                        'proposed_number' => $invoiceNumber,
                        'approved_number' => $invoiceNumber,
                        'invoice_number' => $invoiceNumber,
                        'financial_year' => $fy,
                        'service_period_start' => $periodStart,
                        'service_period_end' => $periodEnd,
                        'purchase_type' => (string) ($quote['purchase_type'] ?? $intent->purpose),
                        'sac_code' => (string) ($tax['sac_code'] ?? ($profile !== null ? $profile->sac_code : '998314')),
                        'subtotal_amount' => $subtotalAmount,
                        'discount_amount' => $discountAmount,
                        'taxable_amount' => $taxableAmount,
                        'gst_rate_percent' => (string) ($tax['gst_rate_percent'] ?? '0.00'),
                        'cgst_amount' => $cgst,
                        'sgst_amount' => $sgst,
                        'igst_amount' => $igst,
                        'total_tax_amount' => $totalTax,
                        'place_of_supply_state' => $tax['place_of_supply_state'] ?? null,
                        'place_of_supply_code' => $tax['place_of_supply_code'] ?? null,
                        'is_inter_state' => (bool) ($tax['is_inter_state'] ?? false),
                        'amount' => $grandTotal,
                        'currency' => $intent->currency,
                        'line_items' => array_values($resolvedLines),
                        'supplier_snapshot' => $supplierSnapshot,
                        'customer_snapshot' => $customerSnapshot,
                        'pricing_snapshot' => $pricingSnapshot,
                        'payment_reference' => $intent->gateway_payment_id ?? $intent->gateway_order_id ?? $intent->id,
                        'approved_at' => $now,
                        'issued_at' => $now,
                    ]);

                    $this->audit->write(
                        action: AuditAction::Created,
                        subjectType: 'subscription_invoice',
                        subjectId: $invoice->id,
                        remark: 'Automatically issue immutable subscription tax invoice '.$invoiceNumber,
                        before: null,
                        after: $invoice->toArray(),
                    );

                    $owner = User::query()->whereKey($company->owner_user_id)->first();
                    if ($owner !== null) {
                        app(OperationalAccountingPoster::class)
                            ->postSubscriptionExpenseForCustomer($invoice, $company, $owner);
                    }

                    return $invoice;
                } finally {
                    if ($previous === null) {
                        TenantContextHolder::clear();
                    } else {
                        TenantContextHolder::set($previous);
                    }
                }
            });
        } catch (UniqueConstraintViolationException) {
            return SubscriptionInvoice::query()
                ->where('payment_intent_id', $intent->id)
                ->where('document_type', 'tax_invoice')
                ->firstOrFail();
        }
    }

    /**
     * Legacy compatibility entry point invoked after verified payment.
     *
     * @param  list<array<string, mixed>>|null  $lineItems
     */
    public function ensureDraftQueuedAfterPayment(PaymentIntent $intent, ?array $lineItems = null): SubscriptionInvoice
    {
        return $this->issueTaxInvoiceFromPayment($intent, $lineItems);
    }

    /**
     * @param  list<array<string, mixed>>|null  $lineItems
     */
    public function createDraftFromPayment(PaymentIntent $intent, ?array $lineItems = null): SubscriptionInvoice
    {
        return $this->issueTaxInvoiceFromPayment($intent, $lineItems);
    }

    /**
     * Issue an immutable Credit Note against an issued subscription tax invoice for refunds/adjustments.
     */
    public function issueCreditNote(
        User $actor,
        SubscriptionInvoice $invoice,
        string $reason,
        ?string $creditGrossAmount = null,
    ): SubscriptionInvoice {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new SubscriptionDomainException('Credit note reason is required.');
        }

        if ($invoice->document_type !== 'tax_invoice') {
            throw new SubscriptionDomainException('Credit notes can only be issued against a tax invoice.');
        }

        return $this->tx->run(function () use ($actor, $invoice, $trimmedReason, $creditGrossAmount): SubscriptionInvoice {
            /** @var SubscriptionInvoice $locked */
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $existingCredited = SubscriptionInvoice::query()
                ->where('original_invoice_id', $locked->id)
                ->where('document_type', 'credit_note')
                ->whereNull('deleted_at')
                ->sum('amount');

            $remaining = DecimalMath::sub((string) $locked->amount, DecimalMath::of((string) $existingCredited, 2), 2);
            if (DecimalMath::compare($remaining, '0', 2) <= 0) {
                throw new SubscriptionDomainException('This invoice has already been fully credited.');
            }

            $gross = $creditGrossAmount !== null && trim($creditGrossAmount) !== ''
                ? DecimalMath::of($creditGrossAmount, 2)
                : $remaining;

            if (DecimalMath::compare($gross, '0', 2) <= 0 || DecimalMath::compare($gross, $remaining, 2) > 0) {
                throw new SubscriptionDomainException('Credit note amount must be positive and cannot exceed remaining invoice amount ₹'.$remaining.'.');
            }

            $ratio = DecimalMath::compare((string) $locked->amount, '0', 2) > 0
                ? DecimalMath::div($gross, (string) $locked->amount, 8)
                : '1';

            $taxable = DecimalMath::round(DecimalMath::mul((string) $locked->taxable_amount, $ratio, 8), 2);
            $cgst = DecimalMath::round(DecimalMath::mul((string) $locked->cgst_amount, $ratio, 8), 2);
            $sgst = DecimalMath::round(DecimalMath::mul((string) $locked->sgst_amount, $ratio, 8), 2);
            $igst = DecimalMath::round(DecimalMath::mul((string) $locked->igst_amount, $ratio, 8), 2);
            $totalTax = DecimalMath::add($cgst, DecimalMath::add($sgst, $igst, 2), 2);

            if (DecimalMath::compare(DecimalMath::add($taxable, $totalTax, 2), $gross, 2) !== 0) {
                $taxable = DecimalMath::sub($gross, $totalTax, 2);
            }

            $now = CarbonImmutable::now('Asia/Kolkata');
            $fy = $this->financialYear($now);

            /** @var PlatformBillingProfile|null $profile */
            $profile = PlatformBillingProfile::query()
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            $cnNumber = $this->allocateSequentialNumber($profile, $fy, isCreditNote: true);
            $company = Company::query()->whereKey($locked->company_id)->firstOrFail();

            $previous = TenantContextHolder::get();
            TenantContextHolder::set(new TenantContext(
                companyId: $company->id,
                branchId: null,
                ownerId: (string) $company->owner_user_id,
                isInternal: true,
                isOwnerScope: false,
            ));

            try {
                $creditNote = SubscriptionInvoice::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $locked->company_id,
                    'payment_intent_id' => $locked->payment_intent_id,
                    'company_subscription_id' => $locked->company_subscription_id,
                    'status' => 'issued',
                    'document_type' => 'credit_note',
                    'original_invoice_id' => $locked->id,
                    'proposed_number' => $cnNumber,
                    'approved_number' => $cnNumber,
                    'invoice_number' => $cnNumber,
                    'financial_year' => $fy,
                    'service_period_start' => $locked->service_period_start,
                    'service_period_end' => $locked->service_period_end,
                    'purchase_type' => 'credit_note',
                    'sac_code' => $locked->sac_code,
                    'subtotal_amount' => $taxable,
                    'discount_amount' => '0.00',
                    'taxable_amount' => $taxable,
                    'gst_rate_percent' => $locked->gst_rate_percent,
                    'cgst_amount' => $cgst,
                    'sgst_amount' => $sgst,
                    'igst_amount' => $igst,
                    'total_tax_amount' => $totalTax,
                    'place_of_supply_state' => $locked->place_of_supply_state,
                    'place_of_supply_code' => $locked->place_of_supply_code,
                    'is_inter_state' => $locked->is_inter_state,
                    'amount' => $gross,
                    'currency' => $locked->currency,
                    'line_items' => [[
                        'description' => 'Credit Note against Invoice '.($locked->invoice_number ?? $locked->approved_number).' — '.$trimmedReason,
                        'quantity' => 1,
                        'amount' => $taxable,
                        'currency' => $locked->currency,
                    ]],
                    'supplier_snapshot' => $locked->supplier_snapshot,
                    'customer_snapshot' => $locked->customer_snapshot,
                    'pricing_snapshot' => [
                        'original_invoice_id' => $locked->id,
                        'original_invoice_number' => $locked->invoice_number ?? $locked->approved_number,
                        'credit_note_reason' => $trimmedReason,
                    ],
                    'payment_reference' => $locked->payment_reference,
                    'credit_note_reason' => $trimmedReason,
                    'approved_at' => $now,
                    'issued_at' => $now,
                    'approved_by' => $actor->id,
                ]);

                $this->audit->write(
                    action: AuditAction::Created,
                    subjectType: 'subscription_invoice',
                    subjectId: $creditNote->id,
                    remark: 'Issue subscription credit note '.$cnNumber.' against '.$locked->id.': '.$trimmedReason,
                    before: null,
                    after: $creditNote->toArray(),
                );

                return $creditNote;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::set($previous);
                }
            }
        });
    }

    public function approveNumber(User $actor, SubscriptionInvoice $invoice, ?string $finalNumber = null): SubscriptionInvoice
    {
        return $this->mutate($invoice, function (SubscriptionInvoice $locked) use ($actor, $finalNumber): void {
            if ($locked->status === 'issued' && filled($locked->invoice_number)) {
                return;
            }
            $number = $finalNumber !== null && trim($finalNumber) !== ''
                ? trim($finalNumber)
                : (string) ($locked->invoice_number ?? $locked->proposed_number);
            if ($number === '') {
                throw new SubscriptionDomainException('Invoice number is required.');
            }
            $locked->approved_number = $number;
            $locked->invoice_number = $number;
            $locked->status = 'issued';
            $locked->approved_at = $locked->approved_at ?? now();
            $locked->issued_at = $locked->issued_at ?? now();
            $locked->approved_by = $actor->id;
            $locked->save();
        }, 'Finalize invoice number');
    }

    public function markIssued(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        return $this->mutate($invoice, function (SubscriptionInvoice $locked): void {
            if (! in_array($locked->status, ['approved', 'issued'], true)) {
                throw new SubscriptionDomainException('Only approved invoices may be issued.');
            }
            $locked->status = 'issued';
            $locked->issued_at = $locked->issued_at ?? now();
            $locked->save();
        }, 'Mark invoice issued');
    }

    public function recordEmailAttempt(SubscriptionInvoice $invoice, bool $success, ?string $failureReason = null): SubscriptionInvoice
    {
        return $this->mutate($invoice, function (SubscriptionInvoice $locked) use ($success, $failureReason): void {
            if (! in_array($locked->status, ['approved', 'issued', 'emailed'], true)) {
                throw new SubscriptionDomainException('Invoice must be approved/issued before email.');
            }
            $locked->email_status = $success ? 'sent' : 'failed';
            $locked->email_failure_reason = $success ? null : $failureReason;
            $locked->emailed_at = now();
            if ($success) {
                $locked->status = 'emailed';
            }
            $locked->save();
        }, $success ? 'Invoice emailed' : 'Invoice email failed');
    }

    /**
     * @param  callable(SubscriptionInvoice): void  $callback
     */
    private function mutate(SubscriptionInvoice $invoice, callable $callback, string $remark): SubscriptionInvoice
    {
        return $this->tx->run(function () use ($invoice, $callback, $remark): SubscriptionInvoice {
            /** @var SubscriptionInvoice $locked */
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $company = Company::query()->whereKey($locked->company_id)->firstOrFail();
            $previous = TenantContextHolder::get();
            TenantContextHolder::set(new TenantContext(
                companyId: $company->id,
                branchId: null,
                ownerId: (string) $company->owner_user_id,
                isInternal: false,
                isOwnerScope: true,
            ));
            try {
                $before = $locked->toArray();
                $callback($locked);
                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: 'subscription_invoice',
                    subjectId: $locked->id,
                    remark: $remark,
                    before: $before,
                    after: $locked->fresh()?->toArray(),
                );

                return $locked->fresh() ?? $locked;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::set($previous);
                }
            }
        });
    }

    private function allocateSequentialNumber(?PlatformBillingProfile $profile, string $fy, bool $isCreditNote): string
    {
        if ($profile !== null) {
            if ($isCreditNote) {
                $seq = max(1, (int) $profile->credit_note_next_sequence);
                $prefix = $profile->credit_note_prefix ?: 'TBCN';
                $profile->credit_note_next_sequence = $seq + 1;
                $profile->save();
            } else {
                $seq = max(1, (int) $profile->invoice_next_sequence);
                $prefix = $profile->invoice_prefix ?: (string) config('subscription.billing.invoice_number_prefix', 'TB');
                $profile->invoice_next_sequence = $seq + 1;
                $profile->save();
            }

            $candidate = sprintf('%s/%s/%04d', $prefix, $fy, $seq);
            while (SubscriptionInvoice::query()->where('invoice_number', $candidate)->exists()) {
                $seq++;
                $candidate = sprintf('%s/%s/%04d', $prefix, $fy, $seq);
            }

            return $candidate;
        }

        $prefix = $isCreditNote ? 'TBCN' : (string) config('subscription.billing.invoice_number_prefix', 'TB');
        $seq = SubscriptionInvoice::query()
            ->where('document_type', $isCreditNote ? 'credit_note' : 'tax_invoice')
            ->where('financial_year', $fy)
            ->count() + 1;

        $candidate = sprintf('%s/%s/%04d', $prefix, $fy, $seq);
        while (SubscriptionInvoice::query()->where('invoice_number', $candidate)->exists()) {
            $seq++;
            $candidate = sprintf('%s/%s/%04d', $prefix, $fy, $seq);
        }

        return $candidate;
    }

    private function financialYear(CarbonImmutable $at): string
    {
        $startMonth = (int) config('subscription.billing.financial_year_start_month', 4);
        $year = (int) $at->year;
        if ((int) $at->month < $startMonth) {
            $year--;
        }

        return sprintf('%d-%d', $year, $year + 1);
    }

    private function defaultDescriptionForPurpose(string $purpose): string
    {
        return match ($purpose) {
            'renewal' => 'TruckBill SaaS Subscription Renewal',
            'upgrade' => 'TruckBill SaaS Subscription Upgrade (Prorated)',
            'addon_add' => 'TruckBill SaaS Add-on Purchase (Prorated)',
            'credit_topup_broker' => 'TruckBill Broker LR/Bilty Credits',
            'credit_topup_partload' => 'TruckBill Partload LR/Bilty Credits',
            default => 'TruckBill SaaS Subscription',
        };
    }
}
