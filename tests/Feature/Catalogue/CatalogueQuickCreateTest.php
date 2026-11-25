<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
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

/**
 * @return array{owner: User, company: Company, branch: Branch}
 */
function catalogueQuickCreateWorkspace(): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Catalogue QA Co');
    $branch = $company->branches()->firstOrFail();

    return [
        'owner' => $owner,
        'company' => $company,
        'branch' => $branch,
    ];
}

it('creates goods type via json quick-create and derives code from name', function (): void {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = catalogueQuickCreateWorkspace();

    $this->flushSession();
    $this->actingAs($owner);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($company, $branch);

    $response = $this->postJson(route('branch.goods-types.store'), [
        'name' => 'Steel Coils',
        'remark' => 'Quick create',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Steel Coils');

    $id = $response->json('data.id');
    expect($id)->toBeString();

    $item = OwnerCatalogueItem::query()->findOrFail($id);
    expect($item->catalogue_type)->toBe(OwnerCatalogueType::GoodsType->value)
        ->and($item->code)->toBe('STEEL-COILS')
        ->and($item->name)->toBe('Steel Coils');
});

it('creates package type via json with explicit code', function (): void {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = catalogueQuickCreateWorkspace();

    $this->flushSession();
    $this->actingAs($owner);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($company, $branch);

    $response = $this->postJson(route('branch.package-types.store'), [
        'name' => 'Wooden Crate',
        'code' => 'WCRATE',
        'remark' => 'Quick create',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.code', 'WCRATE')
        ->assertJsonPath('data.name', 'Wooden Crate');
});
