<?php

namespace App\Domains\Subscription\Purchase;

use App\Domains\Accounting\AccountingActivationLifecycleService;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Capacity\CompanyCapacityService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\PaymentIntentService;
use App\Domains\Subscription\Pricing\SubscriptionPricingCalculator;
use App\Domains\Subscription\Support\DecimalMath;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionItem;
use App\Models\PaymentIntent;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Authoritative single-flow subscription purchase, mid-term prorated upgrade, and renewal service.
 */
final class SubscriptionPurchaseService
{
    public function __construct(
        private readonly SubscriptionPricingCalculator $pricing = new SubscriptionPricingCalculator,
        private readonly CompanySubscriptionContractService $contracts = new CompanySubscriptionContractService,
        private readonly PlatformBillingProfileService $billingProfiles = new PlatformBillingProfileService,
        private readonly CompanyCapacityService $capacity = new CompanyCapacityService,
        private readonly SubscriptionClock $clock = new SubscriptionClock,
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * Build authoritative live purchase/upgrade/renewal quote for a Company.
     *
     * @param  array{
     *     plan_ids?: list<string>,
     *     addon_ids?: list<string>,
     *     addon_quantities?: array<string, int>,
     *     tenure?: string|null,
     *     mode?: string|null
     * }  $input
     * @return array<string, mixed>
     */
    public function quote(Company $company, array $input): array
    {
        $catalogue = $this->activeCatalogueOrFail();
        $activeSubscription = $this->activeSubscription($company);
        $requestedMode = (string) ($input['mode'] ?? '');

        $mode = match (true) {
            $activeSubscription !== null && $requestedMode === 'renewal' => 'renewal',
            $activeSubscription !== null => 'upgrade',
            default => 'initial',
        };

        $today = $this->clock->now()->timezone('Asia/Kolkata')->startOfDay();
        $tenure = SubscriptionTenure::tryFrom((string) ($input['tenure'] ?? ''))
            ?? ($activeSubscription !== null
                ? (SubscriptionTenure::tryFrom((string) $activeSubscription->tenure) ?? SubscriptionTenure::Yearly)
                : SubscriptionTenure::Yearly);

        $allPlans = SubscriptionPlan::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('is_active', true)
            ->orderBy('module')
            ->orderBy('sort_order')
            ->get()
            ->keyBy('id');

        $allAddons = SubscriptionAddon::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $selectedPlanIds = array_values(array_unique(array_map('strval', $input['plan_ids'] ?? [])));
        $selectedAddonIds = array_values(array_unique(array_map('strval', $input['addon_ids'] ?? [])));

        /** @var array<string, int> $addonQuantities */
        $addonQuantities = [];
        foreach (($input['addon_quantities'] ?? []) as $addonId => $qty) {
            $q = (int) $qty;
            if ($q > 0) {
                $addonQuantities[(string) $addonId] = $q;
                if (! in_array((string) $addonId, $selectedAddonIds, true)) {
                    $selectedAddonIds[] = (string) $addonId;
                }
            }
        }

        /** @var Collection<int, SubscriptionPlan> $selectedPlans */
        $selectedPlans = collect();
        foreach ($selectedPlanIds as $id) {
            $plan = $allPlans->get($id);
            if ($plan !== null) {
                $selectedPlans->push($plan);
            }
        }
        // Guarantee at most one plan per module (last selected wins cleanly).
        $selectedPlansByModule = $selectedPlans->keyBy('module');

        /** @var Collection<int, SubscriptionAddon> $selectedAddons */
        $selectedAddons = collect();
        foreach ($selectedAddonIds as $id) {
            $addon = $allAddons->get($id);
            if ($addon !== null) {
                if (DecimalMath::compare((string) $addon->annual_price, '0.00', 2) <= 0) {
                    throw new SubscriptionDomainException(
                        "Add-on {$addon->name} does not have a valid configured commercial price.",
                    );
                }
                $selectedAddons->push($addon);
            }
        }

        $activeModuleSet = [];
        $activePlanIds = [];
        $activeAddonCodes = [];
        if ($activeSubscription !== null) {
            $activeSubscription->loadMissing(['items.addon']);
            foreach ($activeSubscription->items as $item) {
                if ($item->module !== null && $item->module !== '') {
                    $activeModuleSet[$item->module] = true;
                }
                if ($item->plan_id !== null) {
                    $activePlanIds[] = $item->plan_id;
                }
                if ($item->item_type === 'addon' || $item->addon_id !== null) {
                    $code = $item->code !== '' ? $item->code : ($item->addon !== null ? $item->addon->code : '');
                    if ($code !== '') {
                        $activeAddonCodes[$code] = ($activeAddonCodes[$code] ?? 0) + max(1, (int) ($item->quantity ?? 1));
                    }
                }
            }
        }

        if ($mode === 'upgrade') {
            return $this->quoteMidTermUpgrade(
                $company,
                $activeSubscription,
                $today,
                $selectedPlansByModule,
                $selectedAddons,
                $addonQuantities,
                $activeModuleSet,
                $activePlanIds,
                $activeAddonCodes,
            );
        }

        if ($mode === 'renewal') {
            return $this->quoteRenewal(
                $company,
                $activeSubscription,
                $today,
                $tenure,
                $selectedPlansByModule,
                $selectedAddons,
                $addonQuantities,
                $activeModuleSet,
                $activePlanIds,
                $activeAddonCodes,
            );
        }

        return $this->quoteInitial(
            $company,
            $today,
            $tenure,
            $selectedPlansByModule,
            $selectedAddons,
            $addonQuantities,
        );
    }

    /**
     * Price a prospective initial purchase without any Company, owner, contract or payment record.
     * Runs the exact initial-purchase path used by Owner checkout so identical inputs yield identical totals.
     *
     * @param  array{
     *     plan_ids?: list<string>,
     *     addon_ids?: list<string>,
     *     addon_quantities?: array<string, int>,
     *     tenure?: string|null
     * }  $input
     * @return array<string, mixed>
     */
    public function quoteForProspect(array $input, ?string $customerStateId = null): array
    {
        $prospect = new Company;
        $prospect->state_id = $customerStateId;

        return $this->quote($prospect, [...$input, 'mode' => 'initial']);
    }

    /**
     * Automate draft/contract creation and PaymentIntent creation for checkout.
     *
     * @param  array{
     *     plan_ids?: list<string>,
     *     addon_ids?: list<string>,
     *     addon_quantities?: array<string, int>,
     *     tenure?: string|null,
     *     mode?: string|null
     * }  $input
     * @return array{intent: PaymentIntent, subscription: CompanySubscription, quote: array<string, mixed>}
     */
    public function initiateCheckout(
        User $actor,
        Company $company,
        array $input,
        PaymentIntentService $paymentIntents,
    ): array {
        $quote = $this->quote($company, $input);
        if (! ($quote['payable'] ?? false) || DecimalMath::compare((string) $quote['total_payable'], '0', 2) <= 0) {
            throw new SubscriptionDomainException((string) ($quote['validation_message'] ?? 'Select modules or add-ons with a positive payable total to continue.'));
        }

        $catalogue = $this->activeCatalogueOrFail();
        $mode = (string) $quote['mode'];
        $tenure = SubscriptionTenure::from((string) $quote['tenure']);
        $renewalDate = CarbonImmutable::parse((string) $quote['common_renewal_date']);

        $subscription = $this->tx->run(function () use ($actor, $company, $catalogue, $quote, $mode, $tenure, $renewalDate): CompanySubscription {
            if ($mode === 'initial') {
                $planIds = array_values(array_map('strval', $quote['payable_plan_ids'] ?? []));
                $addonIds = array_values(array_map('strval', $quote['payable_addon_ids'] ?? []));
                $addonQuantities = $quote['payable_addon_quantities'] ?? [];

                $subscription = $this->contracts->createDraftContract(
                    actor: $actor,
                    company: $company,
                    catalogue: $catalogue,
                    planIds: $planIds,
                    addonIds: $addonIds,
                    tenure: $tenure,
                    renewalEligible: false,
                    commonRenewalDate: $renewalDate,
                );

                // Persist addon quantities and capacity metadata cleanly on internal draft.
                $subscription->loadMissing('items');
                foreach ($subscription->items as $item) {
                    if ($item->addon_id !== null && isset($addonQuantities[$item->addon_id])) {
                        $item->forceFill(['quantity' => max(1, (int) $addonQuantities[$item->addon_id])])->save();
                    }
                }

                $breakdown = is_array($subscription->pricing_breakdown) ? $subscription->pricing_breakdown : [];
                $breakdown['purchased_branches'] = (int) ($quote['included_capacity']['purchased_branches'] ?? 0);
                $breakdown['purchased_seats'] = (int) ($quote['included_capacity']['purchased_seats'] ?? 0);
                $breakdown['taxable_amount'] = $quote['taxable_amount'];
                $breakdown['total_tax_amount'] = $quote['gst']['total_tax_amount'] ?? '0.00';
                $breakdown['grand_total'] = $quote['total_payable'];

                $subscription->forceFill([
                    'status' => 'pending_payment',
                    'discounted_annual_total' => (string) $quote['total_payable'],
                    'pricing_breakdown' => $breakdown,
                ])->save();

                return $subscription->fresh('items') ?? $subscription;
            }

            $active = $this->activeSubscription($company);
            if ($active === null) {
                throw new SubscriptionDomainException('No active subscription found for upgrade or renewal.');
            }

            return $active;
        });

        $purpose = match ($mode) {
            'renewal' => 'renewal',
            'upgrade' => count($quote['payable_plan_ids'] ?? []) > 0 ? 'upgrade' : 'addon_add',
            default => 'subscription_activation',
        };

        $fingerprint = hash('sha256', json_encode([
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
            'purpose' => $purpose,
            'plans' => $quote['payable_plan_ids'] ?? [],
            'addons' => $quote['payable_addon_quantities'] ?? [],
            'tenure' => $quote['tenure'],
            'total' => $quote['total_payable'],
            'date' => $this->clock->now()->toDateString(),
        ], JSON_THROW_ON_ERROR));

        $intent = $paymentIntents->create(
            company: $company,
            purpose: $purpose,
            amount: (string) $quote['total_payable'],
            idempotencyKey: 'sub_purchase:'.substr($fingerprint, 0, 32),
            subscription: $subscription,
            meta: [
                'quote' => $quote,
                'payable_plan_ids' => $quote['payable_plan_ids'] ?? [],
                'payable_addon_ids' => $quote['payable_addon_ids'] ?? [],
                'payable_addon_quantities' => $quote['payable_addon_quantities'] ?? [],
                'purchase_type' => $quote['purchase_type'] ?? $purpose,
            ],
        );

        return [
            'intent' => $intent,
            'subscription' => $subscription,
            'quote' => $quote,
        ];
    }

    /**
     * Apply verified mid-term module/add-on upgrade or renewal expansion onto an active CompanySubscription.
     * Must be called inside the verified payment transaction.
     */
    public function applyVerifiedPaymentModifications(PaymentIntent $intent, CompanySubscription $subscription): void
    {
        $meta = is_array($intent->meta) ? $intent->meta : [];
        $planIds = array_values(array_map('strval', $meta['payable_plan_ids'] ?? []));
        $addonIds = array_values(array_map('strval', $meta['payable_addon_ids'] ?? []));
        /** @var array<string, int> $addonQuantities */
        $addonQuantities = is_array($meta['payable_addon_quantities'] ?? null) ? $meta['payable_addon_quantities'] : [];

        /** @var CompanySubscription $locked */
        $locked = CompanySubscription::query()->whereKey($subscription->id)->lockForUpdate()->with(['items.addon'])->firstOrFail();
        $company = Company::query()->whereKey($locked->company_id)->firstOrFail();

        if ($planIds !== [] || $addonIds !== []) {
            $plans = SubscriptionPlan::query()->whereIn('id', $planIds)->get()->keyBy('id');
            $addons = SubscriptionAddon::query()->whereIn('id', $addonIds)->get()->keyBy('id');

            foreach ($plans as $plan) {
                $existingSameModule = $locked->items->firstWhere('module', $plan->module);
                if ($existingSameModule !== null) {
                    if ($existingSameModule->plan_id === $plan->id) {
                        continue;
                    }
                    // Plan tier upgrade for the same module: replace snapshot on the item cleanly.
                    $existingSameModule->forceFill([
                        'plan_id' => $plan->id,
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'annual_price_snapshot' => $plan->annual_price,
                        'capacity_units_snapshot' => $plan->capacity_units,
                    ])->save();

                    continue;
                }

                CompanySubscriptionItem::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'company_subscription_id' => $locked->id,
                    'item_type' => 'plan',
                    'plan_id' => $plan->id,
                    'addon_id' => null,
                    'module' => $plan->module,
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'annual_price_snapshot' => $plan->annual_price,
                    'capacity_units_snapshot' => $plan->capacity_units,
                    'quantity' => 1,
                ]);
            }

            foreach ($addons as $addon) {
                $qty = max(1, (int) ($addonQuantities[$addon->id] ?? 1));

                if ($addon->credit_units_per_unit > 0 && in_array($addon->code, ['lr_credits_broker', 'lr_credits_partload'], true)) {
                    $ledgerType = $addon->code === 'lr_credits_broker' ? 'broker' : 'partload';
                    $units = (string) ($qty * (int) $addon->credit_units_per_unit);
                    $ledger = $this->contracts->ensureCreditLedger($company, $ledgerType);
                    $this->contracts->appendCreditEntry(
                        $ledger,
                        $units,
                        'grant',
                        'Purchased '.$addon->name,
                        'pay:'.$intent->id.':'.$addon->code,
                    );

                    continue;
                }

                $existingAddon = $locked->items->first(fn (CompanySubscriptionItem $item): bool => ($item->addon_id === $addon->id || $item->code === $addon->code));
                if ($existingAddon !== null) {
                    if ($addon->is_quantifiable) {
                        $newQty = $intent->purpose === 'renewal'
                            ? $qty
                            : (int) $existingAddon->quantity + $qty;
                        $existingAddon->forceFill(['quantity' => $newQty])->save();
                    }

                    continue;
                }

                CompanySubscriptionItem::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'company_subscription_id' => $locked->id,
                    'item_type' => 'addon',
                    'plan_id' => null,
                    'addon_id' => $addon->id,
                    'module' => null,
                    'code' => $addon->code,
                    'name' => $addon->name,
                    'annual_price_snapshot' => $addon->annual_price,
                    'capacity_units_snapshot' => null,
                    'quantity' => $qty,
                ]);
            }
        }

        $locked->load('items.addon');
        $coreModules = $locked->items
            ->where('item_type', 'plan')
            ->pluck('module')
            ->filter()
            ->unique()
            ->count();

        $extraBranches = 0;
        $extraSeats = 0;
        foreach ($locked->items as $item) {
            $code = $item->code !== '' ? $item->code : ($item->addon !== null ? $item->addon->code : '');
            $qty = max(1, (int) ($item->quantity ?? 1));
            if ($code === 'extra_branch') {
                $extraBranches += $qty;
            } elseif ($code === 'extra_user') {
                $extraSeats += $qty;
            }
        }

        $breakdown = is_array($locked->pricing_breakdown) ? $locked->pricing_breakdown : [];
        $breakdown['purchased_branches'] = $extraBranches;
        $breakdown['purchased_seats'] = $extraSeats;

        $locked->forceFill([
            'core_module_count' => $coreModules,
            'pricing_breakdown' => $breakdown,
        ])->save();

        if (in_array($intent->purpose, ['upgrade', 'addon_add'], true)) {
            $this->contracts->writeImmutableSnapshot($locked, null, 'verified_'.$intent->purpose);
        }

        $this->audit->write(
            action: AuditAction::Updated,
            subjectType: 'company_subscription',
            subjectId: $locked->id,
            remark: 'Apply verified '.$intent->purpose.' payment items',
            before: null,
            after: $locked->fresh('items')?->toArray(),
        );

        CompanyCapabilityResolver::invalidate($company);

        if (app(CompanyCapabilityResolver::class)->allowsRead($company, CompanyCapabilities::ACCOUNTING)) {
            app(AccountingActivationLifecycleService::class)->ensureCutoverState($company);
        }
    }

    /**
     * @param  Collection<string, SubscriptionPlan>  $selectedPlansByModule
     * @param  Collection<int, SubscriptionAddon>  $selectedAddons
     * @param  array<string, int>  $addonQuantities
     * @return array<string, mixed>
     */
    private function quoteInitial(
        Company $company,
        CarbonImmutable $today,
        SubscriptionTenure $tenure,
        Collection $selectedPlansByModule,
        Collection $selectedAddons,
        array $addonQuantities,
    ): array {
        $selectedModules = $selectedPlansByModule->keys()->values()->all();
        $serviceStart = $today->toDateString();
        $renewalDate = $today->addMonthsNoOverflow($tenure->months())->subDay();
        $serviceEnd = $renewalDate->toDateString();

        if ($selectedPlansByModule->isEmpty()) {
            return $this->emptyQuote($company, 'initial', $tenure, $serviceStart, $serviceEnd, 'Select at least one core module (Broker, OwnFleet, or Partload).');
        }

        $planPrices = array_values(
            $selectedPlansByModule->map(fn (SubscriptionPlan $plan): string => DecimalMath::of((string) $plan->annual_price, 2))->all()
        );
        $coreCalc = $this->pricing->calculate($planPrices, $tenure, false);

        $lineItems = [];
        $subtotal = DecimalMath::of($coreCalc['period_before_tenure'], 2);
        $coreDiscount = DecimalMath::sub($coreCalc['period_before_tenure'], $coreCalc['final_period_amount'], 2);
        // Include bundle discount in overall subtotal vs discount comparison.
        $rawAnnualSum = $coreCalc['base_annual'];
        $rawPeriodBeforeBundle = $this->pricing->periodAmountBeforeTenureDiscount($rawAnnualSum, $tenure);
        $subtotal = $rawPeriodBeforeBundle;
        $totalDiscount = DecimalMath::sub($rawPeriodBeforeBundle, $coreCalc['final_period_amount'], 2);

        $extraBranches = 0;
        $extraSeats = 0;
        $extraStorageBytes = 0;
        $lrCreditsBroker = 0;
        $lrCreditsPartload = 0;

        foreach ($selectedPlansByModule as $plan) {
            $lineItems[] = [
                'item_type' => 'plan',
                'id' => $plan->id,
                'module' => $plan->module,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->name.' ('.$this->tenureLabel($tenure).')',
                'quantity' => 1,
                'unit_annual_price' => (string) $plan->annual_price,
                'amount' => $this->pricing->periodAmountBeforeTenureDiscount((string) $plan->annual_price, $tenure),
                'already_subscribed' => false,
                'currency' => 'INR',
            ];
            if ($plan->module === 'broker' && $plan->capacity_units > 0) {
                $lrCreditsBroker += $plan->capacity_units;
            }
            if ($plan->module === 'partload' && $plan->capacity_units > 0) {
                $lrCreditsPartload += $plan->capacity_units;
            }
        }

        $addonResult = $this->priceSelectedAddons(
            addons: $selectedAddons,
            addonQuantities: $addonQuantities,
            selectedModules: array_values($selectedModules),
            tenure: $tenure,
            prorateActivation: null,
            prorateRenewal: null,
            alreadySubscribedAddonCodes: [],
            isRenewal: false,
        );

        foreach ($addonResult['line_items'] as $item) {
            $lineItems[] = $item;
        }

        $subtotal = DecimalMath::add($subtotal, $addonResult['subtotal'], 2);
        $taxableAmount = DecimalMath::add($coreCalc['final_period_amount'], $addonResult['taxable'], 2);
        $totalDiscount = DecimalMath::sub($subtotal, $taxableAmount, 2);
        if (DecimalMath::compare($totalDiscount, '0', 2) < 0) {
            $totalDiscount = '0.00';
        }

        $extraBranches += $addonResult['extra_branches'];
        $extraSeats += $addonResult['extra_seats'];
        $extraStorageBytes += $addonResult['extra_storage_bytes'];
        $lrCreditsBroker += $addonResult['lr_credits_broker'];
        $lrCreditsPartload += $addonResult['lr_credits_partload'];

        $tax = $this->billingProfiles->computeTaxForCompany($company, $taxableAmount);

        return $this->finalizeQuotePayload(
            company: $company,
            mode: 'initial',
            purchaseType: 'initial',
            tenure: $tenure,
            serviceStart: $serviceStart,
            serviceEnd: $serviceEnd,
            renewalDate: $serviceEnd,
            selectedPlans: $selectedPlansByModule->values(),
            selectedAddons: $addonResult['payable_addons'],
            addonQuantities: $addonResult['payable_quantities'],
            alreadyModules: [],
            alreadyAddons: [],
            lineItems: $lineItems,
            subtotalAmount: $subtotal,
            discountAmount: $totalDiscount,
            taxableAmount: $taxableAmount,
            tax: $tax,
            discountDetails: [
                'bundle_percent' => $coreCalc['bundle_percent'],
                'tenure_percent' => $coreCalc['tenure_percent'],
                'renewal_percent' => $coreCalc['renewal_percent'],
                'mid_term_discount_percent' => '0',
                'prorated' => false,
                'proration_days' => null,
            ],
            includedCapacity: $this->buildIncludedCapacity(
                $company,
                $selectedPlansByModule->values(),
                $extraBranches,
                $extraSeats,
                $extraStorageBytes,
                $lrCreditsBroker,
                $lrCreditsPartload,
            ),
            payable: true,
            validationMessage: null,
        );
    }

    /**
     * @param  Collection<string, SubscriptionPlan>  $selectedPlansByModule
     * @param  Collection<int, SubscriptionAddon>  $selectedAddons
     * @param  array<string, int>  $addonQuantities
     * @param  array<string, bool>  $activeModuleSet
     * @param  list<string>  $activePlanIds
     * @param  array<string, int>  $activeAddonCodes
     * @return array<string, mixed>
     */
    private function quoteMidTermUpgrade(
        Company $company,
        CompanySubscription $activeSubscription,
        CarbonImmutable $today,
        Collection $selectedPlansByModule,
        Collection $selectedAddons,
        array $addonQuantities,
        array $activeModuleSet,
        array $activePlanIds,
        array $activeAddonCodes,
    ): array {
        $tenure = SubscriptionTenure::tryFrom((string) $activeSubscription->tenure) ?? SubscriptionTenure::Yearly;
        $renewalDate = $activeSubscription->common_renewal_date !== null
            ? CarbonImmutable::parse($activeSubscription->common_renewal_date->toDateString())->startOfDay()
            : $today->addYear();

        if ($renewalDate->lessThanOrEqualTo($today)) {
            return $this->emptyQuote(
                $company,
                'upgrade',
                $tenure,
                $today->toDateString(),
                $renewalDate->toDateString(),
                'Your subscription term has reached its renewal date. Use Renew Subscription to extend and upgrade.',
            );
        }

        $serviceStart = $today->toDateString();
        $serviceEnd = $renewalDate->toDateString();
        $remainingDays = $today->diffInDays($renewalDate) + 1;

        $existingCoreCount = count($activeModuleSet);
        $midTermDiscount = $this->pricing->midTermModuleDiscountPercent($existingCoreCount);

        $lineItems = [];
        $payablePlans = collect();
        $subtotal = '0.00';
        $taxableAmount = '0.00';

        foreach ($selectedPlansByModule as $plan) {
            if (in_array($plan->id, $activePlanIds, true)) {
                continue; // Already subscribed — never charge again!
            }

            if (isset($activeModuleSet[$plan->module])) {
                // Tier upgrade within same module: difference between new plan and existing plan, prorated.
                $existingItem = $activeSubscription->items->firstWhere('module', $plan->module);
                $existingPrice = $existingItem !== null ? (string) $existingItem->annual_price_snapshot : '0.00';
                $annualDelta = DecimalMath::sub((string) $plan->annual_price, $existingPrice, 2);
                if (DecimalMath::compare($annualDelta, '0', 2) <= 0) {
                    continue;
                }
                $proratedRaw = $this->pricing->prorateAnnual($annualDelta, $today, $renewalDate);
                $discountedAnnual = DecimalMath::applyPercentDiscount($annualDelta, $midTermDiscount, 2);
                $proratedNet = $this->pricing->prorateAnnual($discountedAnnual, $today, $renewalDate);
                $desc = $plan->name.' (Upgrade delta prorated · '.$remainingDays.' days)';
            } else {
                $proratedRaw = $this->pricing->prorateAnnual((string) $plan->annual_price, $today, $renewalDate);
                $discountedAnnual = DecimalMath::applyPercentDiscount((string) $plan->annual_price, $midTermDiscount, 2);
                $proratedNet = $this->pricing->prorateAnnual($discountedAnnual, $today, $renewalDate);
                $desc = $plan->name.' (Prorated · '.$remainingDays.' days to '.$serviceEnd.')';
            }

            $payablePlans->push($plan);
            $subtotal = DecimalMath::add($subtotal, $proratedRaw, 2);
            $taxableAmount = DecimalMath::add($taxableAmount, $proratedNet, 2);
            $lineItems[] = [
                'item_type' => 'plan',
                'id' => $plan->id,
                'module' => $plan->module,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $desc,
                'quantity' => 1,
                'unit_annual_price' => (string) $plan->annual_price,
                'amount' => $proratedNet,
                'already_subscribed' => false,
                'currency' => 'INR',
            ];
        }

        $effectiveModules = array_values(array_unique([
            ...array_keys($activeModuleSet),
            ...$selectedPlansByModule->keys()->all(),
        ]));

        $addonResult = $this->priceSelectedAddons(
            addons: $selectedAddons,
            addonQuantities: $addonQuantities,
            selectedModules: $effectiveModules,
            tenure: $tenure,
            prorateActivation: $today,
            prorateRenewal: $renewalDate,
            alreadySubscribedAddonCodes: array_keys($activeAddonCodes),
            isRenewal: false,
        );

        foreach ($addonResult['line_items'] as $item) {
            $lineItems[] = $item;
        }

        $subtotal = DecimalMath::add($subtotal, $addonResult['subtotal'], 2);
        $taxableAmount = DecimalMath::add($taxableAmount, $addonResult['taxable'], 2);
        $discountAmount = DecimalMath::sub($subtotal, $taxableAmount, 2);
        if (DecimalMath::compare($discountAmount, '0', 2) < 0) {
            $discountAmount = '0.00';
        }

        $hasChanges = $payablePlans->isNotEmpty() || $addonResult['payable_addons']->isNotEmpty();
        $tax = $this->billingProfiles->computeTaxForCompany($company, $taxableAmount);

        $activePlans = array_values(
            $activeSubscription->items
                ->where('item_type', 'plan')
                ->map(fn (CompanySubscriptionItem $i): array => [
                    'plan_id' => $i->plan_id,
                    'module' => $i->module,
                    'code' => $i->code,
                    'name' => $i->name,
                ])->all()
        );

        $activeAddonsList = array_values(
            $activeSubscription->items
                ->where('item_type', 'addon')
                ->map(fn (CompanySubscriptionItem $i): array => [
                    'addon_id' => $i->addon_id,
                    'code' => $i->code,
                    'name' => $i->name,
                    'quantity' => (int) ($i->quantity ?? 1),
                ])->all()
        );

        return $this->finalizeQuotePayload(
            company: $company,
            mode: 'upgrade',
            purchaseType: $payablePlans->isNotEmpty() ? 'prorated' : 'addon',
            tenure: $tenure,
            serviceStart: $serviceStart,
            serviceEnd: $serviceEnd,
            renewalDate: $serviceEnd,
            selectedPlans: $payablePlans,
            selectedAddons: $addonResult['payable_addons'],
            addonQuantities: $addonResult['payable_quantities'],
            alreadyModules: $activePlans,
            alreadyAddons: $activeAddonsList,
            lineItems: $lineItems,
            subtotalAmount: $subtotal,
            discountAmount: $discountAmount,
            taxableAmount: $taxableAmount,
            tax: $tax,
            discountDetails: [
                'bundle_percent' => '0',
                'tenure_percent' => '0',
                'renewal_percent' => '0',
                'mid_term_discount_percent' => $midTermDiscount,
                'prorated' => true,
                'proration_days' => $remainingDays,
            ],
            includedCapacity: $this->buildIncludedCapacity(
                $company,
                $payablePlans,
                $addonResult['extra_branches'],
                $addonResult['extra_seats'],
                $addonResult['extra_storage_bytes'],
                $addonResult['lr_credits_broker'],
                $addonResult['lr_credits_partload'],
                includeExistingCapacity: true,
            ),
            payable: $hasChanges && DecimalMath::compare($tax['grand_total'], '0', 2) > 0,
            validationMessage: $hasChanges ? null : 'Select a new module, add-on (e.g. Accounting), or additional capacity to upgrade.',
        );
    }

    /**
     * @param  Collection<string, SubscriptionPlan>  $selectedPlansByModule
     * @param  Collection<int, SubscriptionAddon>  $selectedAddons
     * @param  array<string, int>  $addonQuantities
     * @param  array<string, bool>  $activeModuleSet
     * @param  list<string>  $activePlanIds
     * @param  array<string, int>  $activeAddonCodes
     * @return array<string, mixed>
     */
    private function quoteRenewal(
        Company $company,
        CompanySubscription $activeSubscription,
        CarbonImmutable $today,
        SubscriptionTenure $tenure,
        Collection $selectedPlansByModule,
        Collection $selectedAddons,
        array $addonQuantities,
        array $activeModuleSet,
        array $activePlanIds,
        array $activeAddonCodes,
    ): array {
        $baseRenewalStart = $activeSubscription->paid_through_date !== null
            ? CarbonImmutable::parse($activeSubscription->paid_through_date->toDateString())->addDay()
            : $today;
        if ($baseRenewalStart->lessThan($today)) {
            $baseRenewalStart = $today;
        }
        $newRenewalEnd = $baseRenewalStart->addMonthsNoOverflow($tenure->months())->subDay();

        // If caller did not pass plans explicitly, default to active plans.
        if ($selectedPlansByModule->isEmpty() && $activePlanIds !== []) {
            $plans = SubscriptionPlan::query()->whereIn('id', $activePlanIds)->get()->keyBy('module');
            $selectedPlansByModule = $plans;
        }

        if ($selectedPlansByModule->isEmpty()) {
            return $this->emptyQuote($company, 'renewal', $tenure, $baseRenewalStart->toDateString(), $newRenewalEnd->toDateString(), 'Select at least one core module for renewal.');
        }

        $planPrices = array_values(
            $selectedPlansByModule->map(fn (SubscriptionPlan $plan): string => DecimalMath::of((string) $plan->annual_price, 2))->all()
        );
        $coreCalc = $this->pricing->calculate($planPrices, $tenure, renewalEligible: true);
        $rawPeriod = $this->pricing->periodAmountBeforeTenureDiscount($coreCalc['base_annual'], $tenure);

        $lineItems = [];
        foreach ($selectedPlansByModule as $plan) {
            $lineItems[] = [
                'item_type' => 'plan',
                'id' => $plan->id,
                'module' => $plan->module,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->name.' (Renewal · '.$this->tenureLabel($tenure).')',
                'quantity' => 1,
                'unit_annual_price' => (string) $plan->annual_price,
                'amount' => $this->pricing->periodAmountBeforeTenureDiscount((string) $plan->annual_price, $tenure),
                'already_subscribed' => false,
                'currency' => 'INR',
            ];
        }

        $addonResult = $this->priceSelectedAddons(
            addons: $selectedAddons,
            addonQuantities: $addonQuantities,
            selectedModules: array_values($selectedPlansByModule->keys()->map(fn (mixed $k): string => (string) $k)->all()),
            tenure: $tenure,
            prorateActivation: null,
            prorateRenewal: null,
            alreadySubscribedAddonCodes: [],
            isRenewal: true,
        );

        foreach ($addonResult['line_items'] as $item) {
            $lineItems[] = $item;
        }

        $subtotal = DecimalMath::add($rawPeriod, $addonResult['subtotal'], 2);
        $taxableAmount = DecimalMath::add($coreCalc['final_period_amount'], $addonResult['taxable'], 2);
        $discountAmount = DecimalMath::sub($subtotal, $taxableAmount, 2);
        if (DecimalMath::compare($discountAmount, '0', 2) < 0) {
            $discountAmount = '0.00';
        }

        $tax = $this->billingProfiles->computeTaxForCompany($company, $taxableAmount);

        return $this->finalizeQuotePayload(
            company: $company,
            mode: 'renewal',
            purchaseType: 'renewal',
            tenure: $tenure,
            serviceStart: $baseRenewalStart->toDateString(),
            serviceEnd: $newRenewalEnd->toDateString(),
            renewalDate: $newRenewalEnd->toDateString(),
            selectedPlans: $selectedPlansByModule->values(),
            selectedAddons: $addonResult['payable_addons'],
            addonQuantities: $addonResult['payable_quantities'],
            alreadyModules: [],
            alreadyAddons: [],
            lineItems: $lineItems,
            subtotalAmount: $subtotal,
            discountAmount: $discountAmount,
            taxableAmount: $taxableAmount,
            tax: $tax,
            discountDetails: [
                'bundle_percent' => $coreCalc['bundle_percent'],
                'tenure_percent' => $coreCalc['tenure_percent'],
                'renewal_percent' => $coreCalc['renewal_percent'],
                'mid_term_discount_percent' => '0',
                'prorated' => false,
                'proration_days' => null,
            ],
            includedCapacity: $this->buildIncludedCapacity(
                $company,
                $selectedPlansByModule->values(),
                $addonResult['extra_branches'],
                $addonResult['extra_seats'],
                $addonResult['extra_storage_bytes'],
                $addonResult['lr_credits_broker'],
                $addonResult['lr_credits_partload'],
            ),
            payable: true,
            validationMessage: null,
        );
    }

    /**
     * @param  Collection<int, SubscriptionAddon>  $addons
     * @param  array<string, int>  $addonQuantities
     * @param  list<string>  $selectedModules
     * @param  list<string>  $alreadySubscribedAddonCodes
     * @return array{
     *     line_items: list<array<string, mixed>>,
     *     subtotal: string,
     *     taxable: string,
     *     payable_addons: Collection<int, SubscriptionAddon>,
     *     payable_quantities: array<string, int>,
     *     extra_branches: int,
     *     extra_seats: int,
     *     extra_storage_bytes: int,
     *     lr_credits_broker: int,
     *     lr_credits_partload: int
     * }
     */
    private function priceSelectedAddons(
        Collection $addons,
        array $addonQuantities,
        array $selectedModules,
        SubscriptionTenure $tenure,
        ?CarbonImmutable $prorateActivation,
        ?CarbonImmutable $prorateRenewal,
        array $alreadySubscribedAddonCodes,
        bool $isRenewal,
    ): array {
        $lineItems = [];
        $subtotal = '0.00';
        $taxable = '0.00';
        $payableAddons = collect();
        $payableQuantities = [];
        $extraBranches = 0;
        $extraSeats = 0;
        $extraStorageBytes = 0;
        $lrCreditsBroker = 0;
        $lrCreditsPartload = 0;

        foreach ($addons as $addon) {
            if ($addon->code === 'lr_credits_broker' && ! in_array('broker', $selectedModules, true)) {
                continue;
            }
            if ($addon->code === 'lr_credits_partload' && ! in_array('partload', $selectedModules, true)) {
                continue;
            }

            if (! $addon->is_quantifiable && ! $isRenewal && in_array($addon->code, $alreadySubscribedAddonCodes, true)) {
                continue; // Already subscribed non-quantifiable add-on — do not charge again!
            }

            $annualPrice = (string) $addon->annual_price;
            if (DecimalMath::compare($annualPrice, '0', 2) <= 0) {
                throw new SubscriptionDomainException("Add-on \"{$addon->name}\" does not have a valid configured commercial price.");
            }

            $qty = $addon->is_quantifiable
                ? max(1, (int) ($addonQuantities[$addon->id] ?? 1))
                : 1;

            $isCreditPack = $addon->credit_units_per_unit > 0
                && in_array($addon->code, ['lr_credits_broker', 'lr_credits_partload'], true);

            if ($isCreditPack) {
                $net = DecimalMath::mul($annualPrice, (string) $qty, 2);
                $raw = $net;
                $desc = $addon->name.' x '.$qty;
            } elseif ($prorateActivation !== null && $prorateRenewal !== null) {
                $totalAnnual = DecimalMath::mul($annualPrice, (string) $qty, 2);
                $net = $this->pricing->prorateAnnual($totalAnnual, $prorateActivation, $prorateRenewal);
                $raw = $net;
                $days = $prorateActivation->diffInDays($prorateRenewal) + 1;
                $desc = $addon->name.($qty > 1 ? ' x '.$qty : '')." (Prorated · {$days} days)";
            } else {
                $totalAnnual = DecimalMath::mul($annualPrice, (string) $qty, 2);
                $raw = $this->pricing->periodAmountBeforeTenureDiscount($totalAnnual, $tenure);
                $net = $raw;
                $desc = $addon->name.($qty > 1 ? ' x '.$qty : '').' ('.$this->tenureLabel($tenure).')';
            }

            $payableAddons->push($addon);
            $payableQuantities[$addon->id] = $qty;
            $subtotal = DecimalMath::add($subtotal, $raw, 2);
            $taxable = DecimalMath::add($taxable, $net, 2);

            if ($addon->code === 'extra_branch') {
                $extraBranches += $qty;
            } elseif ($addon->code === 'extra_user') {
                $extraSeats += $qty;
            } elseif ($addon->code === 'extra_storage') {
                $extraStorageBytes += $qty * max(0, (int) $addon->storage_bytes_per_unit);
            } elseif ($addon->code === 'lr_credits_broker') {
                $lrCreditsBroker += $qty * (int) $addon->credit_units_per_unit;
            } elseif ($addon->code === 'lr_credits_partload') {
                $lrCreditsPartload += $qty * (int) $addon->credit_units_per_unit;
            }

            $lineItems[] = [
                'item_type' => 'addon',
                'id' => $addon->id,
                'module' => null,
                'code' => $addon->code,
                'name' => $addon->name,
                'description' => $desc,
                'quantity' => $qty,
                'unit_annual_price' => $annualPrice,
                'amount' => $net,
                'already_subscribed' => false,
                'currency' => 'INR',
            ];
        }

        return [
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'taxable' => $taxable,
            'payable_addons' => $payableAddons,
            'payable_quantities' => $payableQuantities,
            'extra_branches' => $extraBranches,
            'extra_seats' => $extraSeats,
            'extra_storage_bytes' => $extraStorageBytes,
            'lr_credits_broker' => $lrCreditsBroker,
            'lr_credits_partload' => $lrCreditsPartload,
        ];
    }

    /**
     * @param  Collection<int, SubscriptionPlan>  $plans
     * @return array<string, mixed>
     */
    private function buildIncludedCapacity(
        Company $company,
        Collection $plans,
        int $extraBranches,
        int $extraSeats,
        int $extraStorageBytes,
        int $lrCreditsBroker,
        int $lrCreditsPartload,
        bool $includeExistingCapacity = false,
    ): array {
        $baseBranches = (int) config('subscription.capacity.included_branches', 2);
        $seatsPerBranch = (int) config('subscription.capacity.seats_per_included_branch', 3);
        $baseSeats = $baseBranches * $seatsPerBranch;
        $baseStorage = (int) config('subscription.storage.default_allocation_bytes', 0);

        $existingBranches = 0;
        $existingSeats = 0;
        $existingStorage = 0;
        if ($includeExistingCapacity) {
            [$existingBranches, $existingSeats, $existingStorage] = $this->capacity->activePurchasedExtras($company);
        }

        $totalExtraBranches = $existingBranches + $extraBranches;
        $totalExtraSeats = $existingSeats + $extraSeats;
        $totalStorageBytes = $baseStorage + $existingStorage + $extraStorageBytes;

        $ownFleetVehicles = 0;
        foreach ($plans as $plan) {
            if ($plan->module === 'ownfleet' && $plan->capacity_units > 0) {
                $ownFleetVehicles += $plan->capacity_units;
            }
        }

        return [
            'included_branches' => $baseBranches,
            'included_seats' => $baseSeats,
            'purchased_branches' => $totalExtraBranches,
            'purchased_seats' => $totalExtraSeats,
            'total_branches' => $baseBranches + $totalExtraBranches,
            'total_users' => $baseSeats + $totalExtraSeats,
            'ownfleet_vehicles' => $ownFleetVehicles > 0 ? $ownFleetVehicles : null,
            'lr_credits_broker' => $lrCreditsBroker,
            'lr_credits_partload' => $lrCreditsPartload,
            'storage_bytes' => $totalStorageBytes,
            'storage_gb' => round($totalStorageBytes / (1024 * 1024 * 1024), 2),
        ];
    }

    /**
     * @param  Collection<int, SubscriptionPlan>  $selectedPlans
     * @param  Collection<int, SubscriptionAddon>  $selectedAddons
     * @param  array<string, int>  $addonQuantities
     * @param  list<array<string, mixed>>  $alreadyModules
     * @param  list<array<string, mixed>>  $alreadyAddons
     * @param  list<array<string, mixed>>  $lineItems
     * @param  array<string, mixed>  $tax
     * @param  array<string, mixed>  $discountDetails
     * @param  array<string, mixed>  $includedCapacity
     * @return array<string, mixed>
     */
    private function finalizeQuotePayload(
        Company $company,
        string $mode,
        string $purchaseType,
        SubscriptionTenure $tenure,
        string $serviceStart,
        string $serviceEnd,
        string $renewalDate,
        Collection $selectedPlans,
        Collection $selectedAddons,
        array $addonQuantities,
        array $alreadyModules,
        array $alreadyAddons,
        array $lineItems,
        string $subtotalAmount,
        string $discountAmount,
        string $taxableAmount,
        array $tax,
        array $discountDetails,
        array $includedCapacity,
        bool $payable,
        ?string $validationMessage,
    ): array {
        return [
            'mode' => $mode,
            'purchase_type' => $purchaseType,
            'payable' => $payable,
            'validation_message' => $validationMessage,
            'currency' => 'INR',
            'tenure' => $tenure->value,
            'tenure_label' => $this->tenureLabel($tenure),
            'service_period_start' => $serviceStart,
            'service_period_end' => $serviceEnd,
            'common_renewal_date' => $renewalDate,
            'payable_plan_ids' => $selectedPlans->pluck('id')->values()->all(),
            'payable_addon_ids' => $selectedAddons->pluck('id')->values()->all(),
            'payable_addon_quantities' => $addonQuantities,
            'already_subscribed_modules' => $alreadyModules,
            'already_subscribed_addons' => $alreadyAddons,
            'line_items' => $lineItems,
            'subtotal_amount' => $subtotalAmount,
            'discount_amount' => $discountAmount,
            'taxable_amount' => $taxableAmount,
            'discount_details' => $discountDetails,
            'included_capacity' => $includedCapacity,
            'gst' => $tax,
            'total_payable' => (string) $tax['grand_total'],
            'billing_profile_configured' => (bool) $tax['configured'],
            'customer_snapshot' => $this->billingProfiles->customerSnapshot($company),
            'breakdown' => array_merge([
                'base_annual' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                'taxable_amount' => $taxableAmount,
                'total_tax_amount' => $tax['total_tax_amount'],
                'final_period_amount' => (string) $tax['grand_total'],
                'purchased_branches' => $includedCapacity['purchased_branches'],
                'purchased_seats' => $includedCapacity['purchased_seats'],
            ], $discountDetails),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyQuote(
        Company $company,
        string $mode,
        SubscriptionTenure $tenure,
        string $start,
        string $end,
        string $message,
    ): array {
        $tax = $this->billingProfiles->computeTaxForCompany($company, '0.00');

        return $this->finalizeQuotePayload(
            company: $company,
            mode: $mode,
            purchaseType: $mode,
            tenure: $tenure,
            serviceStart: $start,
            serviceEnd: $end,
            renewalDate: $end,
            selectedPlans: collect(),
            selectedAddons: collect(),
            addonQuantities: [],
            alreadyModules: [],
            alreadyAddons: [],
            lineItems: [],
            subtotalAmount: '0.00',
            discountAmount: '0.00',
            taxableAmount: '0.00',
            tax: $tax,
            discountDetails: [
                'bundle_percent' => '0',
                'tenure_percent' => $tenure->discountPercent(),
                'renewal_percent' => '0',
                'mid_term_discount_percent' => '0',
                'prorated' => false,
                'proration_days' => null,
            ],
            includedCapacity: $this->buildIncludedCapacity($company, collect(), 0, 0, 0, 0, 0, true),
            payable: false,
            validationMessage: $message,
        );
    }

    private function activeCatalogueOrFail(): SubscriptionCatalogueVersion
    {
        $catalogue = SubscriptionCatalogueVersion::query()
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->first();

        if ($catalogue === null) {
            throw new SubscriptionDomainException('No active subscription catalogue is configured.');
        }

        return $catalogue;
    }

    private function activeSubscription(Company $company): ?CompanySubscription
    {
        if (! $company->exists) {
            return null;
        }

        return CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNotNull('activated_at')
            ->whereNull('deleted_at')
            ->with(['items.addon'])
            ->orderByDesc('activated_at')
            ->first();
    }

    private function tenureLabel(SubscriptionTenure $tenure): string
    {
        return match ($tenure) {
            SubscriptionTenure::Monthly => 'Monthly (1 month)',
            SubscriptionTenure::Quarterly => 'Quarterly (3 months)',
            SubscriptionTenure::HalfYearly => 'Half-Yearly (6 months)',
            SubscriptionTenure::Yearly => 'Yearly (12 months)',
            SubscriptionTenure::ThreeYearFullAdvance => '3-Year Full Advance',
            SubscriptionTenure::ThreeYearAnnualInstalments => '3-Year Annual Instalments',
        };
    }
}
