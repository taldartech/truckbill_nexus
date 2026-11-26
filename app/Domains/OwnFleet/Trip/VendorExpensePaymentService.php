<?php

namespace App\Domains\OwnFleet\Trip;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\TripExpense;
use App\Models\User;
use App\Models\VendorExpensePayment;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Presentation\FreightAmountPresentation;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OwnFleet credit vendor payables & payments (fuel pumps, workshops, general vendors).
 */
final class VendorExpensePaymentService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
        private readonly ?CompanyCapabilityResolver $capabilities = null,
        private readonly FreightAmountPresentation $freightPresentation = new FreightAmountPresentation,
    ) {}

    /**
     * @return array{
     *     unpaid_expenses: list<array<string, mixed>>,
     *     payments: list<array<string, mixed>>,
     *     vendor_balances: list<array{vendor_party_id: string, vendor_name: string, billed: string|null, paid: string|null, balance_due: string|null}>,
     *     total_balance_due: string|null
     * }
     */
    public function summaryForBranch(
        Company $company,
        Branch $branch,
        User $actor,
        ?string $vendorPartyId = null,
        bool $maySeeFreight = true,
    ): array {
        $this->assertEntitled($company, mutate: false);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_VIEW);

        $creditExpenses = TripExpense::query()
            ->where('company_id', $company->id)
            ->where('is_credit_payable', true)
            ->whereNull('deleted_at')
            ->whereHas('trip', fn ($q) => $q->where('branch_id', $branch->id)->whereNull('deleted_at'))
            ->with(['vendor', 'trip.lorryReceipt', 'category'])
            ->orderByDesc('expense_date')
            ->get();

        $payments = VendorExpensePayment::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['vendor', 'tripExpense'])
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->get();

        $paidByExpense = [];
        $paidByVendor = [];
        foreach ($payments as $p) {
            $amt = number_format((float) $p->amount, 2, '.', '');
            $vId = (string) $p->vendor_party_id;
            $paidByVendor[$vId] = bcadd($paidByVendor[$vId] ?? '0.00', $amt, 2);
            if ($p->trip_expense_id !== null) {
                $eId = (string) $p->trip_expense_id;
                $paidByExpense[$eId] = bcadd($paidByExpense[$eId] ?? '0.00', $amt, 2);
            }
        }

        $unpaidRows = [];
        $vendors = [];
        foreach ($creditExpenses as $exp) {
            $vId = (string) ($exp->vendor_party_id ?? '');
            if ($vId === '') {
                continue;
            }
            if (filled($vendorPartyId) && $vId !== $vendorPartyId) {
                continue;
            }

            $billed = number_format((float) $exp->amount, 2, '.', '');
            if (! isset($vendors[$vId])) {
                $vendors[$vId] = [
                    'vendor_party_id' => $vId,
                    'vendor_name' => $exp->vendor !== null ? $exp->vendor->name : 'Vendor',
                    'billed' => '0.00',
                    'paid' => $paidByVendor[$vId] ?? '0.00',
                    'balance_due' => '0.00',
                ];
            }
            $vendors[$vId]['billed'] = bcadd($vendors[$vId]['billed'], $billed, 2);

            $expPaid = $paidByExpense[$exp->id] ?? '0.00';
            $expRemaining = bcsub($billed, $expPaid, 2);
            if ($exp->settled_at === null && bccomp($expRemaining, '0.00', 2) === 1) {
                $unpaidRows[] = [
                    'id' => $exp->id,
                    'trip_id' => $exp->trip_id,
                    'trip_number' => $exp->trip?->lorryReceipt?->lr_number,
                    'vendor_party_id' => $vId,
                    'vendor_name' => $exp->vendor?->name,
                    'category_name' => $exp->category?->name,
                    'expense_date' => $exp->expense_date->toDateString(),
                    'amount' => $this->freightPresentation->presentAmount($billed, $maySeeFreight),
                    'paid_amount' => $this->freightPresentation->presentAmount($expPaid, $maySeeFreight),
                    'remaining_amount' => $this->freightPresentation->presentAmount($expRemaining, $maySeeFreight),
                    'is_diesel' => (bool) $exp->is_diesel,
                    'remarks' => $exp->remarks,
                ];
            }
        }

        $totalDue = '0.00';
        foreach ($vendors as &$vendor) {
            $balanceDue = bcsub($vendor['billed'], $vendor['paid'], 2);
            $vendor['balance_due'] = $balanceDue;
            if (bccomp($balanceDue, '0.00', 2) === 1) {
                $totalDue = bcadd($totalDue, $balanceDue, 2);
            }
            if (! $maySeeFreight) {
                $vendor['billed'] = null;
                $vendor['paid'] = null;
                $vendor['balance_due'] = null;
            }
        }
        unset($vendor);

        $filteredPayments = array_values(
            $payments
                ->filter(fn (VendorExpensePayment $p): bool => ! filled($vendorPartyId) || $p->vendor_party_id === $vendorPartyId)
                ->map(fn (VendorExpensePayment $p): array => [
                    'id' => $p->id,
                    'vendor_party_id' => $p->vendor_party_id,
                    'vendor_name' => $p->vendor?->name,
                    'trip_expense_id' => $p->trip_expense_id,
                    'payment_date' => $p->payment_date->toDateString(),
                    'amount' => $this->freightPresentation->presentAmount($p->amount, $maySeeFreight),
                    'payment_mode' => $p->payment_mode,
                    'bank_cash_account_id' => $p->bank_cash_account_id,
                    'reference' => $p->reference,
                    'remark' => $p->remark,
                ])
                ->all()
        );

        return [
            'unpaid_expenses' => $unpaidRows,
            'payments' => $filteredPayments,
            'vendor_balances' => array_values($vendors),
            'total_balance_due' => $this->freightPresentation->presentAmount($totalDue, $maySeeFreight),
        ];
    }

    /**
     * @param  array{
     *     vendor_party_id: string,
     *     trip_expense_id?: string|null,
     *     payment_date?: string|null,
     *     amount: numeric-string|float|int,
     *     payment_mode?: string|null,
     *     bank_cash_account_id: string,
     *     reference?: string|null,
     *     remark?: string|null,
     * }  $payload
     */
    public function recordPayment(Company $company, Branch $branch, User $actor, array $payload): VendorExpensePayment
    {
        $this->assertEntitled($company, mutate: true);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_EDIT);

        if ($branch->company_id !== $company->id) {
            throw new TripDomainException('Branch does not belong to company.');
        }

        $vendorId = (string) $payload['vendor_party_id'];
        $vendor = Party::query()
            ->whereKey($vendorId)
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->first();

        $hasVendorRole = $vendor !== null && PartyRole::query()
            ->where('party_id', $vendor->id)
            ->whereIn('role', [
                PartyRoleType::GeneralVendor->value,
                PartyRoleType::PumpVendor->value,
                PartyRoleType::Transporter->value,
            ])
            ->exists();

        if (! $hasVendorRole) {
            throw ValidationException::withMessages(['vendor_party_id' => 'Selected vendor is invalid.']);
        }

        $amount = number_format((float) $payload['amount'], 2, '.', '');
        if (bccomp($amount, '0.00', 2) !== 1) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        $paymentMode = (string) ($payload['payment_mode'] ?? 'cash');
        if (! in_array($paymentMode, ['cash', 'bank'], true)) {
            throw ValidationException::withMessages(['payment_mode' => 'Payment mode must be cash or bank.']);
        }

        $bankCashId = (string) $payload['bank_cash_account_id'];
        $account = BankCashAccount::query()
            ->where('company_id', $company->id)
            ->whereKey($bankCashId)
            ->whereNull('deleted_at')
            ->first();
        if ($account === null || ($account->branch_id !== null && $account->branch_id !== $branch->id)) {
            throw ValidationException::withMessages(['bank_cash_account_id' => 'Cash or Bank account is not available in this branch.']);
        }

        $tripExpenseId = filled($payload['trip_expense_id'] ?? null) ? (string) $payload['trip_expense_id'] : null;
        $paymentDate = filled($payload['payment_date'] ?? null)
            ? CarbonImmutable::parse((string) $payload['payment_date'])->toDateString()
            : now()->toDateString();

        return $this->auditedTransaction->run(function () use (
            $company,
            $branch,
            $actor,
            $vendorId,
            $tripExpenseId,
            $paymentDate,
            $amount,
            $paymentMode,
            $bankCashId,
            $payload,
        ): VendorExpensePayment {
            return $this->withTenant($company, $branch, function () use (
                $company,
                $branch,
                $actor,
                $vendorId,
                $tripExpenseId,
                $paymentDate,
                $amount,
                $paymentMode,
                $bankCashId,
                $payload,
            ): VendorExpensePayment {
                if ($tripExpenseId !== null) {
                    $expense = TripExpense::query()
                        ->where('company_id', $company->id)
                        ->whereKey($tripExpenseId)
                        ->where('vendor_party_id', $vendorId)
                        ->where('is_credit_payable', true)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first();

                    if ($expense === null) {
                        throw ValidationException::withMessages(['trip_expense_id' => 'Credit vendor expense not found.']);
                    }

                    $alreadyPaid = (string) VendorExpensePayment::query()
                        ->where('company_id', $company->id)
                        ->where('trip_expense_id', $expense->id)
                        ->whereNull('deleted_at')
                        ->sum('amount');

                    $remaining = bcsub(number_format((float) $expense->amount, 2, '.', ''), number_format((float) $alreadyPaid, 2, '.', ''), 2);
                    if (bccomp($amount, $remaining, 2) === 1) {
                        throw ValidationException::withMessages([
                            'amount' => "Payment exceeds remaining expense balance ({$remaining}).",
                        ]);
                    }

                    if (bccomp($amount, $remaining, 2) === 0) {
                        $expense->forceFill(['settled_at' => now()])->save();
                    }
                }

                $payment = VendorExpensePayment::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'vendor_party_id' => $vendorId,
                    'trip_expense_id' => $tripExpenseId,
                    'payment_date' => $paymentDate,
                    'amount' => $amount,
                    'payment_mode' => $paymentMode,
                    'bank_cash_account_id' => $bankCashId,
                    'reference' => filled($payload['reference'] ?? null) ? trim((string) $payload['reference']) : null,
                    'remark' => filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : null,
                    'created_by_user_id' => $actor->id,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    VendorExpensePayment::class,
                    $payment->id,
                    remark: 'Vendor expense payment recorded',
                    before: null,
                    after: $payment->toArray(),
                );

                $fresh = $payment->fresh(['vendor', 'tripExpense']) ?? $payment;
                $this->accounting->postVendorExpensePayment($fresh, $actor);

                return $fresh;
            });
        });
    }

    private function assertEntitled(Company $company, bool $mutate): void
    {
        $resolver = $this->capabilities ?? app(CompanyCapabilityResolver::class);
        $allowed = $mutate
            ? $resolver->allowsMutate($company, CompanyCapabilities::OWNFLEET_TRIP_EXPENSES)
            : $resolver->allowsRead($company, CompanyCapabilities::OWNFLEET_TRIP_EXPENSES);

        if (! $allowed) {
            throw new TripAuthorizationException('OwnFleet expenses module is not entitled.');
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            throw new TripAuthorizationException('Not authorized for vendor payment operations.');
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
