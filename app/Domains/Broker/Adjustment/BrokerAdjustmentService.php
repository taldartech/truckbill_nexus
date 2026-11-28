<?php

namespace App\Domains\Broker\Adjustment;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\LorryHire\LorryHireOperationalDelivery;
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

    public function activeForHire(LorryHire $hire, BrokerAdjustmentType $type): ?BrokerAdjustment
    {
        return BrokerAdjustment::query()
            ->where('company_id', $hire->company_id)
            ->where('lorry_hire_id', $hire->id)
            ->where('type', $type)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{
     *     detention: string,
     *     deviation: string,
     *     misc_expense: string,
     *     unloading_weight: string,
     *     shortage: string,
     *     damage: string
     * }
     */
    public function snapshotFixedAdjustments(LorryHire $hire): array
    {
        $money = fn (BrokerAdjustmentType $type): string => $this->presentAdjustmentMoney(
            $this->activeForHire($hire, $type)?->amount,
        );
        $unloading = $this->activeForHire($hire, BrokerAdjustmentType::UnloadingWeight);

        return [
            'detention' => $money(BrokerAdjustmentType::Detention),
            'deviation' => $money(BrokerAdjustmentType::Deviation),
            'misc_expense' => $money(BrokerAdjustmentType::MiscExpense),
            'unloading_weight' => $unloading !== null ? $this->presentAdjustmentDecimal($unloading->new_weight, 3) : '',
            'shortage' => $money(BrokerAdjustmentType::Shortage),
            'damage' => $money(BrokerAdjustmentType::Damage),
        ];
    }

    /**
     * @param  array{
     *     detention?: string|null,
     *     deviation?: string|null,
     *     misc_expense?: string|null,
     *     unloading_weight?: string|null,
     *     shortage?: string|null,
     *     damage?: string|null
     * }  $payload
     */
    public function syncFixedAdjustments(LorryHire $hire, User $actor, array $payload): void
    {
        if (! LorryHireOperationalDelivery::isDelivered($hire)) {
            if ($this->fixedAdjustmentsPayloadHasValues($payload)) {
                throw ValidationException::withMessages([
                    'fixed_adjustments' => 'Detention, deviation, unloading weight and other delivery adjustments are available after the Bilty is delivered.',
                ]);
            }

            return;
        }

        $this->syncMoneyAdjustment($hire, $actor, BrokerAdjustmentType::Detention, $payload['detention'] ?? null);
        $this->syncMoneyAdjustment($hire, $actor, BrokerAdjustmentType::Deviation, $payload['deviation'] ?? null);
        $this->syncMoneyAdjustment($hire, $actor, BrokerAdjustmentType::MiscExpense, $payload['misc_expense'] ?? null);
        $this->syncMoneyAdjustment($hire, $actor, BrokerAdjustmentType::Shortage, $payload['shortage'] ?? null);
        $this->syncMoneyAdjustment($hire, $actor, BrokerAdjustmentType::Damage, $payload['damage'] ?? null);
        $this->syncUnloadingWeight($hire, $actor, $payload['unloading_weight'] ?? null);
    }

    /**
     * @param  array{
     *     fixed_adjustments?: array<string, mixed>|null
     * }  $payload
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    private function fixedAdjustmentsPayloadHasValues(array $payload): bool
    {
        foreach (['detention', 'deviation', 'misc_expense', 'unloading_weight', 'shortage', 'damage'] as $key) {
            if ($this->normalizeFixedField($payload[$key] ?? null, $key === 'unloading_weight' ? 3 : 2) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fixedAdjustmentsDiffer(LorryHire $hire, array $payload): bool
    {
        $incoming = is_array($payload['fixed_adjustments'] ?? null)
            ? $payload['fixed_adjustments']
            : [];
        $current = $this->snapshotFixedAdjustments($hire);

        foreach (array_keys($current) as $key) {
            $next = $this->normalizeFixedField($incoming[$key] ?? null, $key === 'unloading_weight' ? 3 : 2);
            $before = $this->normalizeFixedField($current[$key] !== '' ? $current[$key] : null, $key === 'unloading_weight' ? 3 : 2);
            if ($before !== $next) {
                return true;
            }
        }

        return false;
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

        $existing = $this->activeForHire($hire, BrokerAdjustmentType::UnloadingWeight);
        if ($existing !== null) {
            return $this->replaceUnloadingWeight($existing, $hire, $actor, $newWeight, $reason, $occurredAt);
        }

        return $this->auditedTransaction->run(function () use ($hire, $actor, $company, $branch, $previousWeight, $newWeight, $reason, $occurredAt): BrokerAdjustment {
            return $this->withTenant($company, $branch, function () use ($hire, $actor, $company, $branch, $previousWeight, $newWeight, $reason, $occurredAt): BrokerAdjustment {
                $this->lockAdjustableHire($hire);

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
    public function store(LorryHire $hire, User $actor, array $payload): never
    {
        BrokerAdjustmentType::tryFrom((string) ($payload['type'] ?? ''));

        throw ValidationException::withMessages([
            'type' => 'Record Detention, Deviation, Misc. Expense, Unloading Weight, Shortage and Damage from Lorry Hire Edit.',
        ]);
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

        $existing = $this->activeForHire($hire, $type);
        if ($existing !== null) {
            return $this->replaceAmountAdjustment($existing, $hire, $actor, $type, $amount, $reason, $occurredAt);
        }

        return $this->auditedTransaction->run(function () use ($hire, $actor, $company, $branch, $type, $amount, $reason, $occurredAt): BrokerAdjustment {
            return $this->withTenant($company, $branch, function () use ($hire, $actor, $company, $branch, $type, $amount, $reason, $occurredAt): BrokerAdjustment {
                $this->lockAdjustableHire($hire);

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

        $existing = $this->activeForHire($hire, $type);
        if ($existing !== null) {
            return $this->replaceQuantityAdjustment($existing, $hire, $actor, $type, $quantity, $rate, $amount, $reason, $occurredAt);
        }

        return $this->auditedTransaction->run(function () use ($hire, $actor, $company, $branch, $type, $quantity, $rate, $amount, $reason, $occurredAt): BrokerAdjustment {
            return $this->withTenant($company, $branch, function () use ($hire, $actor, $company, $branch, $type, $quantity, $rate, $amount, $reason, $occurredAt): BrokerAdjustment {
                $this->lockAdjustableHire($hire);

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

    private function syncMoneyAdjustment(
        LorryHire $hire,
        User $actor,
        BrokerAdjustmentType $type,
        mixed $rawValue,
    ): void {
        $amount = $this->optionalPositiveMoney($rawValue);
        $existing = $this->activeForHire($hire, $type);

        if ($amount === null) {
            if ($existing !== null) {
                $this->removeAdjustment($existing, $hire, $actor);
            }

            return;
        }

        if ($type === BrokerAdjustmentType::Shortage || $type === BrokerAdjustmentType::Damage) {
            if ($existing !== null) {
                $this->replaceQuantityAdjustment(
                    $existing,
                    $hire,
                    $actor,
                    $type,
                    '1.000',
                    $amount,
                    $amount,
                    $existing->reason,
                    $existing->occurred_at?->toDateString(),
                );

                return;
            }

            $quantityPayload = ['quantity' => '1', 'rate' => $amount, 'amount' => $amount];
            if ($type === BrokerAdjustmentType::Shortage) {
                $this->createShortage($hire, $actor, $quantityPayload);
            } else {
                $this->createDamage($hire, $actor, $quantityPayload);
            }

            return;
        }

        if ($existing !== null) {
            $this->replaceAmountAdjustment(
                $existing,
                $hire,
                $actor,
                $type,
                $amount,
                $existing->reason,
                $existing->occurred_at?->toDateString(),
            );

            return;
        }

        $this->createAmountAdjustment($hire, $actor, $type, ['amount' => $amount]);
    }

    private function syncUnloadingWeight(LorryHire $hire, User $actor, mixed $rawValue): void
    {
        $existing = $this->activeForHire($hire, BrokerAdjustmentType::UnloadingWeight);
        $weight = $this->optionalPositiveDecimal($rawValue, 3);

        if ($weight === null) {
            if ($existing !== null) {
                $this->removeAdjustment($existing, $hire, $actor);
            }

            return;
        }

        $payload = [
            'new_weight' => $weight,
            'reason' => $existing?->reason,
            'occurred_at' => $existing?->occurred_at?->toDateString(),
        ];

        if ($existing !== null) {
            $this->replaceUnloadingWeight(
                $existing,
                $hire,
                $actor,
                $weight,
                $existing->reason,
                $existing->occurred_at?->toDateString(),
            );

            return;
        }

        $this->createUnloadingWeight($hire, $actor, $payload);
    }

    private function replaceAmountAdjustment(
        BrokerAdjustment $adjustment,
        LorryHire $hire,
        User $actor,
        BrokerAdjustmentType $type,
        string $amount,
        ?string $reason,
        ?string $occurredAt,
    ): BrokerAdjustment {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);
        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);
        $parsedOccurredAt = $this->parseOccurredAt($company, $occurredAt);

        return $this->auditedTransaction->run(function () use ($adjustment, $hire, $actor, $company, $branch, $type, $amount, $reason, $parsedOccurredAt): BrokerAdjustment {
            return $this->withTenant($company, $branch, function () use ($adjustment, $hire, $actor, $type, $amount, $reason, $parsedOccurredAt): BrokerAdjustment {
                $this->lockAdjustableHire($hire);
                $before = $adjustment->toArray();
                $adjustment->forceFill([
                    'amount' => $amount,
                    'reason' => $reason,
                    'occurred_at' => $parsedOccurredAt,
                ])->save();

                $this->refreshHireFinancials($hire, null);

                $this->audit->write(
                    AuditAction::Updated,
                    BrokerAdjustment::class,
                    $adjustment->id,
                    remark: 'Broker adjustment '.$type->value.' updated',
                    before: $before,
                    after: $adjustment->toArray(),
                );

                $this->accounting->correctBrokerAdjustment($adjustment->fresh() ?? $adjustment, $hire->fresh() ?? $hire, $actor);

                return $adjustment->fresh() ?? $adjustment;
            });
        });
    }

    private function replaceUnloadingWeight(
        BrokerAdjustment $adjustment,
        LorryHire $hire,
        User $actor,
        string $newWeight,
        ?string $reason,
        ?string $occurredAt,
    ): BrokerAdjustment {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);
        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);

        $previousWeight = $this->calculations->effectiveLoadingWeightBeforeUnloading($hire);
        $parsedOccurredAt = $this->parseOccurredAt($company, $occurredAt);

        return $this->auditedTransaction->run(function () use ($adjustment, $hire, $actor, $company, $branch, $previousWeight, $newWeight, $reason, $parsedOccurredAt): BrokerAdjustment {
            return $this->withTenant($company, $branch, function () use ($adjustment, $hire, $actor, $previousWeight, $newWeight, $reason, $parsedOccurredAt): BrokerAdjustment {
                $this->lockAdjustableHire($hire);
                $before = $adjustment->toArray();
                $previousFreight = $this->money($hire->freight_total);

                $adjustment->forceFill([
                    'previous_weight' => $previousWeight,
                    'new_weight' => $newWeight,
                    'weight' => $newWeight,
                    'reason' => $reason,
                    'occurred_at' => $parsedOccurredAt,
                ])->save();

                $freightTotal = $this->calculations->recalculateFreightForUnloading($hire, $newWeight);
                $freightDelta = bcsub($freightTotal, $previousFreight, 2);
                $this->refreshHireFinancials($hire, $freightTotal);

                $this->audit->write(
                    AuditAction::Updated,
                    BrokerAdjustment::class,
                    $adjustment->id,
                    remark: 'Unloading weight adjustment updated',
                    before: $before,
                    after: $adjustment->toArray(),
                );

                $this->accounting->correctBrokerAdjustment(
                    $adjustment->fresh() ?? $adjustment,
                    $hire->fresh() ?? $hire,
                    $actor,
                    $freightDelta,
                );

                return $adjustment->fresh() ?? $adjustment;
            });
        });
    }

    private function replaceQuantityAdjustment(
        BrokerAdjustment $adjustment,
        LorryHire $hire,
        User $actor,
        BrokerAdjustmentType $type,
        string $quantity,
        string $rate,
        string $amount,
        ?string $reason,
        ?string $occurredAt,
    ): BrokerAdjustment {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);
        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);
        $parsedOccurredAt = $this->parseOccurredAt($company, $occurredAt);

        return $this->auditedTransaction->run(function () use ($adjustment, $hire, $actor, $company, $branch, $type, $quantity, $rate, $amount, $reason, $parsedOccurredAt): BrokerAdjustment {
            return $this->withTenant($company, $branch, function () use ($adjustment, $hire, $actor, $type, $quantity, $rate, $amount, $reason, $parsedOccurredAt): BrokerAdjustment {
                $this->lockAdjustableHire($hire);
                $before = $adjustment->toArray();

                $adjustment->forceFill([
                    'amount' => $amount,
                    'quantity' => $quantity,
                    'rate' => $rate,
                    'reason' => $reason,
                    'occurred_at' => $parsedOccurredAt,
                ])->save();

                $this->refreshHireFinancials($hire, null);

                $this->audit->write(
                    AuditAction::Updated,
                    BrokerAdjustment::class,
                    $adjustment->id,
                    remark: 'Broker adjustment '.$type->value.' updated',
                    before: $before,
                    after: $adjustment->toArray(),
                );

                $this->accounting->correctBrokerAdjustment($adjustment->fresh() ?? $adjustment, $hire->fresh() ?? $hire, $actor);

                return $adjustment->fresh() ?? $adjustment;
            });
        });
    }

    private function removeAdjustment(BrokerAdjustment $adjustment, LorryHire $hire, User $actor): void
    {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);
        $this->assertCanCreate($actor, $company, $branch);

        $this->auditedTransaction->run(function () use ($adjustment, $hire, $actor, $company, $branch): void {
            $this->withTenant($company, $branch, function () use ($adjustment, $hire, $actor): void {
                $this->lockAdjustableHire($hire);
                $before = $adjustment->toArray();
                $freightBefore = $this->money($hire->freight_total);
                $type = $adjustment->type;

                $this->accounting->reverseBrokerAdjustment($adjustment, $actor);
                $adjustment->delete();

                $freightTotal = $type === BrokerAdjustmentType::UnloadingWeight
                    ? $this->resetFreightWithoutUnloading($hire)
                    : null;
                $this->refreshHireFinancials($hire, $freightTotal);

                $this->audit->write(
                    AuditAction::Updated,
                    BrokerAdjustment::class,
                    $adjustment->id,
                    remark: 'Broker adjustment '.$type->value.' removed',
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @return numeric-string
     */
    private function resetFreightWithoutUnloading(LorryHire $hire): string
    {
        $hire->loadMissing('goodsLines.lrGoodsRow');
        $freightTotal = '0.00';

        foreach ($hire->goodsLines as $line) {
            $freight = $this->calculations->calculatedLineFreight($line);
            $line->forceFill(['freight_amount' => $freight])->save();
            $freightTotal = bcadd($freightTotal, $freight, 2);
        }

        return $this->money($freightTotal);
    }

    /**
     * @return numeric-string|null
     */
    private function optionalPositiveMoney(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $money = $this->money($value);
        if (bccomp($money, '0', 2) <= 0) {
            return null;
        }

        return $money;
    }

    /**
     * @return numeric-string|null
     */
    private function optionalPositiveDecimal(mixed $value, int $scale): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $decimal = $this->money($value, $scale);
        if (bccomp($decimal, '0', $scale) <= 0) {
            return null;
        }

        return $decimal;
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

    /**
     * Serializes with vouchers and Lorry Hire edits, then revalidates the fresh hire.
     */
    private function lockAdjustableHire(LorryHire $hire): void
    {
        LorryHire::query()->whereKey($hire->id)->lockForUpdate()->firstOrFail();
        $hire->refresh();
        $this->assertAdjustable($hire);
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

    private function presentAdjustmentMoney(mixed $value): string
    {
        if (! filled($value)) {
            return '';
        }

        return $this->money($value);
    }

    private function presentAdjustmentDecimal(mixed $value, int $scale): string
    {
        if (! filled($value)) {
            return '';
        }

        $formatted = number_format((float) $value, $scale, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function normalizeFixedField(mixed $value, int $scale): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return $scale === 3
            ? $this->optionalPositiveDecimal($value, 3)
            : $this->optionalPositiveMoney($value);
    }

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
