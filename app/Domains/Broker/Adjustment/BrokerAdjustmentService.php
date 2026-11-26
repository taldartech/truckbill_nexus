<?php

namespace App\Domains\Broker\Adjustment;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\BrokerAdjustment;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Post-hire freight adjustments — detention, unloading, shortage, etc. (Rules §11).
 */
final class BrokerAdjustmentService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly LorryHireCalculationService $calculations = new LorryHireCalculationService,
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
    ) {}

    /**
     * @return Collection<int, BrokerAdjustment>
     */
    public function listForHire(Company $company, Branch $branch, LorryHire $hire): Collection
    {
        $this->ensureTenantMatches($company, $branch);
        $this->assertHireBelongs($hire, $company, $branch);

        return BrokerAdjustment::query()
            ->where('company_id', $company->id)
            ->where('lorry_hire_id', $hire->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{
     *     amount: string|float|int,
     *     reason?: string|null,
     *     occurred_at?: string|null
     * }  $payload
     */
    public function createDetention(LorryHire $hire, User $actor, array $payload): BrokerAdjustment
    {
        return $this->createAmountAdjustment($hire, $actor, BrokerAdjustmentType::Detention, $payload);
    }

    /**
     * @param  array{
     *     amount: string|float|int,
     *     reason?: string|null,
     *     occurred_at?: string|null
     * }  $payload
     */
    public function createDeviation(LorryHire $hire, User $actor, array $payload): BrokerAdjustment
    {
        return $this->createAmountAdjustment($hire, $actor, BrokerAdjustmentType::Deviation, $payload);
    }

    /**
     * @param  array{
     *     amount: string|float|int,
     *     reason?: string|null,
     *     occurred_at?: string|null
     * }  $payload
     */
    public function createMiscExpense(LorryHire $hire, User $actor, array $payload): BrokerAdjustment
    {
        return $this->createAmountAdjustment($hire, $actor, BrokerAdjustmentType::MiscExpense, $payload);
    }

    /**
     * @param  array{
     *     new_weight: string|float|int,
     *     reason?: string|null,
     *     occurred_at?: string|null
     * }  $payload
     */
    public function createUnloadingWeight(LorryHire $hire, User $actor, array $payload): BrokerAdjustment
    {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);

        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);
        $this->assertAdjustable($hire);

        $newWeight = $this->positiveDecimal($payload['new_weight'], 'new_weight', 3);
        $previousWeight = $this->calculations->effectiveLoadingWeightBeforeUnloading($hire);
        $reason = $this->optionalText($payload['reason'] ?? null);
        $occurredAt = $this->parseOccurredAt($company, $payload['occurred_at'] ?? null);

        return $this->auditedTransaction->run(function () use ($hire, $actor, $company, $branch, $previousWeight, $newWeight, $reason, $occurredAt): BrokerAdjustment {
            return $this->withTenant($company, $branch, function () use ($hire, $actor, $company, $branch, $previousWeight, $newWeight, $reason, $occurredAt): BrokerAdjustment {
                $adjustment = BrokerAdjustment::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'lorry_hire_id' => $hire->id,
                    'type' => BrokerAdjustmentType::UnloadingWeight,
                    'amount' => '0.00',
                    'previous_weight' => $previousWeight,
                    'new_weight' => $newWeight,
                    'weight' => $newWeight,
                    'reason' => $reason,
                    'occurred_at' => $occurredAt,
                    'created_by_user_id' => $actor->id,
                ]);

                $previousFreight = $this->money($hire->freight_total);
                $freightTotal = $this->calculations->recalculateFreightForUnloading($hire, $newWeight);
                $freightDelta = bcsub($freightTotal, $previousFreight, 2);
                $this->refreshHireFinancials($hire, $freightTotal);

                $this->audit->write(
                    AuditAction::Created,
                    BrokerAdjustment::class,
                    $adjustment->id,
                    remark: 'Unloading weight adjustment',
                    before: null,
                    after: $adjustment->toArray(),
                );

                $this->accounting->postBrokerAdjustment($adjustment, $hire->fresh() ?? $hire, $actor, $freightDelta);

                return $adjustment->fresh() ?? $adjustment;
            });
        });
    }

    /**
     * @param  array{
     *     quantity: string|float|int,
     *     rate: string|float|int,
     *     amount?: string|float|int|null,
     *     reason?: string|null,
     *     occurred_at?: string|null
     * }  $payload
     */
    public function createShortage(LorryHire $hire, User $actor, array $payload): BrokerAdjustment
    {
        return $this->createQuantityAdjustment($hire, $actor, BrokerAdjustmentType::Shortage, $payload);
    }

    /**
     * @param  array{
     *     quantity: string|float|int,
     *     rate: string|float|int,
     *     amount?: string|float|int|null,
     *     reason?: string|null,
     *     occurred_at?: string|null
     * }  $payload
     */
    public function createDamage(LorryHire $hire, User $actor, array $payload): BrokerAdjustment
    {
        return $this->createQuantityAdjustment($hire, $actor, BrokerAdjustmentType::Damage, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function store(LorryHire $hire, User $actor, array $payload): BrokerAdjustment
    {
        $type = BrokerAdjustmentType::tryFrom((string) ($payload['type'] ?? ''));
        if ($type === null) {
            throw ValidationException::withMessages(['type' => 'Invalid adjustment type.']);
        }

        $reason = $this->optionalText($payload['reason'] ?? null);
        $occurredAt = filled($payload['occurred_at'] ?? null) ? (string) $payload['occurred_at'] : null;

        return match ($type) {
            BrokerAdjustmentType::Detention => $this->createDetention($hire, $actor, [
                'amount' => $payload['amount'],
                'reason' => $reason,
                'occurred_at' => $occurredAt,
            ]),
            BrokerAdjustmentType::Deviation => $this->createDeviation($hire, $actor, [
                'amount' => $payload['amount'],
                'reason' => $reason,
                'occurred_at' => $occurredAt,
            ]),
            BrokerAdjustmentType::MiscExpense => $this->createMiscExpense($hire, $actor, [
                'amount' => $payload['amount'],
                'reason' => $reason,
                'occurred_at' => $occurredAt,
            ]),
            BrokerAdjustmentType::UnloadingWeight => $this->createUnloadingWeight($hire, $actor, [
                'new_weight' => $payload['new_weight'],
                'reason' => $reason,
                'occurred_at' => $occurredAt,
            ]),
            BrokerAdjustmentType::Shortage => $this->createShortage($hire, $actor, [
                'quantity' => $payload['quantity'],
                'rate' => $payload['rate'],
                'amount' => $payload['amount'] ?? null,
                'reason' => $reason,
                'occurred_at' => $occurredAt,
            ]),
            BrokerAdjustmentType::Damage => $this->createDamage($hire, $actor, [
                'quantity' => $payload['quantity'],
                'rate' => $payload['rate'],
                'amount' => $payload['amount'] ?? null,
                'reason' => $reason,
                'occurred_at' => $occurredAt,
            ]),
        };
    }

    /**
     * @param  array{
     *     amount: string|float|int,
     *     reason?: string|null,
     *     occurred_at?: string|null
     * }  $payload
     */
    private function createAmountAdjustment(
        LorryHire $hire,
        User $actor,
        BrokerAdjustmentType $type,
        array $payload,
    ): BrokerAdjustment {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);

        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);
        $this->assertAdjustable($hire);

        $amount = $this->positiveMoney($payload['amount'], 'amount');
        $reason = $this->optionalText($payload['reason'] ?? null);
        $occurredAt = $this->parseOccurredAt($company, $payload['occurred_at'] ?? null);

        return $this->auditedTransaction->run(function () use ($hire, $actor, $company, $branch, $type, $amount, $reason, $occurredAt): BrokerAdjustment {
            return $this->withTenant($company, $branch, function () use ($hire, $actor, $company, $branch, $type, $amount, $reason, $occurredAt): BrokerAdjustment {
                $adjustment = BrokerAdjustment::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'lorry_hire_id' => $hire->id,
                    'type' => $type,
                    'amount' => $amount,
                    'reason' => $reason,
                    'occurred_at' => $occurredAt,
                    'created_by_user_id' => $actor->id,
                ]);

                $this->refreshHireFinancials($hire, null);

                $this->audit->write(
                    AuditAction::Created,
                    BrokerAdjustment::class,
                    $adjustment->id,
                    remark: 'Broker adjustment '.$type->value,
                    before: null,
                    after: $adjustment->toArray(),
                );

                $this->accounting->postBrokerAdjustment($adjustment, $hire->fresh() ?? $hire, $actor);

                return $adjustment->fresh() ?? $adjustment;
            });
        });
    }

    /**
     * @param  array{
     *     quantity: string|float|int,
     *     rate: string|float|int,
     *     amount?: string|float|int|null,
     *     reason?: string|null,
     *     occurred_at?: string|null
     * }  $payload
     */
    private function createQuantityAdjustment(
        LorryHire $hire,
        User $actor,
        BrokerAdjustmentType $type,
        array $payload,
    ): BrokerAdjustment {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);

        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);
        $this->assertAdjustable($hire);

        $quantity = $this->positiveDecimal($payload['quantity'], 'quantity', 3);
        $rate = $this->positiveDecimal($payload['rate'], 'rate', 4);
        $amount = filled($payload['amount'] ?? null)
            ? $this->positiveMoney($payload['amount'], 'amount')
            : $this->money(bcmul($quantity, $rate, 6));
        $reason = $this->optionalText($payload['reason'] ?? null);
        $occurredAt = $this->parseOccurredAt($company, $payload['occurred_at'] ?? null);

        return $this->auditedTransaction->run(function () use ($hire, $actor, $company, $branch, $type, $quantity, $rate, $amount, $reason, $occurredAt): BrokerAdjustment {
            return $this->withTenant($company, $branch, function () use ($hire, $actor, $company, $branch, $type, $quantity, $rate, $amount, $reason, $occurredAt): BrokerAdjustment {
                $adjustment = BrokerAdjustment::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'lorry_hire_id' => $hire->id,
                    'type' => $type,
                    'amount' => $amount,
                    'quantity' => $quantity,
                    'rate' => $rate,
                    'reason' => $reason,
                    'occurred_at' => $occurredAt,
                    'created_by_user_id' => $actor->id,
                ]);

                $this->refreshHireFinancials($hire, null);

                $this->audit->write(
                    AuditAction::Created,
                    BrokerAdjustment::class,
                    $adjustment->id,
                    remark: 'Broker adjustment '.$type->value,
                    before: null,
                    after: $adjustment->toArray(),
                );

                $this->accounting->postBrokerAdjustment($adjustment, $hire->fresh() ?? $hire, $actor);

                return $adjustment->fresh() ?? $adjustment;
            });
        });
    }

    private function refreshHireFinancials(LorryHire $hire, ?string $freightTotal): void
    {
        $hire->refresh();
        $hire->loadMissing(['adjustments', 'vouchers', 'bookBalanceEntries']);

        if ($freightTotal !== null) {
            $hire->forceFill(['freight_total' => $freightTotal])->save();
            $hire->refresh();
        }

        $summary = $this->calculations->summarize($hire);
        $hire->forceFill([
            'outstanding_amount' => $summary['outstanding_amount'],
        ])->save();
    }

    private function assertAdjustable(LorryHire $hire): void
    {
        if ($hire->trashed() || $hire->status === LorryHireStatus::Cancelled) {
            throw new BrokerConflictException('Adjustments are not allowed on cancelled Lorry Hires.');
        }
    }

    private function assertHireBelongs(LorryHire $hire, Company $company, Branch $branch): void
    {
        if ($hire->company_id !== $company->id || $hire->branch_id !== $branch->id) {
            throw new BrokerDomainException('Lorry Hire does not belong to branch.');
        }
    }

    private function assertCanCreate(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_ADJUSTMENT_CREATE, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to create Broker adjustments.');
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

    private function optionalText(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return trim((string) $value);
    }

    private function parseOccurredAt(Company $company, mixed $value): ?CarbonImmutable
    {
        if (! filled($value)) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, $this->companyTimezone($company));
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = $company->timezone ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Kolkata';
    }

    /**
     * @return numeric-string
     */
    private function positiveMoney(mixed $value, string $field): string
    {
        $money = $this->money($value);
        if (bccomp($money, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                $field => 'Amount must be greater than zero.',
            ]);
        }

        return $money;
    }

    /**
     * @return numeric-string
     */
    private function positiveDecimal(mixed $value, string $field, int $scale): string
    {
        $decimal = $this->money($value, $scale);
        if (bccomp($decimal, '0', $scale) <= 0) {
            throw ValidationException::withMessages([
                $field => 'Value must be greater than zero.',
            ]);
        }

        return $decimal;
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value, int $scale = 2): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return number_format(0, $scale, '.', '');
        }

        return number_format((float) $value, $scale, '.', '');
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
