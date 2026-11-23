<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

test('official truckbill logo assets are present', function () {
    expect(File::exists(public_path('brand/truckbill-logo.svg')))->toBeTrue();
    expect(File::exists(public_path('brand/truckbill-logo.png')))->toBeTrue();
});

test('operational business module routes remain absent', function () {
    $forbidden = [
        'broker.',
        'ownfleet.',
        'partload.',
        'accounting.',
        'permission.master',
    ];

    $names = collect(Route::getRoutes())->map->getName()->filter()->values();

    foreach ($forbidden as $needle) {
        expect(
            $names->contains(fn (?string $name): bool => $name !== null && str_contains($name, $needle))
        )->toBeFalse();
    }

    expect(Route::has('owner.dashboard'))->toBeTrue()
        ->and(Route::has('branch.dashboard'))->toBeTrue()
        ->and(Route::has('workspace.landing'))->toBeTrue();
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

test('authenticated internal dashboard remains a technical shell', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('auth.user.is_super_admin', false));

    $source = File::get(resource_path('js/pages/dashboard.tsx'));
    expect($source)->toContain('ShellEmptyState');
    expect($source)->toContain('Platform administration');
});

test('internal sidebar exposes Batch G admin screens with super-admin invoice gate', function () {
    $sidebar = File::get(resource_path('js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain("from '@/routes/admin'");
    expect($sidebar)->toContain('gstRates');
    expect($sidebar)->toContain('tdsRules');
    expect($sidebar)->toContain('subscriptionInvoices');
    expect($sidebar)->toContain('admin.companies.index');
    expect($sidebar)->toContain('admin.identities.index');
    expect($sidebar)->toContain('admin.internalUsers.index');
    expect($sidebar)->toContain('is_super_admin');

    $super = User::factory()->superAdmin()->create();
    $this->actingAs($super)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.is_super_admin', true));
});

test('owner subscription invoices list wires sample email action when available', function () {
    $source = File::get(resource_path('js/pages/owner/subscription/invoices.tsx'));

    expect($source)->toContain('invoice.email_url');
    expect($source)->toContain('method="post"');
    expect($source)->toContain('Email');
});

test('orphan documents document-drawer component is removed', function () {
    expect(File::exists(resource_path('js/components/documents/document-drawer.tsx')))->toBeFalse();
    expect(File::exists(resource_path('js/components/masters/document-drawer.tsx')))->toBeTrue();
});

test('vite font configuration uses poppins', function () {
    $vite = File::get(base_path('vite.config.ts'));
    expect($vite)->toContain("bunny('Poppins'");
    expect($vite)->not->toContain("bunny('Instrument Sans'");
});
