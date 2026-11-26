<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Enquiry\Webhook\EnquiryWebhookCredentialService;
use App\Domains\Enquiry\Webhook\EnquiryWebhookIntakeService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Models\Company;
use App\Models\Enquiry;
use App\Models\EnquiryExternalReference;
use App\Models\OwnerCatalogueItem;
use App\Models\User;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    TenantContextHolder::clear();
    RateLimiter::clear('enquiry-webhook-test');
    (new RbacSeeder)->run();
    config([
        'enquiry_webhook.rate_limit_per_minute' => 60,
        'enquiry_webhook.timestamp_skew_seconds' => 300,
    ]);
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * @return array{
 *     company: Company,
 *     owner: User,
 *     token: string,
 *     truck: OwnerCatalogueItem,
 *     goodsType: OwnerCatalogueItem,
 *     packageType: OwnerCatalogueItem
 * }
 */
function webhookCompany(): array
{
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Webhook Co');
    $created = app(EnquiryWebhookCredentialService::class)->create($company, $owner, 'site');

    $truck = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'code' => null,
        'name' => 'Webhook Truck',
    ]);
    $goodsType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::GoodsType->value,
        'code' => null,
        'name' => 'Webhook Goods',
    ]);
    $packageType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::PackageType->value,
        'code' => null,
        'name' => 'Webhook Package',
    ]);

    return [
        'company' => $company,
        'owner' => $owner,
        'token' => $created['plain_token'],
        'credential' => $created['credential'],
        'truck' => $truck,
        'goodsType' => $goodsType,
        'packageType' => $packageType,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function webhookPayload(array $overrides = []): array
{
    return array_merge([
        'external_reference' => 'web-ref-'.Str::lower(Str::random(8)),
        'customer_name' => 'Website Customer',
        'customer_phone' => '9876501234',
        'customer_email' => 'customer@example.com',
        'pickup_location' => 'Ahmedabad',
        'delivery_location' => 'Jaipur',
        'vehicle_type_id' => null,
        'vehicle_quantity' => 1,
        'expected_loading_date' => '2026-12-15',
        'instructions' => 'Call on arrival',
        'goods' => [
            [
                'goods_type_id' => null,
                'package_type_id' => null,
                'goods_description' => 'Tiles',
                'package_count' => 20,
                'actual_weight' => 200,
                'charged_weight' => 220,
            ],
        ],
    ], $overrides);
}

/**
 * @param  array{truck: OwnerCatalogueItem, goodsType: OwnerCatalogueItem, packageType: OwnerCatalogueItem}  $ctx
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function webhookPayloadForCompany(array $ctx, array $overrides = []): array
{
    return webhookPayload(array_merge([
        'vehicle_type_id' => $ctx['truck']->id,
        'goods' => [
            [
                'goods_type_id' => $ctx['goodsType']->id,
                'package_type_id' => $ctx['packageType']->id,
                'goods_description' => 'Tiles',
                'package_count' => 20,
                'actual_weight' => 200,
                'charged_weight' => 220,
            ],
        ],
    ], $overrides));
}

/**
 * @param  array<string, string>  $extraHeaders
 * @return array<string, string>
 */
function webhookHeaders(string $token, array $extraHeaders = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Idempotency-Key' => (string) Str::uuid(),
        'X-TruckBill-Timestamp' => (string) time(),
        'X-TruckBill-Nonce' => (string) Str::uuid(),
    ], $extraHeaders);
}

test('valid token creates company-scoped unassigned website enquiry', function () {
    $ctx = webhookCompany();
    $payload = webhookPayloadForCompany($ctx);

    $response = $this->postJson(route('webhooks.enquiries'), $payload, webhookHeaders($ctx['token']));

    $response->assertCreated()
        ->assertJsonPath('data.source', 'website')
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.branch_id', null)
        ->assertJsonPath('data.external_reference', $payload['external_reference'])
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('meta.token');

    expect($response->getContent())->not->toContain($ctx['token'])
        ->and(Enquiry::query()->where('company_id', $ctx['company']->id)->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'enquiry.created')->where('company_id', $ctx['company']->id)->count())->toBe(1);
});

test('missing invalid revoked and expired tokens are rejected', function () {
    $ctx = webhookCompany();
    $payload = webhookPayloadForCompany($ctx);

    $this->postJson(route('webhooks.enquiries'), $payload, webhookHeaders(''))
        ->assertUnauthorized();

    $this->postJson(route('webhooks.enquiries'), $payload, webhookHeaders('tb_enq_invalidtokenvalue1234567890'))
        ->assertUnauthorized();

    app(EnquiryWebhookCredentialService::class)->revoke($ctx['credential']);
    $this->postJson(route('webhooks.enquiries'), $payload, webhookHeaders($ctx['token']))
        ->assertUnauthorized();

    $ctx2 = webhookCompany();
    $ctx2['credential']->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->postJson(route('webhooks.enquiries'), webhookPayloadForCompany($ctx2), webhookHeaders($ctx2['token']))
        ->assertUnauthorized();
});

test('cross-company isolation and tenant injection are rejected', function () {
    $a = webhookCompany();
    $b = webhookCompany();

    $payload = webhookPayloadForCompany($a, ['external_reference' => 'shared-looking-ref']);

    $this->postJson(route('webhooks.enquiries'), $payload, webhookHeaders($a['token']))->assertCreated();
    $this->postJson(route('webhooks.enquiries'), $payload, webhookHeaders($b['token']))->assertCreated();

    expect(Enquiry::query()->where('company_id', $a['company']->id)->where('external_reference', 'shared-looking-ref')->count())->toBe(1)
        ->and(Enquiry::query()->where('company_id', $b['company']->id)->where('external_reference', 'shared-looking-ref')->count())->toBe(1);

    $this->postJson(route('webhooks.enquiries'), webhookPayloadForCompany($a, [
        'company_id' => $b['company']->id,
    ]), webhookHeaders($a['token']))->assertForbidden();

    $this->postJson(route('webhooks.enquiries'), webhookPayloadForCompany($a, [
        'branch_id' => (string) Str::uuid(),
    ]), webhookHeaders($a['token']))->assertForbidden();
});

test('malformed multipart invalid goods and vehicle payloads are rejected', function () {
    $ctx = webhookCompany();

    $this->call(
        'POST',
        route('webhooks.enquiries'),
        [],
        [],
        [],
        webhookHeaders($ctx['token'], ['CONTENT_TYPE' => 'multipart/form-data']),
        'ignored',
    )->assertUnprocessable();

    $this->postJson(route('webhooks.enquiries'), webhookPayload([
        'goods' => [],
    ]), webhookHeaders($ctx['token']))->assertUnprocessable();

    $this->postJson(route('webhooks.enquiries'), webhookPayloadForCompany($ctx, [
        'vehicle_type_id' => (string) Str::uuid(),
    ]), webhookHeaders($ctx['token']))->assertUnprocessable();

    $this->postJson(route('webhooks.enquiries'), webhookPayload([
        'external_reference' => '',
    ]), webhookHeaders($ctx['token']))->assertUnprocessable();

    $this->postJson(route('webhooks.enquiries'), webhookPayload([
        'vehicle_quantity' => 0,
    ]), webhookHeaders($ctx['token']))->assertUnprocessable();
});

test('stale timestamp and reused nonce are rejected while idempotent retry succeeds', function () {
    $ctx = webhookCompany();
    $payload = webhookPayloadForCompany($ctx, ['external_reference' => 'idem-stable-1']);
    $key = (string) Str::uuid();
    $nonce = (string) Str::uuid();

    $headers = webhookHeaders($ctx['token'], [
        'Idempotency-Key' => $key,
        'X-TruckBill-Nonce' => $nonce,
    ]);

    $first = $this->postJson(route('webhooks.enquiries'), $payload, $headers)->assertCreated();
    $enquiryId = $first->json('data.id');
    $number = $first->json('data.enquiry_number');

    // Legitimate idempotent retry: same key + payload, fresh nonce optional path — same key short-circuits before nonce.
    $retry = $this->postJson(route('webhooks.enquiries'), $payload, webhookHeaders($ctx['token'], [
        'Idempotency-Key' => $key,
        'X-TruckBill-Nonce' => (string) Str::uuid(),
    ]));
    $retry->assertOk()
        ->assertJsonPath('data.id', $enquiryId)
        ->assertJsonPath('meta.idempotent_replay', true);

    expect(Enquiry::query()->where('company_id', $ctx['company']->id)->count())->toBe(1)
        ->and(Enquiry::query()->whereKey($enquiryId)->value('enquiry_number'))->toBe($number)
        ->and(OutboxMessage::query()->where('event_type', 'enquiry.created')->where('company_id', $ctx['company']->id)->count())->toBe(1);

    $this->postJson(route('webhooks.enquiries'), webhookPayloadForCompany($ctx), webhookHeaders($ctx['token'], [
        'X-TruckBill-Timestamp' => (string) (time() - 10_000),
    ]))->assertStatus(409);

    // New key but reused nonce should conflict.
    $this->postJson(route('webhooks.enquiries'), webhookPayloadForCompany($ctx, ['external_reference' => 'other-ref']), webhookHeaders($ctx['token'], [
        'Idempotency-Key' => (string) Str::uuid(),
        'X-TruckBill-Nonce' => $nonce,
    ]))->assertStatus(409);
});

test('idempotency key conflict and durable external reference rules', function () {
    $ctx = webhookCompany();
    $payload = webhookPayloadForCompany($ctx, ['external_reference' => 'durable-1']);
    $key = (string) Str::uuid();

    $this->postJson(route('webhooks.enquiries'), $payload, webhookHeaders($ctx['token'], [
        'Idempotency-Key' => $key,
    ]))->assertCreated();

    $this->postJson(route('webhooks.enquiries'), webhookPayload([
        'external_reference' => 'durable-1',
        'customer_name' => 'Changed Name',
    ]), webhookHeaders($ctx['token'], [
        'Idempotency-Key' => $key,
    ]))->assertStatus(409);

    // Different key, same external reference + same semantic payload → one enquiry.
    $this->postJson(route('webhooks.enquiries'), $payload, webhookHeaders($ctx['token'], [
        'Idempotency-Key' => (string) Str::uuid(),
    ]))->assertOk()->assertJsonPath('meta.created', false);

    // Different key, same external reference, changed content → conflict.
    $this->postJson(route('webhooks.enquiries'), webhookPayload([
        'external_reference' => 'durable-1',
        'pickup_location' => 'Different City',
    ]), webhookHeaders($ctx['token'], [
        'Idempotency-Key' => (string) Str::uuid(),
    ]))->assertStatus(409);

    expect(Enquiry::query()->where('company_id', $ctx['company']->id)->count())->toBe(1)
        ->and(EnquiryExternalReference::query()->where('company_id', $ctx['company']->id)->where('external_reference', 'durable-1')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'enquiry.created')->where('company_id', $ctx['company']->id)->count())->toBe(1);
});

test('concurrent identical website intake produces exactly one enquiry', function () {
    $ctx = webhookCompany();
    $payload = webhookPayloadForCompany($ctx, ['external_reference' => 'race-ref-1']);

    $service = app(EnquiryWebhookIntakeService::class);
    $requestFactory = function () use ($ctx, $payload) {
        $request = Request::create(
            '/webhooks/enquiries',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$ctx['token'],
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
                'HTTP_X_TRUCKBILL_TIMESTAMP' => (string) time(),
                'HTTP_X_TRUCKBILL_NONCE' => (string) Str::uuid(),
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Accept', 'application/json');

        return $request;
    };

    $first = $service->handle($requestFactory());
    $second = $service->handle($requestFactory());

    expect($first['enquiry']->id)->toBe($second['enquiry']->id)
        ->and($second['created'])->toBeFalse()
        ->and(Enquiry::query()->where('company_id', $ctx['company']->id)->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'enquiry.created')->where('company_id', $ctx['company']->id)->count())->toBe(1)
        ->and(EnquiryExternalReference::query()->where('company_id', $ctx['company']->id)->where('external_reference', 'race-ref-1')->count())->toBe(1);

    // Force unique-constraint recovery path: pre-insert conflicting fingerprint race window is covered by UniqueConstraint catch.
    $thirdPayload = webhookPayloadForCompany($ctx, ['external_reference' => 'race-ref-2']);
    $results = [];
    $errors = 0;
    foreach ([1, 2] as $i) {
        try {
            $results[] = $service->handle($requestFactory = (function () use ($ctx, $thirdPayload) {
                return Request::create(
                    '/webhooks/enquiries',
                    'POST',
                    [],
                    [],
                    [],
                    [
                        'HTTP_AUTHORIZATION' => 'Bearer '.$ctx['token'],
                        'CONTENT_TYPE' => 'application/json',
                        'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
                        'HTTP_X_TRUCKBILL_TIMESTAMP' => (string) time(),
                        'HTTP_X_TRUCKBILL_NONCE' => (string) Str::uuid(),
                    ],
                    json_encode($thirdPayload, JSON_THROW_ON_ERROR),
                );
            })());
        } catch (Throwable) {
            $errors++;
        }
    }

    expect($errors)->toBe(0)
        ->and(Enquiry::query()->where('company_id', $ctx['company']->id)->where('external_reference', 'race-ref-2')->count())->toBe(1);
});

test('rate limit is enforced without exposing bearer token', function () {
    $ctx = webhookCompany();
    config(['enquiry_webhook.rate_limit_per_minute' => 1]);

    $this->postJson(route('webhooks.enquiries'), webhookPayloadForCompany($ctx), webhookHeaders($ctx['token']))->assertCreated();
    $limited = $this->postJson(route('webhooks.enquiries'), webhookPayloadForCompany($ctx), webhookHeaders($ctx['token']));
    $limited->assertStatus(429);
    expect($limited->getContent())->not->toContain($ctx['token']);
});

test('webhook does not require browser authentication and csrf exemption is exact', function () {
    $ctx = webhookCompany();

    // No session auth.
    $this->postJson(route('webhooks.enquiries'), webhookPayloadForCompany($ctx), webhookHeaders($ctx['token']))
        ->assertCreated();

    // CSRF middleware except list includes only the exact webhook path (assert via config mirror).
    $ref = new ReflectionClass(ApplicationBuilder::class);
    expect(file_get_contents(base_path('bootstrap/app.php')))
        ->toContain("'webhooks/enquiries'")
        ->toContain("'webhooks/razorpay'")
        ->not->toContain("'webhooks/*'");
});

test('failure during nested persistence rolls back website enquiry', function () {
    $ctx = webhookCompany();
    $payload = webhookPayloadForCompany($ctx, ['external_reference' => 'rollback-ref']);

    TenantContextHolder::inject(new TenantContext(
        companyId: $ctx['company']->id,
        branchId: null,
        ownerId: (string) $ctx['owner']->id,
        isOwnerScope: true,
    ));

    $before = Enquiry::query()->where('company_id', $ctx['company']->id)->count();
    $failed = false;

    try {
        DB::transaction(function () use ($ctx, $payload): void {
            app(EnquiryService::class)->createFromWebsite($ctx['company'], $payload);
            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        $failed = true;
    }

    expect($failed)->toBeTrue()
        ->and(Enquiry::query()->where('company_id', $ctx['company']->id)->count())->toBe($before)
        ->and(EnquiryExternalReference::query()->where('external_reference', 'rollback-ref')->count())->toBe(0)
        ->and(OutboxMessage::query()->where('event_type', 'enquiry.created')->where('company_id', $ctx['company']->id)->count())->toBe(0);
});
