<?php

use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Models\City;
use App\Models\Crm\Lead;
use App\Models\State;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config([
        'public_leads.api_key' => 'test-website-lead-key',
        'public_leads.rate_limit_per_minute' => 60,
        'public_leads.append_enquiry_on_open_duplicate' => true,
        'mail.default' => 'array',
    ]);
});

function publicLeadHeaders(array $extra = []): array
{
    return array_merge([
        'Accept' => 'application/json',
        'X-Api-Key' => 'test-website-lead-key',
    ], $extra);
}

test('public lead capture requires api key', function () {
    $this->postJson(route('api.public.leads.store'), [
        'name' => 'Visitor',
        'email' => 'visitor@example.com',
    ])->assertUnauthorized();
});

test('public lead capture creates website lead with text geography', function () {
    $response = $this->postJson(route('api.public.leads.store'), [
        'name' => 'Shalin Demo',
        'company_name' => 'Taldar Logistics',
        'email' => 'shalin.demo@example.com',
        'mobile' => '9876543210',
        'state' => 'Rajasthan',
        'city' => 'Unknown Town',
        'product_interested_in' => 'broker',
        'source' => 'website_demo',
        'remarks' => 'Please schedule a demo.',
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'spring',
        'landing_url' => 'https://truckbill.in/?utm_source=google',
    ], publicLeadHeaders([
        'Idempotency-Key' => 'idem-demo-1',
    ]));

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['receipt', 'meta' => ['created', 'replayed', 'appended']]);

    $lead = Lead::query()->where('email', 'shalin.demo@example.com')->firstOrFail();
    expect($lead->source)->toBe(LeadSource::Website)
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->interested_product)->toBe(InterestedProduct::Broker)
        ->and($lead->state)->toBe('Rajasthan')
        ->and($lead->city)->toBe('Unknown Town')
        ->and($lead->source_channel)->toBe('website_demo')
        ->and($lead->utm_campaign)->toBe('spring')
        ->and($lead->assigned_to_user_id)->toBeNull()
        ->and($lead->created_by_user_id)->toBeNull()
        ->and(City::query()->where('name', 'Unknown Town')->exists())->toBeFalse()
        ->and(State::query()->where('name', 'Rajasthan')->exists())->toBeFalse();
});

test('public lead capture rejects privileged fields', function () {
    $this->postJson(route('api.public.leads.store'), [
        'name' => 'Hacker',
        'email' => 'hacker@example.com',
        'status' => 'converted',
        'assigned_to_user_id' => 1,
    ], publicLeadHeaders())->assertStatus(422);
});

test('public lead capture replays idempotent requests', function () {
    $payload = [
        'name' => 'Retry User',
        'email' => 'retry.user@example.com',
        'product_interested_in' => 'ownfleet',
        'source' => 'website_contact',
        'remarks' => 'Same submission',
        'idempotency_key' => 'fixed-idem-key-99',
    ];

    $first = $this->postJson(route('api.public.leads.store'), $payload, publicLeadHeaders())
        ->assertCreated();

    $second = $this->postJson(route('api.public.leads.store'), $payload, publicLeadHeaders())
        ->assertOk();

    expect($second->json('receipt'))->toBe($first->json('receipt'))
        ->and($second->json('meta.replayed'))->toBeTrue()
        ->and(Lead::query()->where('email', 'retry.user@example.com')->count())->toBe(1);
});

test('public lead capture appends enquiry on open duplicate', function () {
    $payload = [
        'name' => 'Returning Prospect',
        'email' => 'returning@example.com',
        'product_interested_in' => 'broker',
        'source' => 'website_contact',
        'remarks' => 'First enquiry',
        'idempotency_key' => 'dup-first',
    ];

    $this->postJson(route('api.public.leads.store'), $payload, publicLeadHeaders())
        ->assertCreated();

    $again = $this->postJson(route('api.public.leads.store'), [
        ...$payload,
        'remarks' => 'Second enquiry about pricing',
        'idempotency_key' => 'dup-second',
    ], publicLeadHeaders())->assertOk();

    expect($again->json('meta.appended'))->toBeTrue()
        ->and(Lead::query()->where('email', 'returning@example.com')->count())->toBe(1);

    $lead = Lead::query()->where('email', 'returning@example.com')->firstOrFail();
    expect($lead->activities()->where('activity_type', 'website_enquiry')->count())->toBeGreaterThanOrEqual(2);
});

test('public lead capture rate limits abusive clients', function () {
    config(['public_leads.rate_limit_per_minute' => 1]);

    $apiPrefix = substr((string) config('public_leads.api_key'), 0, 12);
    $candidates = ['127.0.0.1', '::1', '0.0.0.0'];
    foreach ($candidates as $ip) {
        RateLimiter::clear('public-leads:'.sha1($ip.'|'.$apiPrefix));
    }

    $this->postJson(route('api.public.leads.store'), [
        'name' => 'A',
        'email' => 'rate1@example.com',
    ], publicLeadHeaders())->assertCreated();

    $this->postJson(route('api.public.leads.store'), [
        'name' => 'B',
        'email' => 'rate2@example.com',
    ], publicLeadHeaders())->assertStatus(429);
});
