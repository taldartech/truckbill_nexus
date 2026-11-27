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

function actAsStationOwnerInBranch(): void
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Station QA Co');
    $branch = $company->branches()->firstOrFail();

    test()->flushSession();
    test()->actingAs($owner);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($company, $branch);
}

it('requires master state and city when creating a station', function (): void {
    actAsStationOwnerInBranch();

    $this->postJson(route('branch.stations.store'), ['name' => 'No Geography Hub'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['state_id', 'city_id']);
});

it('creates station via json quick-create without code', function (): void {
    actAsStationOwnerInBranch();

    $geo = geographyIdsFor();

    $response = $this->postJson(route('branch.stations.store'), [
        'name' => 'Andheri Hub',
        'state_id' => $geo['state_id'],
        'city_id' => $geo['city_id'],
        'remark' => 'Quick create',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Andheri Hub')
        ->assertJsonMissingPath('data.code');

    $station = Station::query()->findOrFail($response->json('data.id'));
    expect($station->name)->toBe('Andheri Hub');
    expect($station->getAttributes())->not->toHaveKey('code');
});
