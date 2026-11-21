<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

test('official truckbill logo assets are present', function () {
    expect(File::exists(public_path('brand/truckbill-logo.svg')))->toBeTrue();
    expect(File::exists(public_path('brand/truckbill-logo.png')))->toBeTrue();
});

test('product panel and business module routes are absent', function () {
    $forbidden = [
        'owner.panel',
        'branch.panel',
        'broker.',
        'ownfleet.',
        'partload.',
        'accounting.',
        'subscription.',
        'permission.master',
    ];

    $names = collect(Route::getRoutes())->map->getName()->filter()->values();

    foreach ($forbidden as $needle) {
        expect(
            $names->contains(fn (?string $name): bool => $name !== null && str_contains($name, $needle))
        )->toBeFalse();
    }
});

test('profile settings omit self-service email change and account deletion controls', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/profile'));

    $source = File::get(resource_path('js/pages/settings/profile.tsx'));
    expect($source)->toContain('Email cannot be changed from this page');
    expect($source)->toContain('disabled');
    expect($source)->toContain('readOnly');
    expect($source)->not->toContain('Delete account');
    expect($source)->not->toContain('destroy');
});

test('css brand tokens include truckbill palette and poppins', function () {
    $css = File::get(resource_path('css/app.css'));

    expect($css)->toContain('Poppins');
    expect($css)->toContain('#2fb36f');
    expect($css)->toContain('#2d8cff');
    expect($css)->toContain('--brand-gradient');
});

test('authenticated workspace shell renders technical empty state component', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('dashboard'));

    $source = File::get(resource_path('js/pages/dashboard.tsx'));
    expect($source)->toContain('No product modules yet');
    expect($source)->toContain('ShellEmptyState');
    expect($source)->toContain('technical shell only');
});

test('vite font configuration uses poppins', function () {
    $vite = File::get(base_path('vite.config.ts'));
    expect($vite)->toContain("bunny('Poppins'");
    expect($vite)->not->toContain("bunny('Instrument Sans'");
});
