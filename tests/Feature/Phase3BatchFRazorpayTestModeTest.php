<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\CheckoutVerificationService;
use App\Domains\Subscription\Payments\Gateway\FakePaymentGateway;
use App\Domains\Subscription\Payments\Gateway\PaymentGateway;
use App\Domains\Subscription\Payments\Gateway\PaymentGatewayAuthorization;
use App\Domains\Subscription\Payments\Gateway\RazorpayClient;
use App\Domains\Subscription\Payments\Gateway\RazorpayTestPaymentGateway;
use App\Domains\Subscription\Payments\PaymentEventProcessor;
use App\Domains\Subscription\Payments\PaymentIntentService;
use App\Domains\Subscription\Payments\RazorpayWebhookNormalizer;
use App\Domains\Subscription\Payments\Support\RazorpayMoney;
use App\Models\City;
use App\Models\PaymentIntent;
use App\Models\State;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Razorpay\Api\Errors\SignatureVerificationError;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    config([
        'subscription.payments.razorpay.key_id' => '',
        'subscription.payments.razorpay.key_secret' => '',
        'subscription.payments.razorpay.webhook_secret' => '',
    ]);
});

afterEach(function () {
    TenantContextHolder::clear();
    Mockery::close();
});

function batchFLogin(User $user, string $password = 'owner-secret'): void
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $user->email, 'password' => $password]);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

function batchFOwnerDraft(): array
{
    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Batch F Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    test()->actingAs($owner);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-12-01'),
    );

    return compact('owner', 'company', 'draft');
}

function batchFRazorpayConfig(): void
{
    config([
        'subscription.payments.razorpay.key_id' => 'rzp_test_ci_key',
        'subscription.payments.razorpay.key_secret' => 'test_secret',
        'subscription.payments.razorpay.webhook_secret' => 'wh_secret',
    ]);
}

/**
 * @return array{0: RazorpayClient&MockInterface, 1: RazorpayTestPaymentGateway}
 */
function batchFSdkGateway(?callable $configure = null): array
{
    batchFRazorpayConfig();
    $client = Mockery::mock(RazorpayClient::class);
    if ($configure !== null) {
        $configure($client);
    }
    $gateway = new RazorpayTestPaymentGateway(app(PaymentGatewayAuthorization::class), $client);

    return [$client, $gateway];
}

test('paise conversion is exact', function () {
    expect(RazorpayMoney::toPaise('10.00'))->toBe(1000)
        ->and(RazorpayMoney::fromPaise(1000))->toBe('10.00')
        ->and(RazorpayMoney::toPaise('99.99'))->toBe(9999);
});

test('production environment rejects razorpay test mode', function () {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('isProduction')->andReturn(true);
    $app->shouldReceive('environment')->with('testing')->andReturn(false);
    $auth = new PaymentGatewayAuthorization($app);
    batchFRazorpayConfig();

    expect(fn () => $auth->assertGatewayAllowed('razorpay_test'))
        ->toThrow(SubscriptionDomainException::class)
        ->and($auth->isRazorpayTestModeAvailable())->toBeFalse()
        ->and($auth->isWebhookEndpointEnabled())->toBeFalse();
});

test('live key id is rejected in test mode', function () {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('isProduction')->andReturn(false);
    $app->shouldReceive('environment')->with('testing')->andReturn(false);
    $auth = new PaymentGatewayAuthorization($app);
    config([
        'subscription.payments.razorpay.key_id' => 'rzp_live_should_fail',
        'subscription.payments.razorpay.key_secret' => 'secret',
        'subscription.payments.razorpay.webhook_secret' => 'whsec',
    ]);

    expect(fn () => $auth->assertGatewayAllowed('razorpay_test'))
        ->toThrow(SubscriptionDomainException::class);
});

test('missing credentials fail closed', function () {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('isProduction')->andReturn(false);
    $app->shouldReceive('environment')->with('testing')->andReturn(false);
    $auth = new PaymentGatewayAuthorization($app);
    config([
        'subscription.payments.razorpay.key_id' => '',
        'subscription.payments.razorpay.key_secret' => '',
        'subscription.payments.razorpay.webhook_secret' => '',
    ]);

    expect(fn () => $auth->assertGatewayAllowed('razorpay_test'))
        ->toThrow(SubscriptionDomainException::class)
        ->and($auth->selectedGateway())->toBe('fake');
});

test('testing environment selects fake gateway automatically', function () {
    expect(app(PaymentGatewayAuthorization::class)->selectedGateway())->toBe('fake')
        ->and(app(PaymentGateway::class))
        ->toBeInstanceOf(FakePaymentGateway::class);
});

test('razorpay webhook normalizer maps payment captured events', function () {
    $normalized = (new RazorpayWebhookNormalizer)->normalize([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => [
                'entity' => [
                    'id' => 'pay_test_1',
                    'order_id' => 'order_test_1',
                    'status' => 'captured',
                    'amount' => 50000,
                    'currency' => 'INR',
                ],
            ],
        ],
    ], 'evt_delivery_1');

    expect($normalized['event_id'])->toBe('evt_delivery_1')
        ->and($normalized['order_id'])->toBe('order_test_1')
        ->and($normalized['payment_id'])->toBe('pay_test_1')
        ->and($normalized['amount'])->toBe('500.00')
        ->and($normalized['status'])->toBe('captured');
});

test('sdk-backed gateway creates orders and verifies signatures', function () {
    ['company' => $company, 'draft' => $draft] = batchFOwnerDraft();

    [$client, $gateway] = batchFSdkGateway(function (RazorpayClient&MockInterface $client): void {
        $client->shouldReceive('createOrder')->once()->andReturn([
            'id' => 'order_RzpSdk1',
            'amount' => 100000,
            'currency' => 'INR',
            'status' => 'created',
        ]);
        $client->shouldReceive('verifyPaymentSignature')->once()->with(Mockery::on(
            fn (array $attrs): bool => $attrs['razorpay_order_id'] === 'order_RzpSdk1'
                && $attrs['razorpay_payment_id'] === 'pay_RzpSdk1'
                && $attrs['razorpay_signature'] === 'sig_ok'
        ))->andReturnNull();
        $client->shouldReceive('verifyPaymentSignature')->once()->with(Mockery::on(
            fn (array $attrs): bool => ($attrs['razorpay_signature'] ?? '') === 'bad'
        ))->andThrow(new SignatureVerificationError('Invalid signature passed'));
        $client->shouldReceive('verifyWebhookSignature')->twice()->andReturnNull();
        $client->shouldReceive('fetchPayment')->never();
    });

    $intents = new PaymentIntentService($gateway, app(PaymentGatewayAuthorization::class));
    $intent = $intents->create($company, 'subscription_activation', '1000.00', 'rzp-sdk-1', subscription: $draft);

    expect($intent->gateway_order_id)->toBe('order_RzpSdk1')
        ->and($intent->gateway)->toBe('razorpay_test');

    expect($gateway->verifyCheckoutSignature([
        'razorpay_order_id' => 'order_RzpSdk1',
        'razorpay_payment_id' => 'pay_RzpSdk1',
    ], 'sig_ok'))->toBeTrue()
        ->and($gateway->verifyCheckoutSignature([
            'razorpay_order_id' => 'order_RzpSdk1',
            'razorpay_payment_id' => 'pay_RzpSdk1',
        ], 'bad'))->toBeFalse();

    $raw = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => [
                'entity' => [
                    'id' => 'pay_RzpSdk1',
                    'order_id' => 'order_RzpSdk1',
                    'status' => 'captured',
                    'amount' => 100000,
                    'currency' => 'INR',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $processor = new PaymentEventProcessor($gateway);
    $event = $processor->processRawWebhook($raw, 'wh_sig', 'rzp_evt_1', 'wh_secret');

    expect($event->processing_status)->toBe('processed')
        ->and($intent->fresh()->status)->toBe('paid')
        ->and($draft->fresh()->status)->toBe('active');

    $dup = $processor->processRawWebhook($raw, 'wh_sig', 'rzp_evt_1', 'wh_secret');
    expect($dup->processing_status)->toBe('duplicate');
});

test('webhook rejects invalid signature and does not trust mismatched payload bytes', function () {
    $gateway = new FakePaymentGateway;
    $processor = new PaymentEventProcessor($gateway);
    $event = [
        'event_id' => 'evt_bad',
        'order_id' => 'order_x',
        'payment_id' => 'pay_x',
        'status' => 'captured',
        'amount' => '10.00',
        'currency' => 'INR',
    ];
    $signed = $gateway->signWebhook($event);
    $rejected = $processor->processRawWebhook($signed['raw'], 'wrong', null, $gateway->secret());
    expect($rejected->processing_status)->toBe('rejected');
});

test('owner checkout simulate activates subscription with fake gateway', function () {
    ['owner' => $owner, 'company' => $company, 'draft' => $draft] = batchFOwnerDraft();
    batchFLogin($owner);
    enterManagementCompany($company)->assertRedirect();

    $this->get(route('owner.subscription.checkout', ['subscription' => $draft]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/checkout')
            ->where('gateway', 'fake')
            ->where('allow_fake_simulate', true)
            ->where('test_mode_banner', 'Razorpay Test Mode — No Real Money Will Be Charged'));

    $intent = PaymentIntent::query()->where('company_id', $company->id)->latest('created_at')->firstOrFail();

    $this->post(route('owner.subscription.checkout.simulate', ['subscription' => $draft]), [
        'payment_intent_id' => $intent->id,
    ])->assertRedirect(route('owner.subscription'));

    expect($intent->fresh()->status)->toBe('paid')
        ->and($draft->fresh()->status)->toBe('active');
});

test('webhook endpoint is closed when test credentials are absent', function () {
    $this->post(route('webhooks.razorpay'), ['event' => 'payment.captured'])->assertNotFound();
});

test('webhook endpoint processes signed razorpay payload when test credentials exist', function () {
    batchFRazorpayConfig();

    $client = Mockery::mock(RazorpayClient::class);
    $client->shouldReceive('createOrder')->once()->andReturn([
        'id' => 'order_Wh1',
        'amount' => 25000,
        'currency' => 'INR',
        'status' => 'created',
    ]);
    $client->shouldReceive('verifyWebhookSignature')->once()->andReturnNull();

    $this->app->instance(RazorpayClient::class, $client);
    $gateway = new RazorpayTestPaymentGateway(app(PaymentGatewayAuthorization::class), $client);
    $this->app->instance(PaymentGateway::class, $gateway);

    ['company' => $company, 'draft' => $draft] = batchFOwnerDraft();
    $intent = (new PaymentIntentService($gateway, app(PaymentGatewayAuthorization::class)))
        ->create($company, 'subscription_activation', '250.00', 'wh-intent-1', subscription: $draft);

    $raw = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => [
                'entity' => [
                    'id' => 'pay_Wh1',
                    'order_id' => 'order_Wh1',
                    'status' => 'captured',
                    'amount' => 25000,
                    'currency' => 'INR',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $sig = 'signed';

    $this->call(
        'POST',
        route('webhooks.razorpay'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $sig,
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_wh_1',
        ],
        $raw,
    )->assertOk();

    expect($intent->fresh()->status)->toBe('paid')
        ->and($draft->fresh()->status)->toBe('active');
});

test('client amount cannot override server payable amount', function () {
    ['company' => $company, 'draft' => $draft] = batchFOwnerDraft();
    $gateway = new FakePaymentGateway;
    $intents = new PaymentIntentService($gateway);
    $intent = $intents->create($company, 'subscription_activation', (string) $draft->discounted_annual_total, 'amt-1', subscription: $draft);

    $tampered = [
        'event_id' => 'evt_amt',
        'order_id' => $intent->gateway_order_id,
        'payment_id' => 'pay_amt',
        'status' => 'captured',
        'amount' => '1.00',
        'currency' => 'INR',
    ];
    $signed = $gateway->signWebhook($tampered);
    $event = (new PaymentEventProcessor($gateway))->processRawWebhook($signed['raw'], $signed['signature'], null, $gateway->secret());

    expect($event->processing_status)->toBe('quarantined')
        ->and($intent->fresh()->status)->not->toBe('paid');
});

test('checkout verification rejects cross-company intent', function () {
    ['owner' => $ownerA, 'company' => $companyA, 'draft' => $draftA] = batchFOwnerDraft();
    $ownerB = User::factory()->customer()->create();
    $companyB = app(CompanyWorkspaceService::class)->createCompany($ownerB, 'Other Co');
    $gateway = new FakePaymentGateway;
    $intent = (new PaymentIntentService($gateway))->create($companyA, 'subscription_activation', '100.00', 'xco-1', subscription: $draftA);
    $paymentId = 'pay_x';
    $sig = hash_hmac('sha256', $intent->gateway_order_id.'|'.$paymentId, $gateway->secret());

    expect(fn () => (new CheckoutVerificationService($gateway, new PaymentEventProcessor($gateway)))
        ->verifyOwnerCallback($ownerA, $companyB, $intent, [
            'order_id' => $intent->gateway_order_id,
            'payment_id' => $paymentId,
            'signature' => $sig,
        ]))->toThrow(SubscriptionDomainException::class);
});

test('stale fake gateway intent is not reused under razorpay test mode', function () {
    ['company' => $company, 'draft' => $draft] = batchFOwnerDraft();
    $logicalKey = 'checkout:'.$draft->id.':subscription_activation:9000.00';

    $fakeIntent = (new PaymentIntentService(new FakePaymentGateway))
        ->create($company, 'subscription_activation', '9000.00', $logicalKey, subscription: $draft);

    expect($fakeIntent->gateway)->toBe('fake')
        ->and($fakeIntent->gateway_order_id)->toStartWith('order_fake_');

    [, $rzpGateway] = batchFSdkGateway(function (RazorpayClient&MockInterface $client): void {
        $client->shouldReceive('createOrder')->once()->andReturn([
            'id' => 'order_RzpFresh1',
            'amount' => 900000,
            'currency' => 'INR',
            'status' => 'created',
        ]);
    });

    $rzpIntent = (new PaymentIntentService($rzpGateway, app(PaymentGatewayAuthorization::class)))
        ->create($company, 'subscription_activation', '9000.00', $logicalKey, subscription: $draft);

    expect($rzpIntent->id)->not->toBe($fakeIntent->id)
        ->and($rzpIntent->gateway)->toBe('razorpay_test')
        ->and($rzpIntent->gateway_order_id)->toBe('order_RzpFresh1')
        ->and($fakeIntent->fresh()->gateway_order_id)->toStartWith('order_fake_');
});

test('checkout verification rejects authorized but uncaptured payments', function () {
    ['owner' => $owner, 'company' => $company, 'draft' => $draft] = batchFOwnerDraft();

    [, $gateway] = batchFSdkGateway(function (RazorpayClient&MockInterface $client): void {
        $client->shouldReceive('createOrder')->once()->andReturn([
            'id' => 'order_Auth1',
            'amount' => 50000,
            'currency' => 'INR',
            'status' => 'created',
        ]);
        $client->shouldReceive('verifyPaymentSignature')->once()->andReturnNull();
        $client->shouldReceive('fetchPayment')->once()->with('pay_Auth1')->andReturn([
            'id' => 'pay_Auth1',
            'order_id' => 'order_Auth1',
            'status' => 'authorized',
            'amount' => 50000,
            'currency' => 'INR',
        ]);
    });

    $intent = (new PaymentIntentService($gateway, app(PaymentGatewayAuthorization::class)))
        ->create($company, 'subscription_activation', '500.00', 'auth-uncaptured', subscription: $draft);

    expect(fn () => (new CheckoutVerificationService($gateway, new PaymentEventProcessor($gateway)))
        ->verifyOwnerCallback($owner, $company, $intent, [
            'razorpay_order_id' => 'order_Auth1',
            'razorpay_payment_id' => 'pay_Auth1',
            'razorpay_signature' => 'sig',
        ]))->toThrow(SubscriptionDomainException::class);

    expect($intent->fresh()->status)->not->toBe('paid')
        ->and($draft->fresh()->status)->not->toBe('active');
});

test('checkout verification accepts captured payment with matching amount', function () {
    ['owner' => $owner, 'company' => $company, 'draft' => $draft] = batchFOwnerDraft();

    [, $gateway] = batchFSdkGateway(function (RazorpayClient&MockInterface $client): void {
        $client->shouldReceive('createOrder')->once()->andReturn([
            'id' => 'order_Cap1',
            'amount' => 75000,
            'currency' => 'INR',
            'status' => 'created',
        ]);
        $client->shouldReceive('verifyPaymentSignature')->once()->andReturnNull();
        $client->shouldReceive('fetchPayment')->once()->with('pay_Cap1')->andReturn([
            'id' => 'pay_Cap1',
            'order_id' => 'order_Cap1',
            'status' => 'captured',
            'amount' => 75000,
            'currency' => 'INR',
        ]);
    });

    $intent = (new PaymentIntentService($gateway, app(PaymentGatewayAuthorization::class)))
        ->create($company, 'subscription_activation', '750.00', 'cap-ok', subscription: $draft);

    $verified = (new CheckoutVerificationService($gateway, new PaymentEventProcessor($gateway)))
        ->verifyOwnerCallback($owner, $company, $intent, [
            'razorpay_order_id' => 'order_Cap1',
            'razorpay_payment_id' => 'pay_Cap1',
            'razorpay_signature' => 'sig',
        ]);

    expect($verified->status)->toBe('paid')
        ->and($draft->fresh()->status)->toBe('active');
});

test('checkout verification rejects remote amount mismatch', function () {
    ['owner' => $owner, 'company' => $company, 'draft' => $draft] = batchFOwnerDraft();

    [, $gateway] = batchFSdkGateway(function (RazorpayClient&MockInterface $client): void {
        $client->shouldReceive('createOrder')->once()->andReturn([
            'id' => 'order_Mis1',
            'amount' => 10000,
            'currency' => 'INR',
            'status' => 'created',
        ]);
        $client->shouldReceive('verifyPaymentSignature')->once()->andReturnNull();
        $client->shouldReceive('fetchPayment')->once()->andReturn([
            'id' => 'pay_Mis1',
            'order_id' => 'order_Mis1',
            'status' => 'captured',
            'amount' => 1,
            'currency' => 'INR',
        ]);
    });

    $intent = (new PaymentIntentService($gateway, app(PaymentGatewayAuthorization::class)))
        ->create($company, 'subscription_activation', '100.00', 'amt-mis', subscription: $draft);

    expect(fn () => (new CheckoutVerificationService($gateway, new PaymentEventProcessor($gateway)))
        ->verifyOwnerCallback($owner, $company, $intent, [
            'razorpay_order_id' => 'order_Mis1',
            'razorpay_payment_id' => 'pay_Mis1',
            'razorpay_signature' => 'sig',
        ]))->toThrow(SubscriptionDomainException::class);

    expect($intent->fresh()->status)->not->toBe('paid');
});

test('failed intent can be retried without reusing the failed row', function () {
    ['company' => $company, 'draft' => $draft] = batchFOwnerDraft();
    $gateway = new FakePaymentGateway;
    $intents = new PaymentIntentService($gateway);
    $first = $intents->create($company, 'subscription_activation', '100.00', 'retry-key', subscription: $draft);
    $first->forceFill(['status' => 'failed'])->save();

    $second = $intents->create($company, 'subscription_activation', '100.00', 'retry-key', subscription: $draft);

    expect($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe('order_created')
        ->and($second->gateway_order_id)->not->toBe($first->gateway_order_id);
});

test('sdk order creation failures are wrapped safely', function () {
    ['company' => $company, 'draft' => $draft] = batchFOwnerDraft();

    [, $gateway] = batchFSdkGateway(function (RazorpayClient&MockInterface $client): void {
        $client->shouldReceive('createOrder')->once()->andThrow(new RuntimeException('network down'));
    });

    expect(fn () => (new PaymentIntentService($gateway, app(PaymentGatewayAuthorization::class)))
        ->create($company, 'subscription_activation', '100.00', 'sdk-fail', subscription: $draft))
        ->toThrow(SubscriptionDomainException::class);
});

test('verified payment activates subscription and queues pending approval invoice', function () {
    ['owner' => $owner, 'company' => $company, 'draft' => $draft] = batchFOwnerDraft();
    $gateway = new FakePaymentGateway;
    $intent = (new PaymentIntentService($gateway))->create(
        $company,
        'subscription_activation',
        '100.00',
        'inv-after-pay',
        subscription: $draft,
    );
    $paymentId = 'pay_inv_auto';
    $sig = hash_hmac('sha256', $intent->gateway_order_id.'|'.$paymentId, $gateway->secret());

    (new CheckoutVerificationService($gateway, new PaymentEventProcessor($gateway)))
        ->verifyOwnerCallback($owner, $company, $intent, [
            'order_id' => $intent->gateway_order_id,
            'payment_id' => $paymentId,
            'signature' => $sig,
        ]);

    $invoice = SubscriptionInvoice::query()
        ->where('payment_intent_id', $intent->id)
        ->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('pending_approval')
        ->and($invoice->approved_number)->toBeNull()
        ->and($draft->fresh()->status)->toBe('active');
});

test('super admin can approve subscription invoice with billing profile and manual invoice number', function () {
    ['company' => $company, 'draft' => $draft] = batchFOwnerDraft();
    $gateway = new FakePaymentGateway;
    $intent = (new PaymentIntentService($gateway))->create($company, 'subscription_activation', '50.00', 'sa-inv', subscription: $draft);
    $intent->forceFill(['status' => 'paid', 'verification_status' => 'verified'])->save();
    $invoice = app(SubscriptionInvoiceService::class)
        ->createPendingApprovalFromPayment($intent);

    $sa = User::factory()->superAdmin()->create();
    $state = State::query()->firstOrCreate(
        ['code' => 'MH'],
        ['id' => (string) Str::uuid(), 'name' => 'Maharashtra', 'gst_code' => '27', 'is_active' => true],
    );
    $city = City::query()->firstOrCreate(
        ['state_id' => $state->id, 'name_normalized' => 'pune'],
        ['id' => (string) Str::uuid(), 'name' => 'Pune', 'is_active' => true],
    );
    $profile = app(PlatformBillingProfileService::class)->create($sa, [
        'legal_name' => 'TruckBill Billing Co',
        'pan' => 'AABCT1234F',
        'contact_number' => '9876543210',
        'email' => 'bill@truckbill.test',
        'registered_address' => 'Pune',
        'state_id' => $state->id,
        'city_id' => $city->id,
        'pin_code' => '411001',
        'gstin' => null,
    ]);

    test()->actingAs($sa);

    $this->post(route('admin.subscription-invoices.approve', $invoice), [
        'billing_profile_id' => $profile->id,
        'invoice_number' => 'TB/2026-27/SA01',
        'invoice_date' => now('Asia/Kolkata')->toDateString(),
    ])->assertRedirect();

    expect($invoice->fresh()->approved_number)->toBe('TB/2026-27/SA01')
        ->and(in_array($invoice->fresh()->status, ['approved', 'issued', 'emailed'], true))->toBeTrue();
});

test('owner cannot approve invoice numbers', function () {
    ['owner' => $owner, 'company' => $company, 'draft' => $draft] = batchFOwnerDraft();
    $gateway = new FakePaymentGateway;
    $intent = (new PaymentIntentService($gateway))->create($company, 'subscription_activation', '50.00', 'own-inv', subscription: $draft);
    $intent->forceFill(['status' => 'paid', 'verification_status' => 'verified'])->save();
    $invoice = app(SubscriptionInvoiceService::class)
        ->createPendingApprovalFromPayment($intent);

    batchFLogin($owner);
    $this->post(route('admin.subscription-invoices.approve', $invoice), [
        'billing_profile_id' => (string) Str::uuid(),
        'invoice_number' => 'HACKED',
        'invoice_date' => now('Asia/Kolkata')->toDateString(),
    ])->assertForbidden();
});
