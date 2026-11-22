<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Subscription\Capacity\CompanyCapacityService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Payments\CheckoutVerificationService;
use App\Domains\Subscription\Payments\Gateway\FakePaymentGateway;
use App\Domains\Subscription\Payments\Gateway\PaymentGateway;
use App\Domains\Subscription\Payments\Gateway\PaymentGatewayAuthorization;
use App\Domains\Subscription\Payments\Gateway\RazorpayTestPaymentGateway;
use App\Domains\Subscription\Payments\PaymentIntentService;
use App\Domains\Subscription\Pricing\SubscriptionPricingCalculator;
use App\Domains\Subscription\Storage\StorageReconciliationService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\PaymentIntent;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionCreditEntry;
use App\Models\SubscriptionCreditLedger;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner subscription overview, catalogue pricing, drafts and credits (PO-P3-UI-AUTH).
 * Live Razorpay checkout remains gated (PO-P3-PAY / G-PAY). Confirmed entitlement rules only.
 */
class OwnerSubscriptionController extends Controller
{
    use AuthorizesOwnerPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly SubscriptionPricingCalculator $pricing,
        private readonly CompanySubscriptionContractService $contracts,
        private readonly PaymentIntentService $paymentIntents,
        private readonly CheckoutVerificationService $checkout,
        private readonly PaymentGateway $gateway,
        private readonly PaymentGatewayAuthorization $paymentAuth,
        private readonly SubscriptionEntitlementGate $entitlements = new SubscriptionEntitlementGate,
        private readonly SubscriptionLifecycleService $lifecycle = new SubscriptionLifecycleService,
        private readonly CompanyCapacityService $capacity = new CompanyCapacityService,
        private readonly StorageReconciliationService $storage = new StorageReconciliationService,
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

        $activeOrDraft = $subscriptions->first(fn (CompanySubscription $s): bool => in_array($s->status, ['active', 'draft', 'pending_payment'], true));
        $active = $subscriptions->first(fn (CompanySubscription $s): bool => $s->status === 'active');

        $ledgers = SubscriptionCreditLedger::query()
            ->where('company_id', $company->id)
            ->orderBy('ledger_type')
            ->get();

        $entitlement = $this->entitlements->evaluate($company);
        $effective = $this->lifecycle->effectiveStatus($company);
        $capacity = $this->capacity->evaluate($company);
        $storage = $this->storage->reconcile($company);

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
            'lifecycle' => [
                'status' => $entitlement['status']->value,
                'company_admin_active' => $entitlement['company_admin_active'],
                'branch_mutations_allowed' => $entitlement['branch_mutations_allowed'],
                'owner_billing_allowed' => $entitlement['owner_billing_allowed'],
                'restriction_reason' => $entitlement['restriction_reason'],
                'matrix_authority' => $entitlement['matrix_authority'],
                'notes' => $entitlement['notes'],
                'trial_ends_at' => $effective['trial_ends_at'] ?? null,
                'paid_through_date' => $effective['paid_through_date'] ?? null,
                'grace_ends_on' => $effective['grace_ends_on'] ?? null,
                'cancellation_effective_on' => $effective['cancellation_effective_on'] ?? null,
            ],
            'capacity' => $capacity,
            'storage' => [
                'counted_bytes' => $storage['counted_bytes'],
                'alert' => $storage['alert'],
                'block' => $storage['block'],
                'percent_of_allocation' => $storage['percent_of_allocation'],
            ],
            'cancellation' => $active === null ? null : [
                'subscription_id' => $active->id,
                'pending' => $active->cancellation_requested_at !== null && $active->cancelled_at === null,
                'effective_on' => $active->cancellation_effective_on?->toDateString(),
                'remark' => $active->cancellation_remark,
            ],
            'credit_summary' => $ledgers->map(fn (SubscriptionCreditLedger $ledger): array => [
                'id' => $ledger->id,
                'ledger_type' => $ledger->ledger_type,
                'service_key' => $ledger->service_key,
                'balance' => (string) $ledger->balance,
                'unit' => $this->creditUnitLabel($ledger->ledger_type),
            ])->values()->all(),
            'entitlement_note' => 'Confirmed Rules §15 lifecycle enforcement is active for Branch mutations during paid grace/restricted states. Fine-grained AUD-CON-006 action cells remain Pending. Live Razorpay checkout remains gated (PO-P3-PAY / G-PAY).',
            'payment_gate_note' => 'Manual Razorpay Test Mode and production charges require PO-P3-PAY / G-PAY. Simulated payment foundations exist for certification doubles only.',
            'links' => [
                'dashboard' => route('owner.dashboard', $company),
                'catalogue' => route('owner.subscription.catalogue', $company),
                'drafts' => route('owner.subscription.drafts', $company),
                'credits' => route('owner.subscription.credits', $company),
                'payments' => route('owner.subscription.payments', $company),
                'invoices' => route('owner.subscription.invoices', $company),
                'cancel' => route('owner.subscription.cancel', $company),
                'withdraw_cancel' => route('owner.subscription.cancel.withdraw', $company),
                'checkout' => $activeOrDraft !== null
                    ? route('owner.subscription.checkout', [$company, $activeOrDraft])
                    : null,
                'renew' => route('owner.subscription.renew', $company),
            ],
            'payment_gateway' => $this->gateway->name(),
            'razorpay_test_ready' => $this->paymentAuth->isRazorpayTestModeAvailable(),
        ]);
    }

    public function checkout(Request $request, Company $company, CompanySubscription $subscription): Response|RedirectResponse
    {
        ['user' => $user, 'scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertCompanySubscription($company, $subscription);

        if (! in_array($subscription->status, ['draft', 'pending_payment', 'active'], true)) {
            return redirect()
                ->route('owner.subscription', $company)
                ->withErrors(['checkout' => 'Subscription is not payable in its current status.']);
        }

        $purpose = $subscription->status === 'active' ? 'renewal' : 'subscription_activation';
        $amount = (string) $subscription->discounted_annual_total;

        try {
            $intent = $this->paymentIntents->create(
                company: $company,
                purpose: $purpose,
                amount: $amount,
                idempotencyKey: 'checkout:'.$subscription->id.':'.$purpose.':'.$amount,
                subscription: $subscription,
            );
        } catch (SubscriptionDomainException $e) {
            return redirect()
                ->route('owner.subscription', $company)
                ->withErrors(['checkout' => $e->getMessage()]);
        }

        if ($subscription->status === 'draft') {
            $subscription->forceFill(['status' => 'pending_payment'])->save();
        }

        $publicKey = null;
        $mode = $this->gateway->name();
        if ($this->gateway instanceof RazorpayTestPaymentGateway) {
            $publicKey = $this->gateway->publicKeyId();
        }

        return Inertia::render('owner/subscription/checkout', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'test_mode_banner' => 'Razorpay Test Mode — No Real Money Will Be Charged',
            'gateway' => $mode,
            'public_key' => $publicKey,
            'subscription' => $this->subscriptionPayload($subscription->load('items')),
            'payment' => [
                'id' => $intent->id,
                'purpose' => $intent->purpose,
                'amount' => (string) $intent->amount,
                'currency' => $intent->currency,
                'status' => $intent->status,
                'gateway_order_id' => $intent->gateway_order_id,
            ],
            'prefill' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'links' => [
                'overview' => route('owner.subscription', $company),
                'verify' => route('owner.subscription.checkout.verify', [$company, $subscription]),
                'simulate' => route('owner.subscription.checkout.simulate', [$company, $subscription]),
            ],
        ]);
    }

    public function verifyCheckout(Request $request, Company $company, CompanySubscription $subscription): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertCompanySubscription($company, $subscription);

        $validated = $request->validate([
            'payment_intent_id' => ['required', 'uuid'],
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $intent = PaymentIntent::query()->whereKey($validated['payment_intent_id'])->firstOrFail();
        if ($intent->company_subscription_id !== $subscription->id) {
            abort(404);
        }

        try {
            $this->checkout->verifyOwnerCallback($user, $company, $intent, $validated);
        } catch (SubscriptionDomainException $e) {
            return redirect()
                ->route('owner.subscription.checkout', [$company, $subscription])
                ->withErrors(['checkout' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.subscription', $company)
            ->with('success', 'Payment verified. Subscription updated from server-side verification.');
    }

    /**
     * Deterministic fake-gateway capture for local/CI when Razorpay credentials are absent.
     */
    public function simulateCheckout(Request $request, Company $company, CompanySubscription $subscription): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertCompanySubscription($company, $subscription);

        if (! $this->gateway instanceof FakePaymentGateway) {
            abort(403, 'Simulate capture is only available with the fake gateway.');
        }

        $validated = $request->validate([
            'payment_intent_id' => ['required', 'uuid'],
        ]);

        $intent = PaymentIntent::query()->whereKey($validated['payment_intent_id'])->firstOrFail();
        if ($intent->company_subscription_id !== $subscription->id || $intent->company_id !== $company->id) {
            abort(404);
        }

        $paymentId = 'pay_fake_'.strtolower(str()->random(10));
        $orderId = (string) $intent->gateway_order_id;
        $signature = hash_hmac('sha256', $orderId.'|'.$paymentId, $this->gateway->secret());

        try {
            $this->checkout->verifyOwnerCallback($user, $company, $intent, [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'signature' => $signature,
            ]);
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['checkout' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.subscription', $company)
            ->with('success', 'Simulated Test Mode capture verified (fake gateway).');
    }

    public function renew(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $validated = $request->validate([
            'subscription_id' => ['required', 'uuid'],
        ]);

        $subscription = CompanySubscription::query()->whereKey($validated['subscription_id'])->firstOrFail();
        $this->assertCompanySubscription($company, $subscription);

        if ($subscription->status !== 'active') {
            return back()->withErrors(['subscription_id' => 'Only active subscriptions can be renewed.']);
        }

        return redirect()->route('owner.subscription.checkout', [$company, $subscription]);
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

    public function requestCancellation(Request $request, Company $company): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $validated = $request->validate([
            'subscription_id' => ['required', 'uuid'],
            'remark' => ['required', 'string', 'max:500'],
        ]);

        $subscription = CompanySubscription::query()->whereKey($validated['subscription_id'])->firstOrFail();
        $this->assertCompanySubscription($company, $subscription);

        try {
            $this->lifecycle->requestCancellation($user, $subscription, $validated['remark']);
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.subscription', $company)
            ->with('success', 'Cancellation scheduled for the common renewal date.');
    }

    public function withdrawCancellation(Request $request, Company $company): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $validated = $request->validate([
            'subscription_id' => ['required', 'uuid'],
            'remark' => ['required', 'string', 'max:500'],
        ]);

        $subscription = CompanySubscription::query()->whereKey($validated['subscription_id'])->firstOrFail();
        $this->assertCompanySubscription($company, $subscription);

        try {
            $this->lifecycle->withdrawCancellation($user, $subscription, $validated['remark']);
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.subscription', $company)
            ->with('success', 'Pending cancellation withdrawn.');
    }

    public function payments(Request $request, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $intents = PaymentIntent::query()
            ->where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('owner/subscription/payments', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'gate_note' => 'Live Razorpay checkout is not enabled. This list shows company-scoped payment intents including simulated records. PO-P3-PAY / G-PAY required for Test Mode charges.',
            'payments' => $intents->map(fn (PaymentIntent $intent): array => [
                'id' => $intent->id,
                'purpose' => $intent->purpose,
                'amount' => (string) $intent->amount,
                'currency' => $intent->currency,
                'status' => $intent->status,
                'verification_status' => $intent->verification_status,
                'gateway' => $intent->gateway,
                'gateway_order_id' => $intent->gateway_order_id,
                'gateway_payment_id' => $intent->gateway_payment_id,
                'created_at' => $intent->created_at?->toIso8601String(),
            ])->values()->all(),
            'links' => [
                'overview' => route('owner.subscription', $company),
            ],
        ]);
    }

    public function invoices(Request $request, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $invoices = SubscriptionInvoice::query()
            ->where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('owner/subscription/invoices', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'issuer_note' => 'Production legally operative invoice issuance requires validated issuer/tax configuration. Invoice-number approval workflow foundations exist; Super Admin approval UI remains operationally gated pending issuer setup.',
            'invoices' => $invoices->map(fn (SubscriptionInvoice $invoice): array => [
                'id' => $invoice->id,
                'status' => $invoice->status,
                'proposed_number' => $invoice->proposed_number,
                'approved_number' => $invoice->approved_number,
                'financial_year' => $invoice->financial_year,
                'amount' => (string) $invoice->amount,
                'currency' => $invoice->currency,
                'email_status' => $invoice->email_status,
                'approved_at' => $invoice->approved_at?->toIso8601String(),
                'created_at' => $invoice->created_at?->toIso8601String(),
            ])->values()->all(),
            'links' => [
                'overview' => route('owner.subscription', $company),
            ],
        ]);
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
            'paid_through_date' => $subscription->paid_through_date?->toDateString(),
            'grace_ends_on' => $subscription->grace_ends_on?->toDateString(),
            'cancellation_requested_at' => $subscription->cancellation_requested_at?->toIso8601String(),
            'cancellation_effective_on' => $subscription->cancellation_effective_on?->toDateString(),
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
