<?php

use App\Models\Branch;
use App\Models\City;
use App\Models\Company;
use App\Models\State;
use App\Support\Workspace\WorkspaceSession;
use Database\Seeders\IndiaGeographySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature')
    ->beforeEach(function (): void {
        // MySQL CI job does not run Vite build; Inertia HTML responses must not require the manifest.
        $this->withoutVite();
        // Party/company address forms resolve State/City names against Masters.
        ensureIndiaGeography();
    });

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Relative path for an internal named route (context redirect payloads).
 *
 * @param  array<string, mixed>  $parameters
 */
function internalRoute(string $name, array $parameters = []): string
{
    return route($name, $parameters, absolute: false);
}

/**
 * Seed the India State/City masters when the disposable test database is empty.
 */
function ensureIndiaGeography(): void
{
    if (State::query()->where('country_code', 'IN')->count() < 36) {
        test()->seed(IndiaGeographySeeder::class);
    }
}

/**
 * Ensure India geography is seeded and resolve State/City master IDs for address forms.
 *
 * @return array{state_id: string, city_id: string, state: string, city: string}
 */
function geographyIdsFor(string $stateName = 'Maharashtra', string $cityName = 'Mumbai City'): array
{
    ensureIndiaGeography();

    $state = State::query()
        ->where('country_code', 'IN')
        ->where('name', $stateName)
        ->where('is_active', true)
        ->firstOrFail();

    $normalized = City::normalizeName($cityName);
    $city = City::query()
        ->where('state_id', $state->id)
        ->where('is_active', true)
        ->where(function ($query) use ($cityName, $normalized): void {
            $query->where('name', $cityName)
                ->orWhere('name_normalized', $normalized)
                ->orWhere('name_normalized', 'like', $normalized.'%');
        })
        ->orderByRaw('case when name_normalized = ? then 0 when name_normalized like ? then 1 else 2 end', [$normalized, $normalized.'%'])
        ->orderBy('name')
        ->firstOrFail();

    return [
        'state_id' => $state->id,
        'city_id' => $city->id,
        'state' => $state->name,
        'city' => $city->name,
    ];
}

/**
 * Establish Owner management company context (session) then continue.
 */
function enterManagementCompany(Company $company, ?string $redirect = null): TestResponse
{
    return test()->post(route('workspace.context.company', ['company' => $company]), [
        'redirect' => $redirect ?? internalRoute('owner.companies.index'),
    ]);
}

/**
 * Establish Branch Panel session context then continue.
 */
function enterBranchWorkspace(Company $company, Branch $branch, ?string $redirect = null): TestResponse
{
    return test()->post(route('workspace.context.branch', [
        'company' => $company,
        'branch' => $branch,
    ]), [
        'redirect' => $redirect ?? internalRoute('branch.dashboard'),
    ]);
}

/**
 * Seed Owner management company session without an HTTP round-trip.
 */
function seedManagementSession(Company $company): void
{
    test()->withSession([
        WorkspaceSession::COMPANY_KEY => $company->id,
        WorkspaceSession::VERSION_KEY => 1,
    ]);
}

/**
 * Seed Branch Panel session keys without an HTTP round-trip (for actingAs JSON tests).
 */
function seedBranchSession(Company $company, Branch $branch): void
{
    test()->withSession([
        WorkspaceSession::COMPANY_KEY => $company->id,
        WorkspaceSession::BRANCH_KEY => $branch->id,
        WorkspaceSession::VERSION_KEY => 1,
    ]);
}
