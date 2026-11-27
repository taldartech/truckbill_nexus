<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\Station\StationService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\Station;
use App\Models\StationDefaultTransporter;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

/**
 * @return array{company: Company, branch: Branch, otherBranch: Branch, otherCompany: Company, station: Station}
 */
function stationDefaultsWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $owner = User::factory()->customer()->create();
    $company = $workspace->createCompany($owner, 'Defaults Co');
    $branch = $company->branches()->firstOrFail();
    $otherBranch = $workspace->createBranch($company, 'Second Branch');
    $otherCompany = $workspace->createCompany($owner, 'Defaults Co B');

    $station = Station::factory()->create(['owner_user_id' => $owner->id]);

    return compact('company', 'branch', 'otherBranch', 'otherCompany', 'station');
}

function mapDefaultTransporter(Station $station, string $companyId, ?string $branchId): Party
{
    $party = Party::factory()->create(['company_id' => $companyId, 'branch_id' => null]);
    StationDefaultTransporter::factory()->create([
        'station_id' => $station->id,
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'default_transporter_party_id' => $party->id,
    ]);

    return $party;
}

test('branch-specific default transporter wins over the company-level default', function () {
    ['company' => $company, 'branch' => $branch, 'station' => $station] = stationDefaultsWorkspace();

    mapDefaultTransporter($station, $company->id, null);
    $branchTransporter = mapDefaultTransporter($station, $company->id, $branch->id);

    $service = app(StationService::class);

    expect($service->effectiveDefaultTransporter($company, $branch, $station))->toBe($branchTransporter->id)
        ->and($service->effectiveDefaultTransporters($company, $branch, [$station->id]))
        ->toBe([$station->id => $branchTransporter->id]);
});

test('company-level default applies when the branch has none and unrelated scopes never leak', function () {
    [
        'company' => $company,
        'branch' => $branch,
        'otherBranch' => $otherBranch,
        'otherCompany' => $otherCompany,
        'station' => $station,
    ] = stationDefaultsWorkspace();

    $service = app(StationService::class);
    mapDefaultTransporter($station, $otherCompany->id, null);
    mapDefaultTransporter($station, $company->id, $otherBranch->id);

    expect($service->effectiveDefaultTransporter($company, $branch, $station))->toBeNull();

    $companyTransporter = mapDefaultTransporter($station, $company->id, null);

    expect($service->effectiveDefaultTransporter($company, $branch, $station))->toBe($companyTransporter->id);
});
