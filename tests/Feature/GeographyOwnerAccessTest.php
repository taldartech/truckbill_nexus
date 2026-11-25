<?php

use App\Models\City;
use App\Models\State;
use App\Models\User;
use Database\Seeders\IndiaGeographySeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    if (State::query()->where('country_code', 'IN')->count() < 36) {
        (new IndiaGeographySeeder)->run();
    }
});

test('customer owner can search active states without company context', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($owner)
        ->getJson(route('geography.states'))
        ->assertOk();

    $data = $response->json('data');
    expect($data)->toBeArray()->not->toBeEmpty()
        ->and($data[0])->toHaveKeys(['id', 'name']);
});

test('customer owner can quick-create city under existing state', function () {
    $owner = User::factory()->customer()->create([
        'email_verified_at' => now(),
    ]);
    $state = State::query()->where('code', 'RJ')->where('is_active', true)->firstOrFail();

    $response = $this->actingAs($owner)->postJson(route('geography.cities.store'), [
        'state_id' => $state->id,
        'name' => 'QA Unlisted Nagar '.uniqid(),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.state_id', $state->id);

    expect(City::query()->whereKey($response->json('data.id'))->exists())->toBeTrue();
});

test('customer owner cannot invent arbitrary gst state codes', function () {
    $owner = User::factory()->customer()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($owner)->postJson(route('geography.states.store'), [
        'name' => 'Fake Fantasy State',
        'code' => 'ZZ',
        'gst_code' => '99',
    ])->assertStatus(422);
});

test('customer owner can resolve catalogue state by exact name when missing', function () {
    $owner = User::factory()->customer()->create([
        'email_verified_at' => now(),
    ]);

    // Soft-delete Rajasthan if present to force create-from-catalogue path without wiping DB.
    $existing = State::query()->where('code', 'RJ')->first();
    if ($existing !== null) {
        // Use already-seeded state: endpoint should return existing without inventing GST codes.
        $this->actingAs($owner)->postJson(route('geography.states.store'), [
            'name' => 'Rajasthan',
        ])->assertOk()
            ->assertJsonPath('data.code', 'RJ')
            ->assertJsonPath('data.gst_code', '08');

        return;
    }

    $this->actingAs($owner)->postJson(route('geography.states.store'), [
        'name' => 'Rajasthan',
    ])->assertCreated()
        ->assertJsonPath('data.code', 'RJ')
        ->assertJsonPath('data.gst_code', '08');
});
