<?php

require_once __DIR__.'/../../Support/TyreLayoutFixtures.php';

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\TyreLayout\TyreLayoutService;
use App\Models\OwnerTyreLayoutPosition;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    TenantContextHolder::clear();
    $this->seed(RbacSeeder::class);
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function (): void {
    TenantContextHolder::clear();
});

it('creates tyre layout with derived positions and stable keys', function (): void {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Tyre Layout Co');
    $branch = $company->branches()->firstOrFail();

    $layout = app(TyreLayoutService::class)->create($owner, $company, [
        'name' => '6x2 Haul',
        'spare_tyre_count' => 1,
        'axles' => [
            ['sequence' => 1, 'configuration' => 'single'],
            ['sequence' => 2, 'configuration' => 'dual'],
        ],
    ]);

    expect($layout->drive_tyre_count)->toBe(6)
        ->and($layout->needs_axle_completion)->toBeFalse()
        ->and($layout->positions()->where('is_active', true)->count())->toBe(7);

    $position = OwnerTyreLayoutPosition::query()
        ->where('tyre_layout_id', $layout->id)
        ->where('position_key', 'A2-LI')
        ->firstOrFail();

    expect($position->label)->toBe('Axle 2 Left Inner');
});

it('preserves position ids when axle layout changes but keys remain', function (): void {
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Tyre Preserve Co');
    $layout = createOwnerTyreLayout($owner, 'Preserve Layout', [
        ['sequence' => 1, 'configuration' => 'single'],
    ]);

    $left = OwnerTyreLayoutPosition::query()
        ->where('tyre_layout_id', $layout->id)
        ->where('position_key', 'A1-L')
        ->firstOrFail();

    $updated = app(TyreLayoutService::class)->update(
        $owner,
        $layout,
        $company,
        [
            'axles' => [
                ['sequence' => 1, 'configuration' => 'single'],
                ['sequence' => 2, 'configuration' => 'dual'],
            ],
        ],
        'Add rear axle',
    );

    expect($updated->positions()->where('is_active', true)->count())->toBe(7);

    $sameLeft = OwnerTyreLayoutPosition::query()->findOrFail($left->id);
    expect($sameLeft->is_active)->toBeTrue()
        ->and($sameLeft->position_key)->toBe('A1-L');
});

it('quick-creates tyre layout via json for vehicle workflows', function (): void {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Tyre Quick Co');
    $branch = $company->branches()->firstOrFail();

    $this->flushSession();
    $this->actingAs($owner);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($company, $branch);

    $response = $this->postJson(route('branch.tyre-layouts.store'), [
        'name' => 'Inline Layout',
        'spare_tyre_count' => 0,
        'axles' => [
            ['sequence' => 1, 'configuration' => 'dual'],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Inline Layout');
});
