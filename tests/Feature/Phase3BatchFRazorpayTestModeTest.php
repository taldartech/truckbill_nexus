<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\CheckoutVerificationService;
use App\Domains\Subscription\Payments\Gateway\FakePaymentGateway;
use App\Domains\Subscription\Payments\Gateway\PaymentGatewayAuthorization;
use App\Domains\Subscription\Payments\Gateway\RazorpayTestPaymentGateway;
use App\Domains\Subscription\Payments\PaymentEventProcessor;
use App\Domains\Subscription\Payments\PaymentIntentService;
use App\Domains\Subscription\Payments\RazorpayWebhookNormalizer;
use App\Domains\Subscription\Payments\Support\RazorpayMoney;
use App\Models\PaymentIntent;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Mockery;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    config([
        'subscription.payments.gateway' => 'fake',
        'subscription.payments.razorpay.test_enabled' => false,
        'subscription.payments.webhook_endpoint_enabled' => false,
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

test('paise conversion is exact', function () {
    expect(RazorpayMoney::toPaise('10.00'))->toBe(1000)
        ->and(RazorpayMoney::fromPaise(1000))->toBe('10.00')
        ->and(RazorpayMoney::toPaise('99.99'))->toBe(9999);
});

test('production environment rejects razorpay test mode', function () {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('isProduction')->andReturn(true);
    $auth = new PaymentGatewayAuthorization($app);
    config([
        'subscription.payments.gateway' => 'razorpay_test',
        'subscription.payments.razorpay.test_enabled' => true,
        'subscription.payments.razorpay.mode' => 'test',
        'subscription.payments.razorpay.key_id' => 'rzp_test_x',
        'subscription.payments.razorpay.key_secret' => 'secret',
        'subscription.payments.razorpay.webhook_secret' => 'whsec',
    ]);

    expect(fn () => $auth->assertGatewayAllowed('razorpay_test'))
        ->toThrow(SubscriptionDomainException::class);
});

test('live key id is rejected in test mode', function () {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('isProduction')->andReturn(false);
    $auth = new PaymentGatewayAuthorization($app);
    config([
        'subscription.payments.razorpay.test_enabled' => true,
        'subscription.payments.razorpay.mode' => 'test',
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
    $auth = new PaymentGatewayAuthorization($app);
    config([
        'subscription.payments.razorpay.test_enabled' => true,
        'subscription.payments.razorpay.mode' => 'test',
        'subscription.payments.razorpay.key_id' => '',
        'subscription.payments.razorpay.key_secret' => '',
        'subscription.payments.razorpay.webhook_secret' => '',
    ]);

    expect(fn () => $auth->assertGatewayAllowed('razorpay_test'))
        ->toThrow(SubscriptionDomainException::class);
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

test('http-mocked razorpay test gateway creates orders and verifies signatures', function () {
    config([
        'subscription.payments.gateway' => 'razorpay_test',
        'subscription.payments.razorpay.test_enabled' => true,
        'subscription.payments.razorpay.mode' => 'test',
        'subscription.payments.razorpay.key_id' => 'rzp_test_ci',
        'subscription.payments.razorpay.key_secret' => 'test_secret',
        'subscription.payments.razorpay.webhook_secret' => 'wh_secret',
        'subscription.payments.razorpay.base_url' => 'https://api.razorpay.com/v1',
    ]);

    Http::fake([
        'api.razorpay.com/v1/orders' => Http::response([
            'id' => 'order_RzpHttp1',
            'amount' => 100000,
            'currency' => 'INR',
            'status' => 'created',
        ], 200),
        'api.razorpay.com/v1/payments/pay_RzpHttp1' => Http::response([
            'id' => 'pay_RzpHttp1',
            'order_id' => 'order_RzpHttp1',
            'status' => 'captured',
            'amount' => 100000,
            'currency' => 'INR',
        ], 200),
    ]);

    ['owner' => $owner, 'company' => $company, 'draft' => $draft] = batchFOwnerDraft();
    $gateway = new RazorpayTestPaymentGateway(app(PaymentGatewayAuthorization::class));
    $intents = new PaymentIntentService($gateway, app(PaymentGatewayAuthorization::class));
    $intent = $intents->create($company, 'subscription_activation', '1000.00', 'rzp-http-1', subscription: $draft);

    expect($intent->gateway_order_id)->toBe('order_RzpHttp1')
        ->and($intent->gateway)->toBe('razorpay_test');

    $signature = hash_hmac('sha256', 'order_RzpHttp1|pay_RzpHttp1', 'test_secret');
    expect($gateway->verifyCheckoutSignature([
        'razorpay_order_id' => 'order_RzpHttp1',
        'razorpay_payment_id' => 'pay_RzpHttp1',
    ], $signature))->toBeTrue()
        ->and($gateway->verifyCheckoutSignature([
            'razorpay_order_id' => 'order_RzpHttp1',
            'razorpay_payment_id' => 'pay_RzpHttp1',
        ], 'bad'))->toBeFalse();

    $raw = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => [
                'entity' => [
                    'id' => 'pay_RzpHttp1',
                    'order_id' => 'order_RzpHttp1',
                    'status' => 'captured',
                    'amount' => 100000,
                    'currency' => 'INR',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $whSig = hash_hmac('sha256', $raw, 'wh_secret');
    $processor = new PaymentEventProcessor($gateway);
    $event = $processor->processRawWebhook($raw, $whSig, 'rzp_evt_1');

    expect($event->processing_status)->toBe('processed')
        ->and($intent->fresh()->status)->toBe('paid')
        ->and($draft->fresh()->status)->toBe('active');

    $dup = $processor->processRawWebhook($raw, $whSig, 'rzp_evt_1');
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

    $this->get(route('owner.subscription.checkout', [$company, $draft]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/checkout')
            ->where('gateway', 'fake')
            ->where('test_mode_banner', 'Razorpay Test Mode — No Real Money Will Be Charged'));

    $intent = PaymentIntent::query()->where('company_id', $company->id)->latest('created_at')->firstOrFail();

    $this->post(route('owner.subscription.checkout.simulate', [$company, $draft]), [
        'payment_intent_id' => $intent->id,
    ])->assertRedirect(route('owner.subscription', $company));

    expect($intent->fresh()->status)->toBe('paid')
        ->and($draft->fresh()->status)->toBe('active');
});

test('webhook endpoint is closed when test mode disabled', function () {
    $this->post(route('webhooks.razorpay'), ['event' => 'payment.captured'])->assertNotFound();
});

test('webhook endpoint processes signed razorpay payload when enabled', function () {
    config([
        'subscription.payments.gateway' => 'razorpay_test',
        'subscription.payments.razorpay.test_enabled' => true,
        'subscription.payments.razorpay.mode' => 'test',
        'subscription.payments.razorpay.key_id' => 'rzp_test_ci',
        'subscription.payments.razorpay.key_secret' => 'test_secret',
        'subscription.payments.razorpay.webhook_secret' => 'wh_secret',
        'subscription.payments.webhook_endpoint_enabled' => true,
    ]);

    Http::fake([
        'api.razorpay.com/v1/orders' => Http::response([
            'id' => 'order_Wh1',
            'amount' => 25000,
            'currency' => 'INR',
            'status' => 'created',
        ], 200),
    ]);

    ['company' => $company, 'draft' => $draft] = batchFOwnerDraft();
    $gateway = new RazorpayTestPaymentGateway(app(PaymentGatewayAuthorization::class));
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
    $sig = hash_hmac('sha256', $raw, 'wh_secret');

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
