<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Pricing\SubscriptionPricingCalculator;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionCreditEntry;
use App\Models\SubscriptionCreditLedger;
use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner subscription overview, catalogue pricing, drafts and credits (PO-P3-UI-AUTH).
 * Does not activate payments or invent AUD-CON-006 entitlements.
 */
class OwnerSubscriptionController extends Controller
{
    use AuthorizesOwnerPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly SubscriptionPricingCalculator $pricing,
        private readonly CompanySubscriptionContractService $contracts,
    ) {}

    public function overview(Request $request, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $catalogue = SubscriptionCatalogueVersion::query()
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->first();

        $subscriptions = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->with(['items'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $activeOrDraft = $subscriptions->first(fn (CompanySubscription $s): bool => in_array($s->status, ['active', 'draft'], true));

        $ledgers = SubscriptionCreditLedger::query()
            ->where('company_id', $company->id)
            ->orderBy('ledger_type')
            ->get();

        return Inertia::render('owner/subscription/overview', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'canManage' => $scope === CompanyAccessService::OWNER_PANEL_FULL,
            'catalogue' => $catalogue === null ? null : [
                'id' => $catalogue->id,
                'code' => $catalogue->code,
                'name' => $catalogue->name,
                'effective_from' => $catalogue->effective_from?->toDateString(),
            ],
            'subscription' => $activeOrDraft === null ? null : $this->subscriptionPayload($activeOrDraft),
            'subscriptions' => $subscriptions->map(fn (CompanySubscription $s): array => $this->subscriptionPayload($s))->values()->all(),
            'credit_summary' => $ledgers->map(fn (SubscriptionCreditLedger $ledger): array => [
                'id' => $ledger->id,
                'ledger_type' => $ledger->ledger_type,
                'service_key' => $ledger->service_key,
                'balance' => (string) $ledger->balance,
                'unit' => $this->creditUnitLabel($ledger->ledger_type),
            ])->values()->all(),
            'entitlement_note' => 'Trial, grace, restricted and suspended entitlement matrices remain Pending (AUD-CON-006). This screen shows configured contract and credit data only.',
            'links' => [
                'dashboard' => route('owner.dashboard', $company),
                'catalogue' => route('owner.subscription.catalogue', $company),
                'drafts' => route('owner.subscription.drafts', $company),
                'credits' => route('owner.subscription.credits', $company),
            ],
        ]);
    }

    public function catalogue(Request $request, Company $company): Response
    {
        ['user' => $user, 'scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $catalogue = $this->activeCatalogue();
        $plans = $catalogue === null
            ? collect()
            : SubscriptionPlan::query()
                ->where('catalogue_version_id', $catalogue->id)
                ->where('is_active', true)
                ->orderBy('module')
                ->orderBy('annual_price')
                ->get();
        $addons = $catalogue === null
            ? collect()
            : SubscriptionAddon::query()
                ->where('catalogue_version_id', $catalogue->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

        $selectedPlanIds = array_values(array_filter((array) $request->input('plan_ids', [])));
        $tenureValue = (string) $request->input('tenure', SubscriptionTenure::Yearly->value);
        $tenure = SubscriptionTenure::tryFrom($tenureValue) ?? SubscriptionTenure::Yearly;
        $renewalEligible = $request->boolean('renewal_eligible');
        $couponPercent = $request->filled('coupon_percent') ? (string) $request->input('coupon_percent') : null;
        $couponFixed = $request->filled('coupon_fixed') ? (string) $request->input('coupon_fixed') : null;

        $breakdown = null;
        if ($selectedPlanIds !== [] && $catalogue !== null) {
            $selectedPlans = $plans->whereIn('id', $selectedPlanIds);
            if ($selectedPlans->count() === count(array_unique($selectedPlanIds))) {
                $prices = array_values($selectedPlans->pluck('annual_price')->map(fn ($p) => (string) $p)->all());
                $breakdown = $this->pricing->calculate(
                    coreModuleAnnualPrices: $prices,
                    tenure: $tenure,
                    renewalEligible: $renewalEligible,
                    couponPercent: $couponPercent,
                    couponFixed: $couponFixed,
                );
                $breakdown['display_list_prices'] = $selectedPlans->map(fn (SubscriptionPlan $plan): array => [
                    'id' => $plan->id,
                    'code' => $plan->code,
                    'catalogue_annual' => (string) $plan->annual_price,
                    'display_list' => $this->pricing->displayListPrice((string) $plan->annual_price),
                ])->values()->all();
            }
        }

        return Inertia::render('owner/subscription/catalogue', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'canManage' => $scope === CompanyAccessService::OWNER_PANEL_FULL && $company->isOwnedBy($user),
            'catalogue' => $catalogue === null ? null : [
                'id' => $catalogue->id,
                'code' => $catalogue->code,
                'name' => $catalogue->name,
            ],
            'plans' => $plans->map(fn (SubscriptionPlan $plan): array => [
                'id' => $plan->id,
                'module' => $plan->module,
                'code' => $plan->code,
                'name' => $plan->name,
                'annual_price' => (string) $plan->annual_price,
                'display_list_price' => $this->pricing->displayListPrice((string) $plan->annual_price),
                'capacity_units' => $plan->capacity_units,
            ])->values()->all(),
            'addons' => $addons->map(fn (SubscriptionAddon $addon): array => [
                'id' => $addon->id,
                'code' => $addon->code,
                'name' => $addon->name,
                'annual_price' => (string) $addon->annual_price,
            ])->values()->all(),
            'tenureOptions' => collect(SubscriptionTenure::cases())->map(fn (SubscriptionTenure $t): array => [
                'value' => $t->value,
                'label' => str_replace('_', ' ', ucfirst($t->value)),
                'months' => $t->months(),
                'discount_percent' => $t->discountPercent(),
            ])->values()->all(),
            'selection' => [
                'plan_ids' => $selectedPlanIds,
                'tenure' => $tenure->value,
                'renewal_eligible' => $renewalEligible,
                'coupon_percent' => $couponPercent,
                'coupon_fixed' => $couponFixed,
            ],
            'breakdown' => $breakdown,
            'gst_note' => 'GST presentation follows confirmed catalogue pricing; internal tax administration UI is not in this scope.',
            'links' => [
                'overview' => route('owner.subscription', $company),
                'calculate' => route('owner.subscription.catalogue', $company),
                'create_draft' => route('owner.subscription.drafts.store', $company),
                'drafts' => route('owner.subscription.drafts', $company),
            ],
        ]);
    }

    public function drafts(Request $request, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $drafts = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'draft')
            ->with(['items', 'snapshots'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('owner/subscription/drafts', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'canManage' => $scope === CompanyAccessService::OWNER_PANEL_FULL,
            'drafts' => $drafts->map(fn (CompanySubscription $draft): array => $this->subscriptionPayload($draft, includeSnapshots: true))->values()->all(),
            'activation_note' => 'Draft contracts do not activate a subscription. Live payment and issuance remain gated (PO-P3-PAY).',
            'links' => [
                'overview' => route('owner.subscription', $company),
                'catalogue' => route('owner.subscription.catalogue', $company),
                'store' => route('owner.subscription.drafts.store', $company),
            ],
            'editLinks' => $drafts->mapWithKeys(fn (CompanySubscription $draft): array => [
                $draft->id => route('owner.subscription.drafts.edit', [$company, $draft]),
            ])->all(),
        ]);
    }

    public function storeDraft(Request $request, Company $company): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $validated = $request->validate([
            'catalogue_version_id' => ['required', 'uuid'],
            'plan_ids' => ['required', 'array', 'min:1'],
            'plan_ids.*' => ['uuid'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['uuid'],
            'tenure' => ['required', Rule::enum(SubscriptionTenure::class)],
            'common_renewal_date' => ['required', 'date'],
            'renewal_eligible' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $catalogue = SubscriptionCatalogueVersion::query()->whereKey($validated['catalogue_version_id'])->firstOrFail();

        try {
            $draft = $this->contracts->createDraftContract(
                actor: $user,
                company: $company,
                catalogue: $catalogue,
                planIds: $validated['plan_ids'],
                tenure: SubscriptionTenure::from($validated['tenure']),
                commonRenewalDate: CarbonImmutable::parse($validated['common_renewal_date']),
                renewalEligible: (bool) ($validated['renewal_eligible'] ?? false),
                addonIds: $validated['addon_ids'] ?? [],
                remark: $validated['remark'] ?? 'Create subscription draft contract',
            );
        } catch (SubscriptionDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('owner.subscription.drafts', $company)
            ->with('success', 'Draft contract created (not activated).');
    }

    public function editDraft(Request $request, Company $company, CompanySubscription $subscription): Response
    {
        ['user' => $user, 'scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);
        $this->assertCompanySubscription($company, $subscription);

        if ($subscription->status !== 'draft') {
            abort(403, 'Only draft contracts may be edited.');
        }

        $subscription->load(['items', 'snapshots']);

        $catalogue = SubscriptionCatalogueVersion::query()
            ->whereKey($subscription->catalogue_version_id)
            ->firstOrFail();

        $plans = SubscriptionPlan::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('is_active', true)
            ->orderBy('module')
            ->orderBy('annual_price')
            ->get();

        $addons = SubscriptionAddon::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $selectedPlanIds = array_values(array_filter((array) $request->input(
            'plan_ids',
            $subscription->items->where('item_type', 'plan')->pluck('plan_id')->filter()->values()->all(),
        )));
        $selectedAddonIds = array_values(array_filter((array) $request->input(
            'addon_ids',
            $subscription->items->where('item_type', 'addon')->pluck('addon_id')->filter()->values()->all(),
        )));
        $tenureValue = (string) $request->input('tenure', $subscription->tenure);
        $tenure = SubscriptionTenure::tryFrom($tenureValue) ?? SubscriptionTenure::from((string) $subscription->tenure);
        $renewalEligible = $request->has('renewal_eligible')
            ? $request->boolean('renewal_eligible')
            : (bool) $subscription->renewal_eligible;

        $previewBreakdown = null;
        if ($selectedPlanIds !== []) {
            $selectedPlans = $plans->whereIn('id', $selectedPlanIds);
            if ($selectedPlans->count() === count(array_unique($selectedPlanIds))) {
                $prices = array_values($selectedPlans->pluck('annual_price')->map(fn ($p) => (string) $p)->all());
                $previewBreakdown = $this->pricing->calculate(
                    coreModuleAnnualPrices: $prices,
                    tenure: $tenure,
                    renewalEligible: $renewalEligible,
                );
            }
        }

        $canManage = $scope === CompanyAccessService::OWNER_PANEL_FULL && $company->isOwnedBy($user);

        return Inertia::render('owner/subscription/draft-edit', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'canManage' => $canManage,
            'catalogue' => [
                'id' => $catalogue->id,
                'code' => $catalogue->code,
                'name' => $catalogue->name,
            ],
            'draft' => $this->subscriptionPayload($subscription, includeSnapshots: true),
            'plans' => $plans->map(fn (SubscriptionPlan $plan): array => [
                'id' => $plan->id,
                'module' => $plan->module,
                'code' => $plan->code,
                'name' => $plan->name,
                'annual_price' => (string) $plan->annual_price,
                'display_list_price' => $this->pricing->displayListPrice((string) $plan->annual_price),
                'capacity_units' => $plan->capacity_units,
            ])->values()->all(),
            'addons' => $addons->map(fn (SubscriptionAddon $addon): array => [
                'id' => $addon->id,
                'code' => $addon->code,
                'name' => $addon->name,
                'annual_price' => (string) $addon->annual_price,
            ])->values()->all(),
            'tenureOptions' => collect(SubscriptionTenure::cases())->map(fn (SubscriptionTenure $t): array => [
                'value' => $t->value,
                'label' => str_replace('_', ' ', ucfirst($t->value)),
                'months' => $t->months(),
                'discount_percent' => $t->discountPercent(),
            ])->values()->all(),
            'selection' => [
                'plan_ids' => $selectedPlanIds,
                'addon_ids' => $selectedAddonIds,
                'tenure' => $tenure->value,
                'renewal_eligible' => $renewalEligible,
                'common_renewal_date' => $request->input(
                    'common_renewal_date',
                    $subscription->common_renewal_date?->toDateString(),
                ),
            ],
            'before_breakdown' => $subscription->pricing_breakdown,
            'preview_breakdown' => $previewBreakdown,
            'edit_note' => 'Editing updates the draft only. Catalogue version is locked to this draft. Payment and activation remain gated (PO-P3-PAY).',
            'links' => [
                'drafts' => route('owner.subscription.drafts', $company),
                'preview' => route('owner.subscription.drafts.edit', [$company, $subscription]),
                'update' => route('owner.subscription.drafts.update', [$company, $subscription]),
                'overview' => route('owner.subscription', $company),
            ],
        ]);
    }

    public function updateDraft(Request $request, Company $company, CompanySubscription $subscription): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertCompanySubscription($company, $subscription);

        $validated = $request->validate([
            'plan_ids' => ['required', 'array', 'min:1'],
            'plan_ids.*' => ['uuid'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['uuid'],
            'tenure' => ['required', Rule::enum(SubscriptionTenure::class)],
            'common_renewal_date' => ['required', 'date'],
            'renewal_eligible' => ['sometimes', 'boolean'],
            'expected_snapshot_count' => ['required', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->contracts->updateDraftContract(
                actor: $user,
                subscription: $subscription,
                planIds: $validated['plan_ids'],
                tenure: SubscriptionTenure::from($validated['tenure']),
                commonRenewalDate: CarbonImmutable::parse($validated['common_renewal_date']),
                renewalEligible: (bool) ($validated['renewal_eligible'] ?? false),
                addonIds: $validated['addon_ids'] ?? [],
                remark: $validated['remark'] ?? 'Update subscription draft contract',
                expectedSnapshotCount: (int) $validated['expected_snapshot_count'],
            );
        } catch (SubscriptionDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('owner.subscription.drafts.edit', [$company, $updated])
            ->with('success', 'Draft contract updated (not activated).');
    }

    public function credits(Request $request, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $ledgers = SubscriptionCreditLedger::query()
            ->where('company_id', $company->id)
            ->orderBy('ledger_type')
            ->orderBy('service_key')
            ->get();

        $entries = SubscriptionCreditEntry::query()
            ->where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('owner/subscription/credits', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'ledgers' => $ledgers->map(fn (SubscriptionCreditLedger $ledger): array => [
                'id' => $ledger->id,
                'ledger_type' => $ledger->ledger_type,
                'service_key' => $ledger->service_key,
                'balance' => (string) $ledger->balance,
                'unit' => $this->creditUnitLabel($ledger->ledger_type),
                'is_cash_wallet' => false,
            ])->values()->all(),
            'entries' => $entries->map(fn (SubscriptionCreditEntry $entry): array => [
                'id' => $entry->id,
                'ledger_id' => $entry->ledger_id,
                'amount' => (string) $entry->amount,
                'entry_type' => $entry->entry_type,
                'reason' => $entry->reason,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->values()->all(),
            'presentation_note' => 'Credits are non-cash balances in their ledger units. They are never shown as a generic ₹ cash wallet.',
            'links' => [
                'overview' => route('owner.subscription', $company),
            ],
        ]);
    }

    private function activeCatalogue(): ?SubscriptionCatalogueVersion
    {
        return SubscriptionCatalogueVersion::query()
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(CompanySubscription $subscription, bool $includeSnapshots = false): array
    {
        $payload = [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'catalogue_version_id' => $subscription->catalogue_version_id,
            'tenure' => $subscription->tenure,
            'common_renewal_date' => $subscription->common_renewal_date?->toDateString(),
            'renewal_eligible' => (bool) $subscription->renewal_eligible,
            'core_module_count' => $subscription->core_module_count,
            'list_annual_total' => (string) $subscription->list_annual_total,
            'discounted_annual_total' => (string) $subscription->discounted_annual_total,
            'pricing_breakdown' => $subscription->pricing_breakdown,
            'updated_at' => $subscription->updated_at?->toIso8601String(),
            'snapshot_count' => $subscription->relationLoaded('snapshots')
                ? $subscription->snapshots->count()
                : $subscription->snapshots()->count(),
            'items' => $subscription->relationLoaded('items')
                ? $subscription->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'item_type' => $item->item_type,
                    'plan_id' => $item->plan_id,
                    'addon_id' => $item->addon_id,
                    'module' => $item->module,
                    'code' => $item->code,
                    'name' => $item->name,
                    'annual_price_snapshot' => (string) $item->annual_price_snapshot,
                    'capacity_units_snapshot' => $item->capacity_units_snapshot,
                ])->values()->all()
                : [],
        ];

        if ($includeSnapshots && $subscription->relationLoaded('snapshots')) {
            $payload['snapshots'] = $subscription->snapshots->map(fn ($snapshot): array => [
                'id' => $snapshot->id,
                'reason' => $snapshot->reason,
                'created_at' => $snapshot->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $payload;
    }

    private function assertCompanySubscription(Company $company, CompanySubscription $subscription): void
    {
        if ($subscription->company_id !== $company->id) {
            abort(404);
        }
    }

    private function creditUnitLabel(string $ledgerType): string
    {
        return match ($ledgerType) {
            'storage_gb' => 'GB storage',
            'sms' => 'SMS credits',
            'whatsapp' => 'WhatsApp credits',
            'capacity' => 'Capacity units',
            default => $ledgerType.' units',
        };
    }
}
