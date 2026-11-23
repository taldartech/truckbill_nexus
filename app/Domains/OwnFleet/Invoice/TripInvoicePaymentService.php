<?php

namespace App\Domains\OwnFleet\Invoice;

use App\Domains\OwnFleet\Invoice\Enums\TripInvoicePaymentStatus;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceStatus;
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
use App\Models\PaymentMode;
use App\Models\TripInvoice;
use App\Models\TripInvoicePayment;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OwnFleet invoice settlements (Rules §10 — payments immutable after save).
 */
final class TripInvoicePaymentService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
    ) {}

    /**
     * @param  array{
     *     payment_date?: string,
     *     payment_mode_id?: string|null,
     *     reference?: string|null,
     *     amount_received?: numeric-string|float|int,
     *     tds_amount?: numeric-string|float|int,
     *     shortage_damage?: numeric-string|float|int,
     *     discount?: numeric-string|float|int,
     *     discount_reason?: string|null,
     *     fill_net?: bool,
     * }  $payload
     */
    public function record(
        TripInvoice $invoice,
        User $actor,
        array $payload,
    ): TripInvoicePayment {
        $company = Company::query()->findOrFail($invoice->company_id);
        $branch = Branch::query()->findOrFail($invoice->branch_id);
        $this->assertCanCreate($actor, $company, $branch);
        $this->assertOwnFleetEntitled($company);

        return $this->auditedTransaction->run(function () use ($invoice, $company, $branch, $actor, $payload): TripInvoicePayment {
            return $this->withTenant($company, $branch, function () use ($invoice, $company, $actor, $payload): TripInvoicePayment {
                $locked = TripInvoice::query()->whereKey($invoice->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();

                if ($locked->status !== TripInvoiceStatus::Pending) {
                    throw new TripInvoiceConflictException('Payments are only allowed on active pending invoices.');
                }

                $paymentDate = filled($payload['payment_date'] ?? null)
                    ? CarbonImmutable::parse((string) $payload['payment_date'])->toDateString()
                    : CarbonImmutable::now('Asia/Kolkata')->toDateString();

                $amountReceived = $this->money($payload['amount_received'] ?? '0', 'amount_received');
                $tdsAmount = $this->money($payload['tds_amount'] ?? '0', 'tds_amount');
                $shortage = $this->money($payload['shortage_damage'] ?? '0', 'shortage_damage');
                $discount = $this->money($payload['discount'] ?? '0', 'discount');
                $discountReason = trim((string) ($payload['discount_reason'] ?? ''));

                if (bccomp($discount, '0', 2) === 1 && $discountReason === '') {
                    throw ValidationException::withMessages([
                        'discount_reason' => 'Discount reason is mandatory when discount is applied.',
                    ]);
                }

                if ((bool) ($payload['fill_net'] ?? false)) {
                    $remaining = $this->remainingBalance($locked);
                    $nonCash = bcadd($tdsAmount, bcadd($shortage, $discount, 2), 2);
                    $amountReceived = bcsub($remaining, $nonCash, 2);
                    if (bccomp($amountReceived, '0', 2) === -1) {
                        $amountReceived = '0.00';
                    }
                }

                foreach ([$amountReceived, $tdsAmount, $shortage, $discount] as $field => $val) {
                    if (bccomp($val, '0', 2) === -1) {
                        $map = ['amount_received', 'tds_amount', 'shortage_damage', 'discount'];
                        throw ValidationException::withMessages([
                            $map[$field] => 'Value cannot be negative.',
                        ]);
                    }
                }

                $settlement = bcadd($amountReceived, bcadd($tdsAmount, bcadd($shortage, $discount, 2), 2), 2);
                if (bccomp($settlement, '0', 2) !== 1) {
                    throw ValidationException::withMessages([
                        'amount_received' => 'Settlement must be greater than zero.',
                    ]);
                }

                $remainingBefore = $this->remainingBalance($locked);
                if (bccomp($settlement, $remainingBefore, 2) === 1) {
                    throw new TripInvoiceConflictException('Settlement cannot exceed the remaining balance.');
                }

                $existingTds = $this->sumTdsOnInvoice($locked);
                $newTdsTotal = bcadd($existingTds, $tdsAmount, 2);
                $tdsCap = (string) $locked->tds_basis_freight;
                if (bccomp($newTdsTotal, $tdsCap, 2) === 1) {
                    throw new TripInvoiceConflictException('Cumulative TDS cannot exceed base freight.');
                }

                $paymentModeId = filled($payload['payment_mode_id'] ?? null) ? (string) $payload['payment_mode_id'] : null;
                if ($paymentModeId !== null) {
                    $mode = PaymentMode::query()->whereKey($paymentModeId)->where('is_active', true)->first();
                    if ($mode === null) {
                        throw ValidationException::withMessages(['payment_mode_id' => 'Payment mode is invalid.']);
                    }
                }

                $payment = TripInvoicePayment::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'trip_invoice_id' => $locked->id,
                    'payment_date' => $paymentDate,
                    'payment_mode_id' => $paymentModeId,
                    'reference' => filled($payload['reference'] ?? null) ? trim((string) $payload['reference']) : null,
                    'amount_received' => $amountReceived,
                    'tds_amount' => $tdsAmount,
                    'shortage_damage' => $shortage,
                    'discount' => $discount,
                    'discount_reason' => $discountReason !== '' ? $discountReason : null,
                    'settlement_amount' => $settlement,
                    'created_by_user_id' => $actor->id,
                ]);

                $this->recomputeInvoiceSettlement($locked);

                $this->audit->write(
                    AuditAction::Created,
                    TripInvoicePayment::class,
                    $payment->id,
                    remark: 'Record OwnFleet trip invoice payment',
                    before: null,
                    after: $payment->toArray(),
                );

                return $payment->fresh('paymentMode') ?? $payment;
            });
        });
    }

    public function fillNetPreview(TripInvoice $invoice, string $tdsAmount, string $shortage, string $discount): string
    {
        $remaining = $this->remainingBalance($invoice);
        $nonCash = bcadd($tdsAmount, bcadd($shortage, $discount, 2), 2);
        $amountReceived = bcsub($remaining, $nonCash, 2);

        return bccomp($amountReceived, '0', 2) === -1 ? '0.00' : $amountReceived;
    }

    private function recomputeInvoiceSettlement(TripInvoice $invoice): void
    {
        $payments = TripInvoicePayment::query()
            ->where('trip_invoice_id', $invoice->id)
            ->lockForUpdate()
            ->get();

        $received = '0.00';
        $tds = '0.00';
        $deductions = '0.00';
        $settled = '0.00';

        foreach ($payments as $row) {
            $received = bcadd($received, (string) $row->amount_received, 2);
            $tds = bcadd($tds, (string) $row->tds_amount, 2);
            $deductions = bcadd($deductions, bcadd((string) $row->shortage_damage, (string) $row->discount, 2), 2);
            $settled = bcadd($settled, (string) $row->settlement_amount, 2);
        }

        $balance = bcsub((string) $invoice->invoice_total, $settled, 2);
        if (bccomp($balance, '0', 2) === -1) {
            $balance = '0.00';
        }

        $paymentStatus = TripInvoicePaymentStatus::Pending;
        if (bccomp($settled, '0', 2) === 1 && bccomp($balance, '0', 2) === 1) {
            $paymentStatus = TripInvoicePaymentStatus::Partial;
        } elseif (bccomp($balance, '0', 2) === 0) {
            $paymentStatus = TripInvoicePaymentStatus::Paid;
        }

        $before = $invoice->toArray();
        $invoice->forceFill([
            'amount_received_total' => $received,
            'tds_received_total' => $tds,
            'deductions_total' => $deductions,
            'settlement_total' => $settled,
            'balance_due' => $balance,
            'payment_status' => $paymentStatus,
            'charges_locked_at' => $invoice->charges_locked_at ?? CarbonImmutable::now('UTC'),
        ])->save();

        $this->audit->write(
            AuditAction::Updated,
            TripInvoice::class,
            $invoice->id,
            remark: 'Reconcile OwnFleet trip invoice settlement',
            before: $before,
            after: $invoice->fresh()?->toArray(),
        );
    }

    private function remainingBalance(TripInvoice $invoice): string
    {
        return bcsub((string) $invoice->invoice_total, (string) $invoice->settlement_total, 2);
    }

    private function sumTdsOnInvoice(TripInvoice $invoice): string
    {
        $total = '0.00';
        foreach (TripInvoicePayment::query()->where('trip_invoice_id', $invoice->id)->lockForUpdate()->get() as $row) {
            $total = bcadd($total, (string) $row->tds_amount, 2);
        }

        return $total;
    }

    private function money(mixed $value, string $field): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'A valid amount is required.']);
        }

        return bcadd((string) $value, '0', 2);
    }

    private function assertCanCreate(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PAYMENT_CREATE, $company, $branch)) {
            throw new TripInvoiceAuthorizationException('Not authorized to record invoice payments.');
        }
    }

    private function assertOwnFleetEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::OwnFleet)) {
            throw new TripInvoiceDomainException('OwnFleet module is not entitled for this company.');
        }
    }

    /**
     * @return T
     *
     * @template T
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
