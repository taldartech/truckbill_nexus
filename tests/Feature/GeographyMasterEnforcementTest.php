<?php

use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\LeadService;
use App\Domains\Geography\GeographyLookupService;
use App\Models\City;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\State;
use App\Models\User;
use Database\Seeders\IndiaGeographySeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed(IndiaGeographySeeder::class);
});

/**
 * @return array{state_id: string, city_id: string, state: string, city: string}
 */
function enforcementGeography(): array
{
    return geographyIdsFor('Maharashtra', 'Mumbai');
}

/**
 * @return array<string, mixed>
 */
function enforcementCompanyPayload(array $overrides = []): array
{
    $rate = GstRate::query()->firstOrCreate(
        ['rate_percent' => '18.000', 'version' => 1],
        [
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
        ],
    );
    $geo = enforcementGeography();

    return array_merge([
        'name' => 'Master Geo Co '.uniqid(),
        'pan' => 'ABCDE1234F',
        'registered_address' => '12 Logistics Park',
        'state_id' => $geo['state_id'],
        'city_id' => $geo['city_id'],
        'pin' => '400001',
        'contact_person' => 'Owner',
        'mobile' => '9876543210',
        'email' => 'owner.geo.'.uniqid().'@example.com',
        'gst_registration_type' => GstRegistrationType::Regular->value,
        'tax_liability' => TaxLiability::Fcm->value,
        'gstin' => '27ABCDE1234F1Z5',
        'gst_rate_id' => $rate->id,
        'data_visibility' => DataVisibility::SharedData->value,
        'timezone' => 'Asia/Kolkata',
    ], $overrides);
}

test('company creation requires valid state and city master ids', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('secret'),
        'email_verified_at' => now(),
    ]);
    $this->actingAs($owner);

    $this->post(route('owner.companies.store'), enforcementCompanyPayload([
        'state_id' => null,
        'city_id' => null,
        'state' => 'Maharashtra',
        'city' => 'Mumbai',
    ]))->assertSessionHasErrors(['state_id', 'city_id']);

    expect(Company::query()->where('name', 'like', 'Master Geo Co%')->exists())->toBeFalse();
});

test('company creation rejects arbitrary geographic text as a substitute for ids', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('secret'),
        'email_verified_at' => now(),
    ]);
    $this->actingAs($owner);

    $this->post(route('owner.companies.store'), array_merge(
        enforcementCompanyPayload(),
        [
            'state_id' => 'custom:Fantasyland',
            'city_id' => 'custom:Nowhere',
            'state' => 'Fantasyland',
            'city' => 'Nowhere',
        ],
    ))->assertSessionHasErrors(['state_id', 'city_id']);
});

test('company creation rejects mismatched state and city masters', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('secret'),
        'email_verified_at' => now(),
    ]);
    $this->actingAs($owner);

    $mh = State::query()->where('code', 'MH')->where('is_active', true)->firstOrFail();
    $kaCity = City::query()
        ->whereHas('state', fn ($q) => $q->where('code', 'KA'))
        ->where('is_active', true)
        ->firstOrFail();

    $this->post(route('owner.companies.store'), enforcementCompanyPayload([
        'state_id' => $mh->id,
        'city_id' => $kaCity->id,
    ]))->assertSessionHasErrors();

    expect(Company::query()->where('owner_user_id', $owner->id)->exists())->toBeFalse();
});

test('company creation persists authoritative master names not client text', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('secret'),
        'email_verified_at' => now(),
    ]);
    $this->actingAs($owner);

    $payload = enforcementCompanyPayload([
        'state' => 'CLIENT-FORGED-STATE',
        'city' => 'CLIENT-FORGED-CITY',
    ]);

    $this->post(route('owner.companies.store'), $payload)->assertRedirect();

    $company = Company::query()->where('owner_user_id', $owner->id)->firstOrFail();
    expect($company->state_id)->toBe($payload['state_id'])
        ->and($company->city_id)->toBe($payload['city_id'])
        ->and($company->state)->toBe('Maharashtra')
        ->and($company->city)->not->toBe('CLIENT-FORGED-CITY')
        ->and($company->city)->not->toBeEmpty();
});

test('resolveAddressFields has no text fallback for master entities', function () {
    $service = new GeographyLookupService;

    $resolved = $service->resolveAddressFields(null, null);
    expect($resolved)->toBe([
        'state_id' => null,
        'city_id' => null,
        'state' => null,
        'city' => null,
    ]);

    expect(fn () => $service->resolveAddressFields('not-a-uuid', null))
        ->toThrow(ValidationException::class);
});

test('crm leads still accept custom geographic text without creating masters', function () {
    $sa = User::factory()->superAdmin()->create();
    $beforeCities = City::query()->count();
    $beforeStates = State::query()->count();

    $lead = app(LeadService::class)->create($sa, [
        'contact_name' => 'Custom Geo Lead',
        'email' => 'custom.geo.'.uniqid().'@example.com',
        'state' => 'Unlisted Province',
        'city' => 'Unlisted Hamlet',
        'source' => LeadSource::Website->value,
        'interested_product' => InterestedProduct::Broker->value,
    ]);

    expect($lead->state)->toBe('Unlisted Province')
        ->and($lead->city)->toBe('Unlisted Hamlet')
        ->and(State::query()->count())->toBe($beforeStates)
        ->and(City::query()->count())->toBe($beforeCities);
});

test('public website lead capture accepts unknown city as text', function () {
    config([
        'public_leads.api_key' => 'test-website-lead-key',
        'public_leads.rate_limit_per_minute' => 60,
        'mail.default' => 'array',
    ]);

    $beforeCities = City::query()->count();

    $this->postJson(route('api.public.leads.store'), [
        'name' => 'Website Prospect',
        'email' => 'website.geo.'.uniqid().'@example.com',
        'mobile' => '9876501234',
        'company_name' => 'Website Co',
        'state' => 'Rajasthan',
        'city' => 'Totally Unknown Village XYZ',
        'product_interested_in' => 'broker',
        'source' => 'website',
    ], [
        'Accept' => 'application/json',
        'X-Api-Key' => 'test-website-lead-key',
    ])->assertSuccessful();

    expect(City::query()->count())->toBe($beforeCities);
});
