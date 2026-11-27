<?php

namespace App\Domains\Subscription\Catalogue;

use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Internal Admin maintenance of the active subscription catalogue (module plans and add-ons).
 *
 * Prices change in place on the active catalogue version: every contract item, payment intent
 * and invoice already snapshots its own price, so edits only affect future quotes and renewals.
 */
final class SubscriptionCatalogueAdminService
{
    public const BYTES_PER_GB = 1073741824;

    public function __construct(
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    public function activeCatalogue(): ?SubscriptionCatalogueVersion
    {
        return SubscriptionCatalogueVersion::query()
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @param  array{module: string, name: string, annual_price: string|int|float, capacity_units: int}  $data
     */
    public function createPlan(array $data): SubscriptionPlan
    {
        $catalogue = $this->activeCatalogueOrFail();
        $module = SubscriptionModule::tryFrom($data['module']);
        if ($module === null) {
            throw ValidationException::withMessages(['module' => 'Select a valid transport module.']);
        }

        $name = trim($data['name']);
        $code = Str::slug($name, '_');
        $duplicate = SubscriptionPlan::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('module', $module->value)
            ->where(fn ($q) => $q->where('code', $code)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'A plan with this name already exists for the module.']);
        }

        return $this->tx->run(fn (): SubscriptionPlan => $this->withPlatformTenant(function () use ($catalogue, $module, $name, $code, $data): SubscriptionPlan {
            $plan = SubscriptionPlan::query()->create([
                'catalogue_version_id' => $catalogue->id,
                'module' => $module->value,
                'code' => $code,
                'name' => $name,
                'annual_price' => $this->price($data['annual_price']),
                'capacity_units' => max(0, (int) $data['capacity_units']),
                'sort_order' => (int) SubscriptionPlan::query()
                    ->where('catalogue_version_id', $catalogue->id)
                    ->where('module', $module->value)
                    ->max('sort_order') + 1,
                'is_active' => true,
            ]);

            $this->audit->write(
                action: AuditAction::Created,
                subjectType: SubscriptionPlan::class,
                subjectId: $plan->id,
                remark: 'Create subscription plan '.$plan->name,
                before: null,
                after: $plan->toArray(),
            );

            return $plan;
        }));
    }

    /**
     * @param  array{name: string, annual_price: string|int|float, capacity_units: int, is_active: bool}  $data
     */
    public function updatePlan(SubscriptionPlan $plan, array $data, string $remark): SubscriptionPlan
    {
        $this->assertInActiveCatalogue((string) $plan->catalogue_version_id);
        $trimmedRemark = $this->requireRemark($remark);

        return $this->tx->run(fn (): SubscriptionPlan => $this->withPlatformTenant(function () use ($plan, $data, $trimmedRemark): SubscriptionPlan {
            $locked = SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();
            $locked->fill([
                'name' => trim($data['name']),
                'annual_price' => $this->price($data['annual_price']),
                'capacity_units' => max(0, (int) $data['capacity_units']),
                'is_active' => $data['is_active'],
            ]);
            $this->assertHasChanges($locked->isDirty());
            $locked->save();

            $this->audit->write(
                action: AuditAction::Updated,
                subjectType: SubscriptionPlan::class,
                subjectId: $locked->id,
                remark: $trimmedRemark,
                before: $before,
                after: $locked->toArray(),
            );

            return $locked;
        }));
    }

    /**
     * @param  array{name: string, annual_price: string|int|float, credit_units_per_unit?: int|null, storage_gb_per_unit?: int|null, is_active: bool}  $data
     */
    public function updateAddon(SubscriptionAddon $addon, array $data, string $remark): SubscriptionAddon
    {
        $this->assertInActiveCatalogue((string) $addon->catalogue_version_id);
        $trimmedRemark = $this->requireRemark($remark);

        return $this->tx->run(fn (): SubscriptionAddon => $this->withPlatformTenant(function () use ($addon, $data, $trimmedRemark): SubscriptionAddon {
            $locked = SubscriptionAddon::query()->whereKey($addon->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();
            $attributes = [
                'name' => trim($data['name']),
                'annual_price' => $this->price($data['annual_price']),
                'is_active' => $data['is_active'],
            ];
            if ((int) $locked->credit_units_per_unit > 0 && isset($data['credit_units_per_unit'])) {
                $attributes['credit_units_per_unit'] = max(1, (int) $data['credit_units_per_unit']);
            }
            if ((int) $locked->storage_bytes_per_unit > 0 && isset($data['storage_gb_per_unit'])) {
                $attributes['storage_bytes_per_unit'] = max(1, (int) $data['storage_gb_per_unit']) * self::BYTES_PER_GB;
            }
            $locked->fill($attributes);
            $this->assertHasChanges($locked->isDirty());
            $locked->save();

            $this->audit->write(
                action: AuditAction::Updated,
                subjectType: SubscriptionAddon::class,
                subjectId: $locked->id,
                remark: $trimmedRemark,
                before: $before,
                after: $locked->toArray(),
            );

            return $locked;
        }));
    }

    private function activeCatalogueOrFail(): SubscriptionCatalogueVersion
    {
        return $this->activeCatalogue()
            ?? throw new SubscriptionDomainException('No active subscription catalogue is configured.');
    }

    private function assertInActiveCatalogue(string $catalogueVersionId): void
    {
        if ($this->activeCatalogueOrFail()->id !== $catalogueVersionId) {
            throw new SubscriptionDomainException('Only items in the active subscription catalogue can be edited.');
        }
    }

    private function requireRemark(string $remark): string
    {
        $trimmed = trim($remark);
        if ($trimmed === '') {
            throw ValidationException::withMessages(['remark' => 'An edit remark is required.']);
        }

        return $trimmed;
    }

    private function assertHasChanges(bool $isDirty): void
    {
        if (! $isDirty) {
            throw ValidationException::withMessages(['catalogue' => 'No changes to save.']);
        }
    }

    private function price(string|int|float $value): string
    {
        $price = DecimalMath::of((string) $value, 2);
        if (DecimalMath::compare($price, '0', 2) <= 0) {
            throw ValidationException::withMessages(['annual_price' => 'Annual price must be greater than zero.']);
        }

        return $price;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withPlatformTenant(callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(isInternal: true));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::set($previous);
            }
        }
    }
}
