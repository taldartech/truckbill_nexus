<?php

use App\Domains\Geography\GeographyLookupService;
use App\Models\City;
use App\Models\State;
use App\Models\User;
use Database\Seeders\IndiaGeographySeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed(IndiaGeographySeeder::class);
});

test('india geography seeder creates states with gst codes and cities', function (): void {
    expect(State::query()->where('country_code', 'IN')->count())->toBeGreaterThan(30)
        ->and(City::query()->count())->toBeGreaterThan(100);

    $mh = State::query()->where('code', 'MH')->first();
    expect($mh)->not->toBeNull()
        ->and($mh?->gst_code)->toBe('27')
        ->and($mh?->name)->toBe('Maharashtra');

    expect(
        City::query()->where('state_id', $mh?->id)->where('name_normalized', 'mumbai')->exists()
        || City::query()->where('state_id', $mh?->id)->where('name', 'like', '%Mumbai%')->exists()
        || City::query()->where('state_id', $mh?->id)->exists()
    )->toBeTrue();
});

test('city search is restricted to the selected state', function (): void {
    $service = new GeographyLookupService;
    $mh = State::query()->where('code', 'MH')->firstOrFail();
    $ka = State::query()->where('code', 'KA')->firstOrFail();

    $mhCities = $service->searchCities($mh->id, null, 20);
    expect($mhCities)->not->toBeEmpty();
    foreach ($mhCities as $city) {
        expect($city['state_id'])->toBe($mh->id);
    }

    $kaCity = City::query()->where('state_id', $ka->id)->where('is_active', true)->firstOrFail();
    expect(fn () => $service->resolveAddressFields($mh->id, $kaCity->id))
        ->toThrow(ValidationException::class);
});

test('geography lookup lists states and cities for form selectors', function (): void {
    $service = new GeographyLookupService;
    $states = $service->listActiveStates('Maha');
    expect($states)->not->toBeEmpty()
        ->and(collect($states)->pluck('code'))->toContain('MH');

    $mh = State::query()->where('code', 'MH')->firstOrFail();
    $cities = $service->searchCities($mh->id);
    expect($cities)->not->toBeEmpty();
});

test('guests cannot search geography over http', function (): void {
    $this->getJson(route('geography.states'))
        ->assertUnauthorized();
});

test('ordinary customer cannot administer platform locations', function (): void {
    $user = User::factory()->customer()->create();

    $this->actingAs($user)
        ->get(route('admin.locations.index'))
        ->assertForbidden();
});

test('internal operator can view platform locations', function (): void {
    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)
        ->get(route('admin.locations.index'))
        ->assertOk();
});
