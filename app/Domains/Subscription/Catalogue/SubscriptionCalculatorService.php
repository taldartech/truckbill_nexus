<?php

namespace App\Domains\Subscription\Catalogue;

use App\Domains\Subscription\Purchase\SubscriptionPurchaseService;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Collection;

/**
 * Internal Admin/CRM subscription calculator. Converts total requirements into catalogue
 * add-on quantities (after included allowances) and prices them with the Owner checkout engine.
 * Never persists anything.
 */
final class SubscriptionCalculatorService
{
    public function __construct(
        private readonly SubscriptionPurchaseService $purchase = new SubscriptionPurchaseService,
        private readonly SubscriptionCatalogueAdminService $catalogue = new SubscriptionCatalogueAdminService,
    ) {}

    /**
     * @param  array{
     *     plan_ids?: list<string>,
     *     addon_ids?: list<string>,
     *     tenure?: string|null,
     *     total_branches?: int|null,
     *     total_users?: int|null,
     *     broker_lr_credits?: int|null,
     *     partload_lr_credits?: int|null,
     *     total_storage_gb?: int|null,
     *     state_id?: string|null
     * }  $requirements
     * @return array{quote: array<string, mixed>, derived: list<array{code: string, name: string, quantity: int}>, additional_charges: string, subscription_amount: string}
     */
    public function calculate(array $requirements): array
    {
        $catalogue = $this->catalogue->activeCatalogue();
        $planIds = array_map('strval', $requirements['plan_ids'] ?? []);
        $plans = $catalogue === null ? collect() : SubscriptionPlan::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('is_active', true)
            ->whereIn('id', $planIds)
            ->get();
        $addons = $catalogue === null ? collect() : SubscriptionAddon::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('code');

        $includedBranches = (int) config('subscription.capacity.included_branches', 2);
        $includedUsers = $includedBranches * (int) config('subscription.capacity.seats_per_included_branch', 3);
        $includedStorageGb = intdiv((int) config('subscription.storage.default_allocation_bytes', 0), SubscriptionCatalogueAdminService::BYTES_PER_GB);

        $quantities = array_filter([
            'extra_branch' => max(0, (int) ($requirements['total_branches'] ?? 0) - $includedBranches),
            'extra_user' => max(0, (int) ($requirements['total_users'] ?? 0) - $includedUsers),
            'lr_credits_broker' => $this->packsFor($addons->get('lr_credits_broker'), (int) ($requirements['broker_lr_credits'] ?? 0), $this->includedCredits($plans, 'broker'), 'credit'),
            'lr_credits_partload' => $this->packsFor($addons->get('lr_credits_partload'), (int) ($requirements['partload_lr_credits'] ?? 0), $this->includedCredits($plans, 'partload'), 'credit'),
            'extra_storage' => $this->packsFor($addons->get('extra_storage'), (int) ($requirements['total_storage_gb'] ?? 0), $includedStorageGb, 'storage'),
        ]);

        $addonIds = [];
        $addonQuantities = [];
        $derived = [];
        foreach ($quantities as $code => $quantity) {
            $addon = $addons->get($code);
            if ($addon === null) {
                continue;
            }
            $addonQuantities[(string) $addon->id] = $quantity;
            $derived[] = ['code' => $code, 'name' => $addon->name, 'quantity' => $quantity];
        }

        foreach (array_map('strval', $requirements['addon_ids'] ?? []) as $addonId) {
            $addon = $addons->firstWhere('id', $addonId);
            if ($addon !== null && ! $addon->is_quantifiable) {
                $addonIds[] = $addon->id;
            }
        }

        $quote = $this->purchase->quoteForProspect([
            'plan_ids' => array_values($plans->map(fn (SubscriptionPlan $plan): string => (string) $plan->id)->all()),
            'addon_ids' => $addonIds,
            'addon_quantities' => $addonQuantities,
            'tenure' => $requirements['tenure'] ?? null,
        ], $requirements['state_id'] ?? null);

        $additionalCharges = '0.00';
        foreach ($quote['line_items'] as $item) {
            if (isset($quantities[(string) $item['code']]) && $item['item_type'] === 'addon') {
                $additionalCharges = DecimalMath::add($additionalCharges, (string) $item['amount'], 2);
            }
        }

        return [
            'quote' => $quote,
            'derived' => $derived,
            'additional_charges' => $additionalCharges,
            'subscription_amount' => DecimalMath::sub((string) $quote['subtotal_amount'], $additionalCharges, 2),
        ];
    }

    /**
     * @param  Collection<int, SubscriptionPlan>  $plans
     */
    private function includedCredits(Collection $plans, string $module): int
    {
        return (int) $plans->where('module', $module)->sum('capacity_units');
    }

    private function packsFor(?SubscriptionAddon $addon, int $required, int $included, string $unit): int
    {
        if ($addon === null) {
            return 0;
        }

        $perPack = $unit === 'credit'
            ? (int) $addon->credit_units_per_unit
            : intdiv((int) $addon->storage_bytes_per_unit, SubscriptionCatalogueAdminService::BYTES_PER_GB);
        $shortfall = $required - $included;

        return $perPack > 0 && $shortfall > 0 ? (int) ceil($shortfall / $perPack) : 0;
    }
}
