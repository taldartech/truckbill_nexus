<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\BranchGstMode;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Models\Branch;
use App\Models\State;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\IndiaGeographySeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    TenantContextHolder::clear();
    $this->seed(IndiaGeographySeeder::class);
});

afterEach(function (): void {
    TenantContextHolder::clear();
});

function loginOwnerProfileCustomer(User $user, string $password = 'owner-secret'): TestResponse
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }

    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    return $response;
}

test('owner can update branch profile including geography and gst mode', function (): void {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Branch Profile Co');
    /** @var Branch $branch */
    $branch = app(CompanyWorkspaceService::class)->createBranch(
        $company,
        'Ops Branch',
        'Initial create',
        [
            'is_head_office' => false,
            'gst_mode' => BranchGstMode::InheritCompany,
        ],
    );

    $state = State::query()->where('code', 'MH')->firstOrFail();
    $city = $state->cities()->where('is_active', true)->firstOrFail();

    loginOwnerProfileCustomer($owner);
    enterManagementCompany($company)->assertRedirect();

    $this->post(route('owner.branches.update', ['branch' => $branch]), [
        'name' => 'Ops Branch Updated',
        'remark' => 'Complete profile',
        'contact_number' => '9876543210',
        'is_head_office' => '0',
        'address' => '12 Warehouse Road',
        'state_id' => $state->id,
        'city_id' => $city->id,
        'pin' => '400001',
        'gst_mode' => 'independent',
        'gst_registration_type' => GstRegistrationType::Regular->value,
        'tax_liability' => TaxLiability::Fcm->value,
        'gstin' => '27AAAAA0000A1Z5',
    ])->assertRedirect(route('owner.branches.show', ['branch' => $branch]));

    $branch->refresh();

    expect($branch->name)->toBe('Ops Branch Updated')
        ->and($branch->contact_number)->toBe('9876543210')
        ->and($branch->address)->toBe('12 Warehouse Road')
        ->and($branch->state_id)->toBe($state->id)
        ->and($branch->city_id)->toBe($city->id)
        ->and($branch->state)->toBe($state->name)
        ->and($branch->city)->toBe($city->name)
        ->and($branch->gst_mode)->toBe(BranchGstMode::Independent)
        ->and($branch->gstin)->toBe('27AAAAA0000A1Z5');
});
