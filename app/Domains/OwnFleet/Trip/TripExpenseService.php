<?php

namespace App\Domains\OwnFleet\Trip;

use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
use App\Models\PaymentMode;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OwnFleet trip expenses / diesel / profit summary (Rules §9).
 */
final class TripExpenseService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function add(Trip $trip, User $actor, array $payload): TripExpense
    {
        $this->assertCanEdit($trip, $actor);
        $company = Company::query()->findOrFail($trip->company_id);
        $branch = Branch::query()->findOrFail($trip->branch_id);
        $normalized = $this->normalizePayload($company, $branch, $payload);

        return $this->auditedTransaction->run(function () use ($trip, $actor, $company, $branch, $normalized): TripExpense {
            return $this->withTenant($company, $branch, function () use ($trip, $actor, $normalized): TripExpense {
                $locked = Trip::query()->whereKey($trip->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
                $this->assertTripAllowsExpenses($locked);

                $expense = TripExpense::query()->create([
                    ...$normalized,
                    'id' => (string) Str::uuid(),
                    'company_id' => $locked->company_id,
                    'trip_id' => $locked->id,
                    'created_by_user_id' => $actor->id,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    TripExpense::class,
                    $expense->id,
                    remark: 'Add trip expense',
                    before: null,
                    after: $expense->toArray(),
                );

                return $expense;
            });
        });
    }

    public function softDelete(Trip $trip, User $actor, TripExpense $expense): void
    {
        $this->assertCanEdit($trip, $actor);

        if ($expense->trip_id !== $trip->id || $expense->company_id !== $trip->company_id) {
            throw new TripDomainException('Expense does not belong to this Trip.');
        }

        $company = Company::query()->findOrFail($trip->company_id);
        $branch = Branch::query()->findOrFail($trip->branch_id);

        $this->auditedTransaction->run(function () use ($trip, $company, $branch, $expense): void {
            $this->withTenant($company, $branch, function () use ($trip, $expense): void {
                $locked = Trip::query()->whereKey($trip->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
                $this->assertTripAllowsExpenses($locked);

                $row = TripExpense::query()->whereKey($expense->id)->whereNull('deleted_at')->lockForUpdate()->first();
                if ($row === null) {
                    throw new TripDomainException('Expense not found.');
                }

                $before = $row->toArray();
                $row->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    TripExpense::class,
                    $row->id,
                    remark: 'Soft-delete trip expense',
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @return array{
     *     agreed_freight: string,
     *     additional_charges: string,
     *     diesel_cost: string,
     *     other_expenses: string,
     *     total_expenses: string,
     *     trip_profit: string,
     *     trip_mileage: string|null,
     *     trip_mileage_label: string
     * }
     */
    public function profitSummary(Trip $trip): array
    {
        $lr = $trip->lorryReceipt;
        $agreed = $this->money((string) ($lr?->freight_total ?? '0'));
        $charges = $this->money((string) ($lr?->additional_charges_total ?? '0'));

        $expenses = TripExpense::query()
            ->where('trip_id', $trip->id)
            ->whereNull('deleted_at')
            ->get();

        $diesel = '0.00';
        $other = '0.00';
        $dieselLitres = '0.000';

        foreach ($expenses as $expense) {
            if ($expense->is_diesel) {
                $diesel = bcadd($diesel, (string) $expense->amount, 2);
                if ($expense->diesel_litres !== null) {
                    $dieselLitres = bcadd($dieselLitres, (string) $expense->diesel_litres, 3);
                }
            } else {
                $other = bcadd($other, (string) $expense->amount, 2);
            }
        }

        $totalExpenses = bcadd($diesel, $other, 2);
        $revenue = bcadd($agreed, $charges, 2);
        $profit = bcsub($revenue, $totalExpenses, 2);

        $mileage = null;
        $mileageLabel = 'Not Available';
        if (
            $trip->status === TripStatus::Completed
            && $trip->trip_distance !== null
            && bccomp($dieselLitres, '0.000', 3) === 1
        ) {
            $mileage = bcdiv((string) $trip->trip_distance, $dieselLitres, 3);
            $mileageLabel = $mileage.' km/L';
        }

        return [
            'agreed_freight' => $agreed,
            'additional_charges' => $charges,
            'diesel_cost' => $diesel,
            'other_expenses' => $other,
            'total_expenses' => $totalExpenses,
            'trip_profit' => $profit,
            'trip_mileage' => $mileage,
            'trip_mileage_label' => $mileageLabel,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(Company $company, Branch $branch, array $payload): array
    {
        $isDiesel = (bool) ($payload['is_diesel'] ?? false);
        $categoryId = (string) ($payload['expense_category_id'] ?? '');
        $this->assertExpenseCategory($company, $categoryId);

        $date = (string) ($payload['expense_date'] ?? '');
        if ($date === '') {
            throw ValidationException::withMessages(['expense_date' => 'Expense date is required.']);
        }

        $paymentModeId = filled($payload['payment_mode_id'] ?? null) ? (string) $payload['payment_mode_id'] : null;
        if ($paymentModeId !== null && ! PaymentMode::query()->whereKey($paymentModeId)->exists()) {
            throw ValidationException::withMessages(['payment_mode_id' => 'Payment mode is invalid.']);
        }

        $paidFromId = filled($payload['paid_from_bank_cash_account_id'] ?? null)
            ? (string) $payload['paid_from_bank_cash_account_id']
            : null;
        if ($paidFromId !== null) {
            $account = BankCashAccount::query()
                ->where('company_id', $company->id)
                ->whereKey($paidFromId)
                ->whereNull('deleted_at')
                ->first();
            if ($account === null) {
                throw ValidationException::withMessages([
                    'paid_from_bank_cash_account_id' => 'Bank/Cash account is not available.',
                ]);
            }
            if ($account->branch_id !== null && $account->branch_id !== $branch->id) {
                throw ValidationException::withMessages([
                    'paid_from_bank_cash_account_id' => 'Bank/Cash account is not visible in this branch.',
                ]);
            }
        }

        $remarks = filled($payload['remarks'] ?? null) ? trim((string) $payload['remarks']) : null;

        if ($isDiesel) {
            $litres = $this->requirePositive($payload['diesel_litres'] ?? null, 'diesel_litres', 3);
            $rate = $this->requirePositive($payload['diesel_rate_per_litre'] ?? null, 'diesel_rate_per_litre', 4);
            $odometer = $this->requireNonNegative($payload['diesel_odometer'] ?? null, 'diesel_odometer', 1);
            $amount = $this->money(bcmul($litres, $rate, 6));

            $plannedQty = $this->optionalNonNegative($payload['planned_diesel_quantity'] ?? null, 'planned_diesel_quantity', 3);
            $plannedRate = $this->optionalNonNegative($payload['planned_diesel_rate'] ?? null, 'planned_diesel_rate', 4);
            $plannedAmount = null;
            if ($plannedQty !== null && $plannedRate !== null) {
                $plannedAmount = $this->money(bcmul($plannedQty, $plannedRate, 6));
            }
            $plannedEfficiency = $this->optionalNonNegative($payload['planned_efficiency'] ?? null, 'planned_efficiency', 3);

            return [
                'expense_category_id' => $categoryId,
                'expense_date' => $date,
                'amount' => $amount,
                'payment_mode_id' => $paymentModeId,
                'paid_from_bank_cash_account_id' => $paidFromId,
                'remarks' => $remarks,
                'is_diesel' => true,
                'diesel_litres' => $litres,
                'diesel_rate_per_litre' => $rate,
                'diesel_odometer' => $odometer,
                'planned_diesel_quantity' => $plannedQty,
                'planned_diesel_rate' => $plannedRate,
                'planned_diesel_amount' => $plannedAmount,
                'planned_efficiency' => $plannedEfficiency,
            ];
        }

        $amount = $this->requirePositive($payload['amount'] ?? null, 'amount', 2);

        return [
            'expense_category_id' => $categoryId,
            'expense_date' => $date,
            'amount' => $amount,
            'payment_mode_id' => $paymentModeId,
            'paid_from_bank_cash_account_id' => $paidFromId,
            'remarks' => $remarks,
            'is_diesel' => false,
            'diesel_litres' => null,
            'diesel_rate_per_litre' => null,
            'diesel_odometer' => null,
            'planned_diesel_quantity' => null,
            'planned_diesel_rate' => null,
            'planned_diesel_amount' => null,
            'planned_efficiency' => null,
        ];
    }

    private function assertExpenseCategory(Company $company, string $categoryId): void
    {
        $exists = OwnerCatalogueItem::query()
            ->whereKey($categoryId)
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', OwnerCatalogueType::ExpenseCategory->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'expense_category_id' => 'Expense category is not available.',
            ]);
        }
    }

    private function assertCanEdit(Trip $trip, User $actor): void
    {
        $company = Company::query()->findOrFail($trip->company_id);
        $branch = Branch::query()->findOrFail($trip->branch_id);

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_EDIT, $company, $branch)) {
            throw new TripAuthorizationException('Not authorized to manage trip expenses.');
        }
    }

    private function assertTripAllowsExpenses(Trip $trip): void
    {
        if ($trip->status === TripStatus::Cancelled) {
            throw new TripDomainException('Cannot change expenses on a cancelled Trip.');
        }
    }

    private function requirePositive(mixed $value, string $field, int $scale): string
    {
        $normalized = $this->requireDecimal($value, $field, $scale);
        if (bccomp($normalized, $this->zero($scale), $scale) !== 1) {
            throw ValidationException::withMessages([$field => 'Value must be greater than zero.']);
        }

        return $normalized;
    }

    private function requireNonNegative(mixed $value, string $field, int $scale): string
    {
        $normalized = $this->requireDecimal($value, $field, $scale);
        if (bccomp($normalized, $this->zero($scale), $scale) === -1) {
            throw ValidationException::withMessages([$field => 'Value cannot be negative.']);
        }

        return $normalized;
    }

    private function optionalNonNegative(mixed $value, string $field, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->requireNonNegative($value, $field, $scale);
    }

    private function requireDecimal(mixed $value, string $field, int $scale): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'A valid number is required.']);
        }

        return bcadd((string) $value, '0', $scale);
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }

    private function zero(int $scale): string
    {
        return bcadd('0', '0', $scale);
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
