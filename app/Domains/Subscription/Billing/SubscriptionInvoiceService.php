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
use App\Support\Files\PrivateFileService;
use App\Support\Files\StoredFile;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Subscription Invoice Approval, Immutable Snapshots, and Credit Notes (separated from Customer GL).
 */
final class SubscriptionInvoiceService
{
    public function __construct(
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PlatformBillingProfileService $billingProfiles = new PlatformBillingProfileService,
        private readonly PrivateFileService $files = new PrivateFileService,
    ) {}

    /**
     * Queue a Pending Approval subscription invoice record after verified payment.
     * Subscription entitlements activate independently; no invoice number or supplier snapshot is assigned yet.
     *
     * @param  list<array<string, mixed>>|null  $lineItems
     */
    public function createPendingApprovalFromPayment(PaymentIntent $intent, ?array $lineItems = null): SubscriptionInvoice
    {
        if ($intent->verification_status !== 'verified' || $intent->status !== 'paid') {
            throw new SubscriptionDomainException('Pending subscription invoices require a verified paid payment intent.');
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
                $paymentAt = $intent->updated_at !== null
                    ? CarbonImmutable::instance($intent->updated_at)->setTimezone('Asia/Kolkata')
                    : CarbonImmutable::now('Asia/Kolkata');
                $fy = $this->financialYear($paymentAt);

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

                $defaultProfile = $this->billingProfiles->current();
                $tax = is_array($quote['gst'] ?? null)
                    ? $quote['gst']
                    : $this->billingProfiles->computeTaxForCompany($company, $taxableAmount, $defaultProfile);

                $cgst = DecimalMath::of((string) ($tax['cgst_amount'] ?? '0.00'), 2);
                $sgst = DecimalMath::of((string) ($tax['sgst_amount'] ?? '0.00'), 2);
                $igst = DecimalMath::of((string) ($tax['igst_amount'] ?? '0.00'), 2);
                $totalTax = DecimalMath::of((string) ($tax['total_tax_amount'] ?? DecimalMath::add($cgst, DecimalMath::add($sgst, $igst, 2), 2)), 2);
                $grandTotal = DecimalMath::of((string) $intent->amount, 2);

                if (DecimalMath::compare(DecimalMath::add($taxableAmount, $totalTax, 2), $grandTotal, 2) !== 0 && ! isset($quote['gst'])) {
                    $taxableAmount = $grandTotal;
                    $cgst = '0.00';
                    $sgst = '0.00';
                    $igst = '0.00';
                    $totalTax = '0.00';
                }

                $subscription = $intent->company_subscription_id !== null
                    ? CompanySubscription::query()->whereKey($intent->company_subscription_id)->first()
                    : null;

                $periodStart = filled($quote['service_period_start'] ?? null)
                    ? CarbonImmutable::parse((string) $quote['service_period_start'])->toDateString()
                    : $paymentAt->toDateString();
                $periodEnd = filled($quote['service_period_end'] ?? null)
                    ? CarbonImmutable::parse((string) $quote['service_period_end'])->toDateString()
                    : ($subscription?->common_renewal_date?->toDateString() ?? $paymentAt->addYear()->subDay()->toDateString());

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
                    'payment_date' => $paymentAt->toDateString(),
                    'statutory_deadline' => $this->statutoryDeadlineFromPayment($paymentAt)->toDateString(),
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
                        'billing_profile_id' => null,
                        'supplier_registration_key' => null,
                        'status' => SubscriptionInvoice::STATUS_PENDING_APPROVAL,
                        'document_type' => 'tax_invoice',
                        'proposed_number' => null,
                        'approved_number' => null,
                        'invoice_number' => null,
                        'financial_year' => $fy,
                        'invoice_date' => null,
                        'service_period_start' => $periodStart,
                        'service_period_end' => $periodEnd,
                        'purchase_type' => (string) ($quote['purchase_type'] ?? $intent->purpose),
                        'sac_code' => (string) ($tax['sac_code'] ?? config('subscription.billing.default_sac_code', '998314')),
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
                        'supplier_snapshot' => null,
                        'customer_snapshot' => $customerSnapshot,
                        'pricing_snapshot' => $pricingSnapshot,
                        'payment_reference' => $intent->gateway_payment_id ?? $intent->gateway_order_id ?? $intent->id,
                        'payment_at' => $paymentAt,
                        'approved_at' => null,
                        'issued_at' => null,
                        'approved_by' => null,
                    ]);

                    $this->audit->write(
                        action: AuditAction::Created,
                        subjectType: 'subscription_invoice',
                        subjectId: $invoice->id,
                        remark: 'Queue pending-approval subscription invoice after verified payment',
                        before: null,
                        after: $invoice->toArray(),
                    );

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
     * Approve a pending subscription invoice with an explicit Billing Profile, manual Invoice Number, and Invoice Date.
     * Enforces concurrency safety, supplier registration + FY uniqueness, statutory issuance window,
     * GST calculation compatibility, and immutable approval snapshots.
     */
    public function approveInvoice(
        User $actor,
        SubscriptionInvoice $invoice,
        string $billingProfileId,
        string $invoiceNumber,
        string $invoiceDate,
    ): SubscriptionInvoice {
        if ($invoice->document_type !== 'tax_invoice') {
            throw new SubscriptionDomainException('Only tax invoices require approval.');
        }

        $cleanNumber = trim($invoiceNumber);
        if ($cleanNumber === '' || strlen($cleanNumber) > 64) {
            throw new SubscriptionDomainException('A valid Invoice Number (1–64 characters) is required.');
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9\/_-]{0,63}$/', $cleanNumber)) {
            throw new SubscriptionDomainException('Invoice Number may only contain letters, numbers, "/", "-", or "_".');
        }

        try {
            $parsedDate = CarbonImmutable::createFromFormat('Y-m-d', trim($invoiceDate), 'Asia/Kolkata')?->startOfDay();
        } catch (\Throwable) {
            $parsedDate = null;
        }
        if ($parsedDate === null) {
            throw new SubscriptionDomainException('Invoice Date must be a valid YYYY-MM-DD date.');
        }

        try {
            return $this->tx->run(function () use ($actor, $invoice, $billingProfileId, $cleanNumber, $parsedDate): SubscriptionInvoice {
                /** @var SubscriptionInvoice $locked */
                $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                if ($locked->isApprovedForCustomer()) {
                    throw new SubscriptionDomainException('This invoice has already been approved and cannot be approved again.');
                }

                if ($locked->payment_intent_id !== null) {
                    $intent = PaymentIntent::query()->whereKey($locked->payment_intent_id)->first();
                    if ($intent === null || $intent->status !== 'paid' || $intent->verification_status !== 'verified') {
                        throw new SubscriptionDomainException('Associated payment must be verified and paid before approving an invoice.');
                    }
                }

                /** @var PlatformBillingProfile|null $profile */
                $profile = PlatformBillingProfile::query()
                    ->whereKey($billingProfileId)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if ($profile === null) {
                    throw new SubscriptionDomainException('Selected Billing Profile does not exist.');
                }
                if (! $profile->is_active) {
                    throw new SubscriptionDomainException('Selected Billing Profile is inactive and cannot be used for new invoice approvals.');
                }

                $paymentAt = $this->paymentTimestamp($locked);
                $paymentDate = CarbonImmutable::parse($paymentAt->setTimezone('Asia/Kolkata')->toDateString(), 'Asia/Kolkata')->startOfDay();
                $todayIst = CarbonImmutable::now('Asia/Kolkata')->startOfDay();
                $deadline = $this->statutoryDeadlineFromPayment($paymentDate);

                if ($parsedDate->lessThan($paymentDate)) {
                    throw new SubscriptionDomainException(
                        'Invoice Date ('.$parsedDate->toDateString().') cannot precede the payment date ('.$paymentDate->toDateString().').'
                    );
                }
                if ($parsedDate->greaterThan($todayIst)) {
                    throw new SubscriptionDomainException(
                        'Invoice Date cannot be in the future beyond today ('.$todayIst->toDateString().').'
                    );
                }
                if ($parsedDate->greaterThan($deadline)) {
                    $windowDays = $this->statutoryWindowDays();
                    throw new SubscriptionDomainException(
                        'Statutory GST issuance deadline exceeded: Invoice Date cannot be more than '.$windowDays.' days after payment ('.$deadline->toDateString().'). Use controlled statutory compliance handling instead of backdating or late issuance.'
                    );
                }

                $company = Company::query()->whereKey($locked->company_id)->firstOrFail();
                $compatibility = $this->checkBillingProfileCompatibility($locked, $profile, $company);
                if (! $compatibility['compatible']) {
                    Log::warning('subscription.invoice_approval_gst_conflict', [
                        'invoice_id' => $locked->id,
                        'billing_profile_id' => $profile->id,
                        'reason' => $compatibility['message'],
                    ]);

                    throw new SubscriptionDomainException((string) $compatibility['message']);
                }

                $financialYear = $this->financialYear($parsedDate);
                $registrationKey = $profile->supplierRegistrationKey();

                $duplicateExists = SubscriptionInvoice::query()
                    ->where('supplier_registration_key', $registrationKey)
                    ->where('financial_year', $financialYear)
                    ->whereRaw('LOWER(invoice_number) = ?', [mb_strtolower($cleanNumber)])
                    ->where('id', '!=', $locked->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->exists();

                if ($duplicateExists) {
                    throw new SubscriptionDomainException(
                        'Invoice number "'.$cleanNumber.'" is already issued under this supplier registration for FY '.$financialYear.'.'
                    );
                }

                $now = CarbonImmutable::now('Asia/Kolkata');
                $supplierSnapshot = $this->buildImmutableSupplierSnapshot($profile, $locked);
                $customerSnapshot = is_array($locked->customer_snapshot) && $locked->customer_snapshot !== []
                    ? $locked->customer_snapshot
                    : $this->billingProfiles->customerSnapshot($company);

                $existingPricing = is_array($locked->pricing_snapshot) ? $locked->pricing_snapshot : [];
                $pricingSnapshot = array_merge($existingPricing, [
                    'billing_profile_id' => $profile->id,
                    'invoice_date' => $parsedDate->toDateString(),
                    'financial_year' => $financialYear,
                    'statutory_deadline' => $deadline->toDateString(),
                    'approval_actor_id' => $actor->id,
                    'approval_actor_name' => $actor->name,
                    'approval_actor_email' => $actor->email,
                    'approved_at' => $now->toIso8601String(),
                ]);

                $previous = TenantContextHolder::get();
                TenantContextHolder::set(new TenantContext(
                    companyId: $company->id,
                    branchId: null,
                    ownerId: (string) $company->owner_user_id,
                    isInternal: true,
                    isOwnerScope: false,
                ));

                try {
                    $before = $locked->toArray();

                    $locked->fill([
                        'billing_profile_id' => $profile->id,
                        'supplier_registration_key' => $registrationKey,
                        'status' => SubscriptionInvoice::STATUS_APPROVED,
                        'approved_number' => $cleanNumber,
                        'invoice_number' => $cleanNumber,
                        'financial_year' => $financialYear,
                        'invoice_date' => $parsedDate->toDateString(),
                        'supplier_snapshot' => $supplierSnapshot,
                        'customer_snapshot' => $customerSnapshot,
                        'pricing_snapshot' => $pricingSnapshot,
                        'approved_at' => $now,
                        'issued_at' => $parsedDate->setTimeFrom($now),
                        'approved_by' => $actor->id,
                    ]);
                    $locked->save();

                    $fresh = $locked->fresh(['company', 'billingProfile', 'approver']) ?? $locked;

                    $this->audit->write(
                        action: AuditAction::Updated,
                        subjectType: 'subscription_invoice',
                        subjectId: $locked->id,
                        remark: 'Approve subscription invoice '.$cleanNumber.' using Billing Profile '.$profile->legal_name,
                        before: $before,
                        after: $fresh->toArray(),
                    );

                    $owner = User::query()->whereKey($company->owner_user_id)->first();
                    if ($owner !== null) {
                        app(OperationalAccountingPoster::class)
                            ->postSubscriptionExpenseForCustomer($fresh, $company, $owner);
                    }

                    return $fresh;
                } finally {
                    if ($previous === null) {
                        TenantContextHolder::clear();
                    } else {
                        TenantContextHolder::set($previous);
                    }
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw new SubscriptionDomainException(
                'Invoice number "'.$cleanNumber.'" is already issued under this supplier registration for that financial year.'
            );
        }
    }

    /**
     * Evaluate whether a Billing Profile's GST configuration matches the verified payment's tax calculation.
     *
     * @return array{compatible: bool, message: string|null, expected: array<string, mixed>}
     */
    public function checkBillingProfileCompatibility(
        SubscriptionInvoice $invoice,
        PlatformBillingProfile $profile,
        ?Company $company = null,
    ): array {
        $resolvedCompany = $company ?? Company::query()->whereKey($invoice->company_id)->firstOrFail();
        $expected = $this->billingProfiles->computeTaxForCompany(
            $resolvedCompany,
            (string) $invoice->taxable_amount,
            $profile,
            (string) $invoice->gst_rate_percent,
        );

        $origTotalTax = DecimalMath::of((string) $invoice->total_tax_amount, 2);
        $origCgst = DecimalMath::of((string) $invoice->cgst_amount, 2);
        $origSgst = DecimalMath::of((string) $invoice->sgst_amount, 2);
        $origIgst = DecimalMath::of((string) $invoice->igst_amount, 2);
        $origRate = DecimalMath::of((string) $invoice->gst_rate_percent, 2);
        $origGrand = DecimalMath::of((string) $invoice->amount, 2);
        $origInter = (bool) $invoice->is_inter_state;

        $expRate = DecimalMath::of((string) $expected['gst_rate_percent'], 2);
        $expTotalTax = DecimalMath::of((string) $expected['total_tax_amount'], 2);
        $expCgst = DecimalMath::of((string) $expected['cgst_amount'], 2);
        $expSgst = DecimalMath::of((string) $expected['sgst_amount'], 2);
        $expIgst = DecimalMath::of((string) $expected['igst_amount'], 2);
        $expGrand = DecimalMath::of((string) $expected['grand_total'], 2);
        $expInter = (bool) $expected['is_inter_state'];

        $mismatches = [];

        if (DecimalMath::compare($origTotalTax, '0', 2) > 0 && ! filled($profile->gstin)) {
            $mismatches[] = 'original purchase collected GST (₹'.$origTotalTax.') but selected Billing Profile has no GSTIN';
        }
        if (DecimalMath::compare($expRate, $origRate, 2) !== 0) {
            $mismatches[] = 'GST rate differs (purchased '.$origRate.'%, selected profile '.$expRate.'%)';
        }
        if (DecimalMath::compare($expTotalTax, $origTotalTax, 2) !== 0
            || DecimalMath::compare($expCgst, $origCgst, 2) !== 0
            || DecimalMath::compare($expSgst, $origSgst, 2) !== 0
            || DecimalMath::compare($expIgst, $origIgst, 2) !== 0
            || $expInter !== $origInter
            || DecimalMath::compare($expGrand, $origGrand, 2) !== 0) {
            $origType = $origInter ? 'IGST ₹'.$origIgst : 'CGST ₹'.$origCgst.' + SGST ₹'.$origSgst;
            $expType = $expInter ? 'IGST ₹'.$expIgst : 'CGST ₹'.$expCgst.' + SGST ₹'.$expSgst;
            $mismatches[] = 'tax split/amount differs (purchased '.$origType.' / total ₹'.$origGrand.'; selected profile '.$expType.' / total ₹'.$expGrand.')';
        }

        if ($mismatches !== []) {
            return [
                'compatible' => false,
                'message' => 'GST configuration conflict: '.implode('; ', $mismatches).'. Verified paid amounts cannot be altered. Select a compatible Billing Profile or handle the discrepancy through a controlled financial correction.',
                'expected' => $expected,
            ];
        }

        return [
            'compatible' => true,
            'message' => null,
            'expected' => $expected,
        ];
    }

    /**
     * Issue an immutable Credit Note against an approved subscription tax invoice for controlled financial adjustments.
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

        if ($invoice->document_type !== 'tax_invoice' || ! $invoice->isApprovedForCustomer()) {
            throw new SubscriptionDomainException('Credit notes can only be issued against an approved tax invoice.');
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
            $registrationKey = $locked->supplier_registration_key
                ?? ($locked->billing_profile_id ? 'profile:'.$locked->billing_profile_id : 'company:'.$locked->company_id);

            $cnNumber = $this->allocateCreditNoteNumber($registrationKey, $fy);
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
                    'billing_profile_id' => $locked->billing_profile_id,
                    'supplier_registration_key' => $registrationKey,
                    'status' => SubscriptionInvoice::STATUS_APPROVED,
                    'document_type' => 'credit_note',
                    'original_invoice_id' => $locked->id,
                    'proposed_number' => $cnNumber,
                    'approved_number' => $cnNumber,
                    'invoice_number' => $cnNumber,
                    'financial_year' => $fy,
                    'invoice_date' => $now->toDateString(),
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
                        'approval_actor_id' => $actor->id,
                        'approval_actor_name' => $actor->name,
                        'approved_at' => $now->toIso8601String(),
                    ],
                    'payment_reference' => $locked->payment_reference,
                    'payment_at' => $locked->payment_at,
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

    public function recordEmailAttempt(SubscriptionInvoice $invoice, bool $success, ?string $failureReason = null): SubscriptionInvoice
    {
        return $this->tx->run(function () use ($invoice, $success, $failureReason): SubscriptionInvoice {
            /** @var SubscriptionInvoice $locked */
            $locked = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isApprovedForCustomer()) {
                throw new SubscriptionDomainException('Invoice must be approved before email dispatch.');
            }

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
                $locked->email_status = $success ? 'sent' : 'failed';
                $locked->email_failure_reason = $success ? null : $failureReason;
                $locked->emailed_at = now();
                if ($success) {
                    $locked->status = SubscriptionInvoice::STATUS_EMAILED;
                }
                $locked->save();

                $fresh = $locked->fresh() ?? $locked;
                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: 'subscription_invoice',
                    subjectId: $locked->id,
                    remark: $success ? 'Invoice emailed' : 'Invoice email failed',
                    before: $before,
                    after: $fresh->toArray(),
                );

                return $fresh;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::set($previous);
                }
            }
        });
    }

    public function paymentTimestamp(SubscriptionInvoice $invoice): CarbonImmutable
    {
        if ($invoice->payment_at !== null) {
            return CarbonImmutable::instance($invoice->payment_at)->setTimezone('Asia/Kolkata');
        }
        if ($invoice->paymentIntent !== null && $invoice->paymentIntent->updated_at !== null) {
            return CarbonImmutable::instance($invoice->paymentIntent->updated_at)->setTimezone('Asia/Kolkata');
        }

        return CarbonImmutable::instance($invoice->created_at ?? now())->setTimezone('Asia/Kolkata');
    }

    public function statutoryDeadlineFromPayment(CarbonImmutable $paymentAt): CarbonImmutable
    {
        return $paymentAt->setTimezone('Asia/Kolkata')->startOfDay()->addDays($this->statutoryWindowDays());
    }

    public function statutoryWindowDays(): int
    {
        return max(1, (int) config('subscription.billing.statutory_issuance_window_days', 30));
    }

    public function financialYear(CarbonImmutable $at): string
    {
        $startMonth = (int) config('subscription.billing.financial_year_start_month', 4);
        $year = (int) $at->year;
        if ((int) $at->month < $startMonth) {
            $year--;
        }

        return sprintf('%d-%d', $year, $year + 1);
    }

    /**
     * Snapshot supplier attributes including an immutable logo copy on private disk so later
     * Billing Profile updates or logo replacements never mutate previously approved invoices.
     *
     * @return array<string, mixed>
     */
    private function buildImmutableSupplierSnapshot(PlatformBillingProfile $profile, SubscriptionInvoice $invoice): array
    {
        $snapshot = $profile->toImmutableSnapshot();

        if ($profile->logo_stored_file_id !== null) {
            $file = StoredFile::query()->whereKey($profile->logo_stored_file_id)->first();
            if ($file !== null) {
                $bytes = $this->files->readPlatformFileBytes($file);
                if ($bytes !== null) {
                    $disk = (string) config('files.disk', 'private_files');
                    $ext = $file->extension ?: 'png';
                    $snapshotPath = sprintf('%s/invoice-snapshots/%s.%s', StoredFile::PLATFORM_SCOPE, $invoice->id, $ext);
                    Storage::disk($disk)->put($snapshotPath, $bytes['contents']);
                    $snapshot['logo_snapshot_disk'] = $disk;
                    $snapshot['logo_snapshot_path'] = $snapshotPath;
                    $snapshot['logo_snapshot_mime'] = $bytes['mime'];
                }
            }
        }

        return $snapshot;
    }

    private function allocateCreditNoteNumber(string $registrationKey, string $fy): string
    {
        $seq = SubscriptionInvoice::query()
            ->where('supplier_registration_key', $registrationKey)
            ->where('document_type', 'credit_note')
            ->where('financial_year', $fy)
            ->count() + 1;

        $candidate = sprintf('TBCN/%s/%04d', $fy, $seq);
        while (SubscriptionInvoice::query()
            ->where('supplier_registration_key', $registrationKey)
            ->where('financial_year', $fy)
            ->where('invoice_number', $candidate)
            ->exists()) {
            $seq++;
            $candidate = sprintf('TBCN/%s/%04d', $fy, $seq);
        }

        return $candidate;
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
