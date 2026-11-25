<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Models\Station;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    TenantContextHolder::clear();
    $this->seed(RbacSeeder::class);
    (new SubscriptionCatalogueSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function (): void {
    TenantContextHolder::clear();
});

it('creates station via json quick-create and derives code from name', function (): void {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Station QA Co');
    $branch = $company->branches()->firstOrFail();

    $this->flushSession();
    $this->actingAs($owner);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($company, $branch);

    $response = $this->postJson(route('branch.stations.store'), [
        'name' => 'Andheri Hub',
        'remark' => 'Quick create',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Andheri Hub');

    $station = Station::query()->findOrFail($response->json('data.id'));
    expect($station->code)->toBe('ANDHERI-HUB')
        ->and($station->name)->toBe('Andheri Hub');
});
