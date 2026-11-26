<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceDocumentService;
use App\Domains\Subscription\Capacity\CompanyCapacityService;
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
use App\Domains\Subscription\Purchase\SubscriptionPurchaseService;
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
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner Subscription Overview, Single-Screen Purchase/Upgrade/Renewal, Checkout, Payments & Invoices.
 */
class OwnerSubscriptionController extends Controller
{
    use AuthorizesOwnerPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PaymentIntentService $paymentIntents,
        private readonly CheckoutVerificationService $checkout,
        private readonly PaymentGateway $gateway,
        private readonly PaymentGatewayAuthorization $paymentAuth,
        private readonly SubscriptionPurchaseService $purchase = new SubscriptionPurchaseService,
        private readonly PlatformBillingProfileService $billingProfiles = new PlatformBillingProfileService,
        private readonly SubscriptionEntitlementGate $entitlements = new SubscriptionEntitlementGate,
        private readonly SubscriptionLifecycleService $lifecycle = new SubscriptionLifecycleService,
        private readonly CompanyCapacityService $capacity = new CompanyCapacityService,
        private readonly StorageReconciliationService $storage = new StorageReconciliationService,
        private readonly QuotaAllocationService $quotas = new QuotaAllocationService,
        private readonly SubscriptionInvoiceDocumentService $invoiceDocuments = new SubscriptionInvoiceDocumentService,
    ) {}

    /**
     * Global Purchase screen — Company selected explicitly (independent of WorkspaceSession).
     */
    public function purchaseGlobal(Request $request): Response|RedirectResponse
    {
        $user = $this->authorizeOwnerIdentity($request);

        $ownedCompanies = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        if ($ownedCompanies->isEmpty()) {
            return redirect()
                ->route('owner.companies.create')
                ->with('info', 'Create a company before purchasing a subscription.');
        }

        $requestedId = $request->query('company');
        $company = null;
        if (is_string($requestedId) && $requestedId !== '') {
            $company = $ownedCompanies->firstWhere('id', $requestedId);
            if ($company === null) {
                abort(403, 'You do not own the selected company.');
            }
        } else {
            $company = $ownedCompanies->first(fn (Company $c): bool => $c->isActive()) ?? $ownedCompanies->first();
        }

        return $this->renderPurchase($request, $company, $ownedCompanies);
    }

    /**
     * Explicit-Company Purchase / Upgrade / Renewal screen.
     */
    public function purchaseForCompany(Request $request, Company $company): Response
    {
        $user = $this->authorizeOwnerIdentity($request);
        if (! $company->isOwnedBy($user)) {
            abort(403, 'Owner Panel access denied.');
        }

        $ownedCompanies = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        return $this->renderPurchase($request, $company, $ownedCompanies);
    }

    /**
     * Authoritative live pricing quote JSON endpoint.
     */
    public function quote(Request $request, Company $company): JsonResponse
    {
        $this->authorizeOwnerPanel($request, $company, $this->access);

        $validated = $request->validate([
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => ['uuid'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['uuid'],
            'addon_quantities' => ['nullable', 'array'],
            'addon_quantities.*' => ['integer', 'min:0', 'max:500'],
            'tenure' => ['nullable', Rule::enum(SubscriptionTenure::class)],
            'mode' => ['nullable', Rule::in(['initial', 'upgrade', 'renewal'])],
        ]);

        try {
            $quote = $this->purchase->quote($company, $validated);
        } catch (SubscriptionDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($quote);
    }

    /**
     * Initiate subscription purchase / upgrade / renewal and redirect to server-verified checkout.
     */
    public function storePurchase(Request $request, Company $company): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $validated = $request->validate([
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => ['uuid'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['uuid'],
            'addon_quantities' => ['nullable', 'array'],
            'addon_quantities.*' => ['integer', 'min:0', 'max:500'],
            'tenure' => ['nullable', Rule::enum(SubscriptionTenure::class)],
            'mode' => ['nullable', Rule::in(['initial', 'upgrade', 'renewal'])],
        ]);

        try {
            $result = $this->purchase->initiateCheckout($user, $company, $validated, $this->paymentIntents);
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['purchase' => $e->getMessage()]);
        }

        return redirect()->route('owner.companies.subscription.checkout', [
            'company' => $company,
            'intent' => $result['intent'],
        ]);
    }

    public function overview(Request $request, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $catalogue = $this->activeCatalogue();

        $subscriptions = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->with(['items'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $activeOrDraft = $subscriptions->first(fn (CompanySubscription $s): bool => in_array($s->status, ['active', 'pending_payment', 'draft'], true));
        $active = $subscriptions->first(fn (CompanySubscription $s): bool => $s->status === 'active');

        $ledgers = SubscriptionCreditLedger::query()
            ->where('company_id', $company->id)
            ->orderBy('ledger_type')
            ->get();

        $entitlement = $this->entitlements->evaluate($company);
        $effective = $this->lifecycle->effectiveStatus($company);
        [$purchasedBranches, $purchasedSeats, $extraStorageBytes] = $this->capacity->activePurchasedExtras($company);
        $capacity = $this->capacity->evaluate($company, $purchasedBranches, $purchasedSeats);
        $vehicleCapacity = $this->vehicleCapacityFromSubscription($active);
        $baseStorage = (int) config('subscription.storage.default_allocation_bytes', 0);
        $allocationBytes = ($baseStorage > 0 || $extraStorageBytes > 0) ? ($baseStorage + $extraStorageBytes) : 0;
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
            'payment_gate_note' => 'Checkout uses Razorpay Test Mode in this environment. Live charges are not enabled.',
            'links' => [
                'dashboard' => route('owner.home'),
                'purchase' => route('owner.companies.subscription.purchase', $company),
                'renew_page' => route('owner.companies.subscription.purchase', ['company' => $company, 'mode' => 'renewal']),
                'credits' => route('owner.subscription.credits'),
                'payments' => route('owner.subscription.payments'),
                'invoices' => route('owner.subscription.invoices'),
                'cancel' => route('owner.subscription.cancel'),
                'withdraw_cancel' => route('owner.subscription.cancel.withdraw'),
                'checkout' => ($activeOrDraft !== null && in_array($activeOrDraft->status, ['draft', 'pending_payment'], true))
                    ? route('owner.subscription.checkout', $activeOrDraft)
                    : null,
                'renew' => route('owner.subscription.renew'),
            ],
            'payment_gateway' => $this->gateway->name(),
            'razorpay_test_ready' => $this->paymentAuth->isRazorpayTestModeAvailable(),
        ]);
    }

    /**
     * Explicit-company Checkout Review & Pay screen bound to a PaymentIntent.
     */
    public function checkoutIntent(Request $request, Company $company, PaymentIntent $intent): Response|RedirectResponse
    {
        ['user' => $user, 'scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        if ($intent->company_id !== $company->id) {
            abort(404);
        }

        if ($intent->status === 'paid') {
            return redirect()
                ->route('owner.subscription.invoices')
                ->with('success', 'This payment has already been verified and fulfilled.');
        }

        $subscription = $intent->company_subscription_id !== null
            ? CompanySubscription::query()->whereKey($intent->company_subscription_id)->with('items')->first()
            : null;

        return $this->renderCheckout($user, $company, $scope, $intent, $subscription);
    }

    /**
     * Legacy / direct checkout route for a subscription contract (reuses single checkout pipeline).
     */
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

        return $this->renderCheckout($user, $company, $scope, $intent, $subscription->load('items'));
    }

    public function verifyCheckoutIntent(Request $request, Company $company, PaymentIntent $intent): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        if ($intent->company_id !== $company->id) {
            abort(404);
        }

        $validated = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        try {
            $this->checkout->verifyOwnerCallback($user, $company, $intent, $validated);
        } catch (SubscriptionDomainException $e) {
            return redirect()
                ->route('owner.companies.subscription.checkout', [$company, $intent])
                ->withErrors(['checkout' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.companies.subscription.purchase', $company)
            ->with('success', 'Payment verified. Subscription activated and tax invoice generated.');
    }

    public function simulateCheckoutIntent(Request $request, Company $company, PaymentIntent $intent): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        if ($intent->company_id !== $company->id) {
            abort(404);
        }

        if (! app()->environment('testing')) {
            abort(403, 'Fake payment simulation is restricted to the testing environment.');
        }

        if (! $this->gateway instanceof FakePaymentGateway) {
            abort(403, 'Simulate capture is only available with the fake gateway.');
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
            ->route('owner.companies.subscription.purchase', $company)
            ->with('success', 'Payment verified and subscription updated.');
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

        return redirect()->route('owner.companies.subscription.purchase', [
            'company' => $company,
            'mode' => 'renewal',
        ]);
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

        $invoicesByIntent = SubscriptionInvoice::query()
            ->where('company_id', $company->id)
            ->whereNotNull('payment_intent_id')
            ->get()
            ->keyBy('payment_intent_id');

        return Inertia::render('owner/subscription/payments', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'gate_note' => 'Payments are verified on the server before entitlement activation. Razorpay Test Mode active when configured.',
            'payments' => $intents->map(function (PaymentIntent $intent) use ($invoicesByIntent): array {
                $invoice = $invoicesByIntent->get($intent->id);

                return [
                    'id' => $intent->id,
                    'purpose' => $intent->purpose,
                    'amount' => (string) $intent->amount,
                    'currency' => $intent->currency,
                    'status' => $intent->status,
                    'verification_status' => $intent->verification_status,
                    'gateway' => $intent->gateway,
                    'gateway_order_id' => $intent->gateway_order_id,
                    'gateway_payment_id' => $intent->gateway_payment_id,
                    'invoice_number' => $invoice !== null
                        ? ($invoice->invoice_number ?? $invoice->approved_number ?? $invoice->proposed_number)
                        : null,
                    'invoice_url' => $invoice !== null ? route('owner.subscription.invoices.show', $invoice) : null,
                    'invoice_download_url' => $invoice !== null ? route('owner.subscription.invoices.download', $invoice) : null,
                    'created_at' => $intent->created_at?->toIso8601String(),
                ];
            })->values()->all(),
            'links' => [
                'overview' => route('owner.subscription'),
                'purchase' => route('owner.companies.subscription.purchase', $company),
                'invoices' => route('owner.subscription.invoices'),
            ],
        ]);
    }

    public function invoices(Request $request, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $invoices = SubscriptionInvoice::query()
            ->where('company_id', $company->id)
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('owner/subscription/invoices', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'issuer_note' => 'Issued subscription tax invoices and credit notes store immutable supplier, customer, tax, and subscription snapshots.',
            'invoices' => $invoices->map(fn (SubscriptionInvoice $invoice): array => [
                'id' => $invoice->id,
                'document_type' => $invoice->document_type,
                'status' => $invoice->status,
                'invoice_number' => $invoice->invoice_number ?? $invoice->approved_number ?? $invoice->proposed_number,
                'proposed_number' => $invoice->proposed_number,
                'approved_number' => $invoice->approved_number,
                'financial_year' => $invoice->financial_year,
                'taxable_amount' => (string) ($invoice->taxable_amount ?: $invoice->amount),
                'total_tax_amount' => (string) ($invoice->total_tax_amount ?? '0.00'),
                'amount' => (string) $invoice->amount,
                'currency' => $invoice->currency,
                'issued_at' => ($invoice->issued_at ?? $invoice->approved_at ?? $invoice->created_at)?->toDateString(),
                'payment_reference' => $invoice->payment_reference ?? $invoice->payment_intent_id,
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
                'payments' => route('owner.subscription.payments'),
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
        $number = (string) ($invoice->invoice_number ?? $invoice->approved_number ?? $invoice->proposed_number ?? $invoice->id);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $number) ?: $invoice->id;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="subscription-invoice-'.$safe.'.html"',
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
            return back()->with('success', 'Subscription invoice email dispatched.');
        }

        return back()->withErrors([
            'email' => 'Email dispatch failed: '.($result->email_failure_reason ?? 'unknown').'. Invoice issuance and payment remain unchanged.',
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
            'presentation_note' => 'Credits are non-cash balances in their ledger units.',
            'links' => [
                'overview' => route('owner.subscription'),
            ],
        ]);
    }

    /**
     * @param  Collection<int, Company>  $ownedCompanies
     */
    private function renderPurchase(Request $request, Company $company, $ownedCompanies): Response
    {
        $this->authorizeOwnerPanel($request, $company, $this->access);

        $catalogue = $this->activeCatalogue();
        $plans = $catalogue === null
            ? collect()
            : SubscriptionPlan::query()
                ->where('catalogue_version_id', $catalogue->id)
                ->where('is_active', true)
                ->orderBy('module')
                ->orderBy('sort_order')
                ->orderBy('annual_price')
                ->get();

        $addons = $catalogue === null
            ? collect()
            : SubscriptionAddon::query()
                ->where('catalogue_version_id', $catalogue->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

        $activeSub = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNotNull('activated_at')
            ->whereNull('deleted_at')
            ->with('items')
            ->orderByDesc('activated_at')
            ->first();

        $modeParam = (string) $request->query('mode', '');
        $defaultMode = ($activeSub !== null && $modeParam === 'renewal')
            ? 'renewal'
            : ($activeSub !== null ? 'upgrade' : 'initial');

        $defaultPlanIds = [];
        if ($defaultMode === 'renewal') {
            $defaultPlanIds = array_values(
                $activeSub->items
                    ->where('item_type', 'plan')
                    ->pluck('plan_id')
                    ->filter()
                    ->map(fn (mixed $id): string => (string) $id)
                    ->all()
            );
        }

        $initialQuote = $catalogue !== null
            ? $this->purchase->quote($company, [
                'plan_ids' => $defaultPlanIds,
                'mode' => $defaultMode,
                'tenure' => $activeSub !== null ? ($activeSub->tenure ?? SubscriptionTenure::Yearly->value) : SubscriptionTenure::Yearly->value,
            ])
            : null;

        $entitlement = $this->entitlements->evaluate($company);
        $effective = $this->lifecycle->effectiveStatus($company);

        $subscribedPlanIds = $activeSub !== null
            ? $activeSub->items->where('item_type', 'plan')->pluck('plan_id')->filter()->values()->all()
            : [];
        $subscribedAddonCodes = $activeSub !== null
            ? $activeSub->items->where('item_type', 'addon')->pluck('code')->filter()->values()->all()
            : [];

        return Inertia::render('owner/subscription/purchase', [
            'company' => $this->companyPayload($company),
            'companyOptions' => $ownedCompanies->map(fn (Company $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->isActive() ? 'Active' : 'Deactivated',
                'purchase_url' => route('owner.companies.subscription.purchase', $c),
            ])->values()->all(),
            'canManage' => $company->isActive(),
            'lifecycle' => [
                'status' => $entitlement['status']->value,
                'trial_ends_at' => $effective['trial_ends_at'] ?? null,
                'common_renewal_date' => $activeSub?->common_renewal_date?->toDateString(),
            ],
            'plans' => $plans->map(fn (SubscriptionPlan $p): array => [
                'id' => $p->id,
                'module' => $p->module,
                'name' => $p->name,
                'annual_price' => (string) $p->annual_price,
                'capacity_units' => $p->capacity_units,
                'subscribed' => in_array($p->id, $subscribedPlanIds, true),
            ])->values()->all(),
            'addons' => $addons->map(fn (SubscriptionAddon $a): array => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'annual_price' => (string) $a->annual_price,
                'unit_label' => $a->unit_label,
                'is_quantifiable' => (bool) $a->is_quantifiable,
                'credit_units_per_unit' => $a->credit_units_per_unit,
                'storage_bytes_per_unit' => $a->storage_bytes_per_unit,
                'subscribed' => ! $a->is_quantifiable && in_array($a->code, $subscribedAddonCodes, true),
            ])->values()->all(),
            'tenureOptions' => collect(SubscriptionTenure::cases())->map(fn (SubscriptionTenure $t): array => [
                'value' => $t->value,
                'label' => match ($t) {
                    SubscriptionTenure::Monthly => 'Monthly',
                    SubscriptionTenure::Quarterly => 'Quarterly',
                    SubscriptionTenure::HalfYearly => 'Half-Yearly',
                    SubscriptionTenure::Yearly => 'Yearly',
                    SubscriptionTenure::ThreeYearFullAdvance => '3-Year Full Advance',
                    SubscriptionTenure::ThreeYearAnnualInstalments => '3-Year Annual Instalments',
                },
                'months' => $t->months(),
                'discount_percent' => $t->discountPercent(),
            ])->values()->all(),
            'initialQuote' => $initialQuote,
            'customerBilling' => $this->billingProfiles->customerSnapshot($company),
            'links' => [
                'quote' => route('owner.companies.subscription.quote', $company),
                'purchase' => route('owner.companies.subscription.store', $company),
                'company_settings' => route('owner.companies.index'),
                'payments' => route('owner.subscription.payments'),
                'invoices' => route('owner.subscription.invoices'),
                'subscriptions' => route('owner.subscriptions.index'),
            ],
        ]);
    }

    private function renderCheckout(
        User $user,
        Company $company,
        string $scope,
        PaymentIntent $intent,
        ?CompanySubscription $subscription,
    ): Response|RedirectResponse {
        $mode = $this->gateway->name();
        $publicKey = null;
        $allowFakeSimulate = app()->environment('testing');

        if ($mode === 'fake' && ! $allowFakeSimulate) {
            return redirect()
                ->route('owner.companies.subscription.purchase', $company)
                ->withErrors([
                    'purchase' => 'Razorpay Test Mode credentials are required for Owner checkout. Configure RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET, and RAZORPAY_WEBHOOK_SECRET (rzp_test_* only).',
                ]);
        }

        if ($this->gateway instanceof RazorpayTestPaymentGateway) {
            $publicKey = $this->gateway->publicKeyId();
        }

        $meta = is_array($intent->meta) ? $intent->meta : [];
        $quote = is_array($meta['quote'] ?? null) ? $meta['quote'] : null;

        return Inertia::render('owner/subscription/checkout', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'test_mode_banner' => 'Razorpay Test Mode — No Real Money Will Be Charged',
            'gateway' => $mode,
            'public_key' => $publicKey,
            'subscription' => $subscription === null ? null : $this->subscriptionPayload($subscription),
            'quote' => $quote,
            'customerBilling' => $quote['customer_snapshot'] ?? $this->billingProfiles->customerSnapshot($company),
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
                'overview' => route('owner.companies.subscription.purchase', $company),
                'verify' => route('owner.companies.subscription.checkout.verify', [$company, $intent]),
                'simulate' => $allowFakeSimulate
                    ? route('owner.companies.subscription.checkout.simulate', [$company, $intent])
                    : null,
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
            'items' => $subscription->relationLoaded('items')
                ? $subscription->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'item_type' => $item->item_type,
                    'plan_id' => $item->plan_id,
                    'addon_id' => $item->addon_id,
                    'module' => $item->module,
                    'code' => $item->code,
                    'name' => $item->name,
                    'quantity' => (int) ($item->quantity ?? 1),
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
