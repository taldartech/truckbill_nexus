<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceDocumentService;
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
use App\Domains\Subscription\Quota\QuotaAllocationService;
use App\Domains\Subscription\Storage\StorageReconciliationService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Company;
use App\Models\CompanyQuotaCounter;
use App\Models\CompanySubscription;
use App\Models\PaymentIntent;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionChangeProposal;
use App\Models\SubscriptionCreditEntry;
use App\Models\SubscriptionCreditLedger;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
        private readonly QuotaAllocationService $quotas = new QuotaAllocationService,
        private readonly SubscriptionInvoiceDocumentService $invoiceDocuments = new SubscriptionInvoiceDocumentService,
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
        [$purchasedBranches, $purchasedSeats] = $this->purchasedCapacityFromSubscription($active);
        $capacity = $this->capacity->evaluate($company, $purchasedBranches, $purchasedSeats);
        $vehicleCapacity = $this->vehicleCapacityFromSubscription($active);
        $allocationBytes = (int) config('subscription.storage.default_allocation_bytes', 0);
        $storage = $this->storage->reconcile($company, $allocationBytes > 0 ? $allocationBytes : null);
        $quotaCounters = CompanyQuotaCounter::query()
            ->where('company_id', $company->id)
            ->orderBy('quota_type')
            ->get();
        $scheduledChanges = SubscriptionChangeProposal::query()
            ->where('company_id', $company->id)
            ->where('status', 'scheduled')
            ->orderBy('effective_on')
            ->limit(20)
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
                'common_renewal_date' => $active?->common_renewal_date?->toDateString(),
            ],
            'capacity' => array_merge($capacity, [
                'vehicle_capacity' => $vehicleCapacity,
                'utilized_vehicle_capacity' => $capacity['used_vehicles'],
            ]),
            'storage' => [
                'allocated_bytes' => $allocationBytes > 0 ? $allocationBytes : null,
                'used_bytes' => $storage['counted_bytes'],
                'remaining_bytes' => $allocationBytes > 0
                    ? max(0, $allocationBytes - $storage['counted_bytes'])
                    : null,
                'counted_bytes' => $storage['counted_bytes'],
                'alert' => $storage['alert'],
                'block' => $storage['block'],
                'percent_of_allocation' => $storage['percent_of_allocation'],
                'threshold_warning' => $storage['alert'] || $storage['block'],
            ],
            'quotas' => $this->quotaPayloads($quotaCounters),
            'scheduled_changes' => $this->scheduledChangePayloads($scheduledChanges),
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
            'entitlement_note' => 'Branch changes remain limited while a paid subscription is in grace or restricted status.',
            'payment_gate_note' => 'Checkout uses Razorpay test mode in this environment. Live charges are not enabled yet.',
            'links' => [
                'dashboard' => route('owner.home'),
                'catalogue' => route('owner.subscription.catalogue'),
                'drafts' => route('owner.subscription.drafts'),
                'credits' => route('owner.subscription.credits'),
                'payments' => route('owner.subscription.payments'),
                'invoices' => route('owner.subscription.invoices'),
                'cancel' => route('owner.subscription.cancel'),
                'withdraw_cancel' => route('owner.subscription.cancel.withdraw'),
                'checkout' => $activeOrDraft !== null
                    ? route('owner.subscription.checkout', $activeOrDraft)
                    : null,
                'renew' => route('owner.subscription.renew'),
            ],
            'payment_gateway' => $this->gateway->name(),
            'razorpay_test_ready' => $this->paymentAuth->isRazorpayTestModeAvailable(),
        ]);
    }

    public function checkout(Request $request, CompanySubscription $subscription, Company $company): Response|RedirectResponse
    {
        ['user' => $user, 'scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertCompanySubscription($company, $subscription);

        if (! in_array($subscription->status, ['draft', 'pending_payment', 'active'], true)) {
            return redirect()
                ->route('owner.subscription')
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
                ->route('owner.subscription')
                ->withErrors(['checkout' => $e->getMessage()]);
        }

        if ($subscription->status === 'draft') {
            $subscription->forceFill(['status' => 'pending_payment'])->save();
        }

        $mode = $this->gateway->name();
        $publicKey = null;
        $allowFakeSimulate = app()->environment('testing');

        if ($mode === 'fake' && ! $allowFakeSimulate) {
            return redirect()
                ->route('owner.subscription')
                ->withErrors([
                    'checkout' => 'Razorpay Test Mode credentials are required for Owner checkout. Configure RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET, and RAZORPAY_WEBHOOK_SECRET (rzp_test_* only).',
                ]);
        }

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
            'allow_fake_simulate' => $allowFakeSimulate,
            'links' => [
                'overview' => route('owner.subscription'),
                'verify' => route('owner.subscription.checkout.verify', $subscription),
                'simulate' => $allowFakeSimulate
                    ? route('owner.subscription.checkout.simulate', $subscription)
                    : null,
            ],
        ]);
    }

    public function verifyCheckout(Request $request, CompanySubscription $subscription, Company $company): RedirectResponse
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
                ->route('owner.subscription.checkout', $subscription)
                ->withErrors(['checkout' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.subscription')
            ->with('success', 'Payment verified. Subscription updated from server-side verification.');
    }

    /**
     * Deterministic fake-gateway capture for local/CI when Razorpay credentials are absent.
     */
    public function simulateCheckout(Request $request, CompanySubscription $subscription, Company $company): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertCompanySubscription($company, $subscription);

        if (! app()->environment('testing')) {
            abort(403, 'Fake payment simulation is restricted to the testing environment.');
        }

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
            ->route('owner.subscription')
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

        return redirect()->route('owner.subscription.checkout', $subscription);
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
                'overview' => route('owner.subscription'),
                'calculate' => route('owner.subscription.catalogue'),
                'create_draft' => route('owner.subscription.drafts.store'),
                'drafts' => route('owner.subscription.drafts'),
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
                'overview' => route('owner.subscription'),
                'catalogue' => route('owner.subscription.catalogue'),
                'store' => route('owner.subscription.drafts.store'),
            ],
            'editLinks' => $drafts->mapWithKeys(fn (CompanySubscription $draft): array => [
                $draft->id => route('owner.subscription.drafts.edit', $draft),
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
            ->route('owner.subscription.drafts')
            ->with('success', 'Draft contract created (not activated).');
    }

    public function editDraft(Request $request, CompanySubscription $subscription, Company $company): Response
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
                'drafts' => route('owner.subscription.drafts'),
                'preview' => route('owner.subscription.drafts.edit', $subscription),
                'update' => route('owner.subscription.drafts.update', $subscription),
                'overview' => route('owner.subscription'),
            ],
        ]);
    }

    public function updateDraft(Request $request, CompanySubscription $subscription, Company $company): RedirectResponse
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
            ->route('owner.subscription.drafts.edit', $updated)
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
            ->route('owner.subscription')
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
            ->route('owner.subscription')
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
                'overview' => route('owner.subscription'),
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
            'issuer_note' => 'SAMPLE / NON-PRODUCTION invoices only. Legally operative tax invoices require validated issuer/GST (Pending). Approved numbers are immutable. Email failure never reverses payment or number approval.',
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
                'preview_url' => route('owner.subscription.invoices.show', $invoice),
                'download_url' => route('owner.subscription.invoices.download', $invoice),
                'email_url' => in_array($invoice->status, ['approved', 'issued', 'emailed'], true)
                    ? route('owner.subscription.invoices.email', $invoice)
                    : null,
            ])->values()->all(),
            'links' => [
                'overview' => route('owner.subscription'),
            ],
        ]);
    }

    public function invoiceShow(Request $request, SubscriptionInvoice $invoice, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);
        $this->assertCompanyInvoice($company, $invoice);

        return Inertia::render('owner/subscription/invoice-preview', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'document' => $this->invoiceDocuments->presentation($invoice),
            'links' => [
                'invoices' => route('owner.subscription.invoices'),
                'download' => route('owner.subscription.invoices.download', $invoice),
                'email' => in_array($invoice->status, ['approved', 'issued', 'emailed'], true)
                    ? route('owner.subscription.invoices.email', $invoice)
                    : null,
            ],
        ]);
    }

    public function invoiceDownload(Request $request, SubscriptionInvoice $invoice, Company $company): HttpResponse
    {
        $this->authorizeOwnerPanel($request, $company, $this->access);
        $this->assertCompanyInvoice($company, $invoice);

        $html = $this->invoiceDocuments->renderHtml($invoice);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="subscription-invoice-'.$invoice->id.'.html"',
            'X-TruckBill-Invoice-Sample' => 'non-production',
        ]);
    }

    public function invoiceEmail(Request $request, SubscriptionInvoice $invoice, Company $company): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertCompanyInvoice($company, $invoice);

        try {
            $result = $this->invoiceDocuments->dispatchEmail($invoice, $user);
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        if ($result->email_status === 'sent') {
            return back()->with('success', 'Sample invoice email dispatched. Delivery does not affect payment or number approval.');
        }

        return back()->withErrors([
            'email' => 'Email dispatch failed: '.($result->email_failure_reason ?? 'unknown').'. Approval and payment remain unchanged.',
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

        $quotaCounters = CompanyQuotaCounter::query()
            ->where('company_id', $company->id)
            ->orderBy('quota_type')
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
            'quotas' => $this->quotaPayloads($quotaCounters, consumedKey: true),
            'presentation_note' => 'Credits are non-cash balances in their ledger units. They are never shown as a generic ₹ cash wallet. Broker/Partload quotas are server-calculated; LR/Bilty operational gates remain Phase 4+',
            'links' => [
                'overview' => route('owner.subscription'),
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

    private function assertCompanyInvoice(Company $company, SubscriptionInvoice $invoice): void
    {
        if ($invoice->company_id !== $company->id) {
            abort(404);
        }
    }

    /**
     * @param  iterable<int, CompanyQuotaCounter>  $counters
     * @return list<array<string, mixed>>
     */
    private function quotaPayloads(iterable $counters, bool $consumedKey = false): array
    {
        $payloads = [];
        foreach ($counters as $counter) {
            $thresholds = $this->quotas->thresholdState($counter);
            $included = (string) $counter->included_units;
            $used = (string) $counter->used_units;
            $row = [
                'id' => $counter->id,
                'quota_type' => $counter->quota_type,
                'included_units' => $included,
                'available_units' => (string) max(0, (float) $included - (float) $used),
                'period_start' => $this->formatModelDate($counter->getAttribute('period_start')),
                'period_end' => $this->formatModelDate($counter->getAttribute('period_end')),
                'percent_used' => $thresholds['percent_used'],
                'alert' => $thresholds['alert'],
                'block' => $thresholds['block'],
            ];
            if ($consumedKey) {
                $row['consumed_units'] = $used;
            } else {
                $row['used_units'] = $used;
            }
            $payloads[] = $row;
        }

        return $payloads;
    }

    /**
     * @param  iterable<int, SubscriptionChangeProposal>  $proposals
     * @return list<array<string, mixed>>
     */
    private function scheduledChangePayloads(iterable $proposals): array
    {
        $payloads = [];
        foreach ($proposals as $proposal) {
            $payloads[] = [
                'id' => $proposal->id,
                'change_type' => $proposal->change_type,
                'status' => $proposal->status,
                'effective_on' => $this->formatModelDate($proposal->getAttribute('effective_on')),
                'remark' => $proposal->remark,
            ];
        }

        return $payloads;
    }

    private function formatModelDate(mixed $value): ?string
    {
        if ($value instanceof CarbonImmutable || $value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function purchasedCapacityFromSubscription(?CompanySubscription $subscription): array
    {
        if ($subscription === null) {
            return [0, 0];
        }

        $raw = $subscription->getAttribute('pricing_breakdown');
        $breakdown = is_array($raw) ? $raw : [];
        $branches = (int) ($breakdown['purchased_branches'] ?? $breakdown['extra_branches'] ?? 0);
        $seats = (int) ($breakdown['purchased_seats'] ?? $breakdown['extra_seats'] ?? 0);

        return [max(0, $branches), max(0, $seats)];
    }

    private function vehicleCapacityFromSubscription(?CompanySubscription $subscription): ?int
    {
        if ($subscription === null || ! $subscription->relationLoaded('items')) {
            return null;
        }

        $units = $subscription->items
            ->where('item_type', 'plan')
            ->pluck('capacity_units_snapshot')
            ->filter(fn ($value): bool => $value !== null)
            ->map(fn ($value): int => (int) $value)
            ->sum();

        return $units > 0 ? $units : null;
    }

    private function creditUnitLabel(string $ledgerType): string
    {
        return match ($ledgerType) {
            'storage_gb' => 'GB storage',
            'sms' => 'SMS credits',
            'whatsapp' => 'WhatsApp credits',
            'capacity' => 'Capacity units',
            'broker' => 'Broker credits',
            'partload' => 'Partload credits',
            default => $ledgerType.' units',
        };
    }
}
