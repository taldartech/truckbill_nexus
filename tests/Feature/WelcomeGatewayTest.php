<?php

use App\Models\User;

test('unauthenticated visitors see branded TruckBill welcome gateway', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->has('website_url')
            ->has('demo_url')
            ->has('policies.privacy')
            ->has('policies.terms')
            ->where('support_email', 'support@truckbill.in'));
});

test('authenticated internal users are redirected by workspace landing resolver', function () {
    $sa = User::factory()->superAdmin()->create();

    $this->actingAs($sa)
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
