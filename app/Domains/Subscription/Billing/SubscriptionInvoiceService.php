<?php

namespace App\Domains\Subscription\Billing;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Models\Company;
use App\Models\PaymentIntent;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Invoice state machine foundations. No accounting postings (ADR-010 Pending).
 * Activation is independent of invoice-number approval.
 */
final class SubscriptionInvoiceService
{
    public function __construct(
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  list<array<string, mixed>>|null  $lineItems
     */
    public function createDraftFromPayment(PaymentIntent $intent, ?array $lineItems = null): SubscriptionInvoice
    {
        if ($intent->verification_status !== 'verified' || $intent->status !== 'paid') {
            throw new SubscriptionDomainException('Draft invoices require a verified paid payment intent.');
        }

        return $this->tx->run(function () use ($intent, $lineItems): SubscriptionInvoice {
            $company = Company::query()->whereKey($intent->company_id)->firstOrFail();
            $fy = $this->financialYear(CarbonImmutable::now());
            $proposed = $this->proposeNumber($company, $fy);

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
                    'status' => 'draft',
                    'proposed_number' => $proposed,
                    'financial_year' => $fy,
                    'amount' => $intent->amount,
                    'currency' => $intent->currency,
                    'line_items' => $lineItems,
                ]);

                $this->audit->write(
                    action: AuditAction::Created,
                    subjectType: 'subscription_invoice',
                    subjectId: $invoice->id,
                    remark: 'Create draft invoice after verified payment',
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
    }

    public function submitForNumberApproval(SubscriptionInvoice $invoice, ?string $proposedNumber = null): SubscriptionInvoice
    {
        return $this->mutate($invoice, function (SubscriptionInvoice $locked) use ($proposedNumber): void {
            if ($locked->status !== 'draft') {
                throw new SubscriptionDomainException('Only draft invoices can be submitted for number approval.');
            }
            if ($proposedNumber !== null && trim($proposedNumber) !== '') {
                $locked->proposed_number = trim($proposedNumber);
            }
            $locked->status = 'awaiting_number_approval';
            $locked->save();
        }, 'Submit invoice for number approval');
    }

    public function approveNumber(User $actor, SubscriptionInvoice $invoice, ?string $finalNumber = null): SubscriptionInvoice
    {
        return $this->mutate($invoice, function (SubscriptionInvoice $locked) use ($actor, $finalNumber): void {
            if ($locked->status !== 'awaiting_number_approval') {
                throw new SubscriptionDomainException('Invoice is not awaiting number approval.');
            }
            $number = $finalNumber !== null && trim($finalNumber) !== ''
                ? trim($finalNumber)
                : (string) $locked->proposed_number;
            if ($number === '') {
                throw new SubscriptionDomainException('Invoice number is required for approval.');
            }
            $locked->approved_number = $number;
            $locked->status = 'approved';
            $locked->approved_at = now();
            $locked->approved_by = $actor->id;
            $locked->save();
        }, 'Approve invoice number');
    }

    public function markIssued(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        return $this->mutate($invoice, function (SubscriptionInvoice $locked): void {
            if (! in_array($locked->status, ['approved', 'issued'], true)) {
                throw new SubscriptionDomainException('Only approved invoices may be issued.');
            }
            $locked->status = 'issued';
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
            // Email failure must not reverse approval/payment state.
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

    private function financialYear(CarbonImmutable $at): string
    {
        $startMonth = (int) config('subscription.billing.financial_year_start_month', 4);
        $year = (int) $at->year;
        if ((int) $at->month < $startMonth) {
            $year--;
        }

        return sprintf('%d-%d', $year, $year + 1);
    }

    private function proposeNumber(Company $company, string $fy): string
    {
        $prefix = (string) config('subscription.billing.invoice_number_prefix', 'TB');
        $seq = SubscriptionInvoice::query()
            ->where('company_id', $company->id)
            ->where('financial_year', $fy)
            ->count() + 1;

        return sprintf('%s/%s/%04d', $prefix, $fy, $seq);
    }
}
