<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function prd7Workspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function prd7LoginCustomer(User $user, string $password = 'owner-secret'): TestResponse
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

/**
 * @return array<string, mixed>
 */
function prd7Profile(array $overrides = []): array
{
    $rate = GstRate::query()->firstOrCreate(
        ['code' => 'GST18', 'version' => 1],
        [
            'name' => 'GST 18%',
            'rate_percent' => '18.000',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
        ],
    );

    $geography = geographyIdsFor('Maharashtra', 'Mumbai');

    return array_merge([
        'pan' => 'ABCDE1234F',
        'registered_address' => '12 Logistics Park, Mumbai',
        'state_id' => $geography['state_id'],
        'city_id' => $geography['city_id'],
        'state' => $geography['state'],
        'city' => $geography['city'],
        'pin' => '400001',
        'contact_person' => 'Owner Name',
        'mobile' => '9876543210',
        'email' => 'owner@example.com',
        'gst_registration_type' => GstRegistrationType::Regular->value,
        'tax_liability' => TaxLiability::Fcm->value,
        'gstin' => '27ABCDE1234F1Z5',
        'gst_rate_id' => $rate->id,
        'data_visibility' => DataVisibility::SharedData->value,
        'timezone' => 'Asia/Kolkata',
    ], $overrides);
}

test('authenticated customer can create company with complete prd7 profile without initial branch', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'name' => 'Owner Name',
        'email' => 'owner.create@example.com',
        'email_verified_at' => now(),
    ]);

    $profile = prd7Profile([
        'contact_person' => $owner->name,
        'email' => $owner->email,
    ]);

    prd7LoginCustomer($owner);

    $this->get(route('owner.companies.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('owner/companies/create'));

    $this->post(route('owner.companies.store'), array_merge($profile, [
        'name' => 'PRD Seven Logistics',
        'create_initial_branch' => false,
    ]))->assertRedirect();

    $company = Company::query()->where('name', 'PRD Seven Logistics')->firstOrFail();
    expect($company->owner_user_id)->toBe($owner->id)
        ->and($company->pan)->toBe('ABCDE1234F')
        ->and($company->gstin)->toBe('27ABCDE1234F1Z5')
        ->and($company->data_visibility)->toBe(DataVisibility::SharedData)
        ->and($company->timezone)->toBe('Asia/Kolkata')
        ->and($company->branches()->count())->toBe(0);
});

test('complete company create with shared data and bank creates opening bank account', function () {
    $owner = User::factory()->customer()->create();
    $profile = prd7Profile([
        'bank_name' => 'HDFC Bank',
        'account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
        'data_visibility' => DataVisibility::SharedData->value,
    ]);

    $company = prd7Workspace()->createCompany(
        $owner,
        'Banked Co',
        'Head Office',
        $profile,
        requireCompleteProfile: true,
    );

    expect($company->branches()->count())->toBe(1)
        ->and(BankCashAccount::query()->where('company_id', $company->id)->where('account_kind', 'bank')->count())->toBe(1);
});

test('branch-wise restricted company rejects bank create without initial branch', function () {
    $owner = User::factory()->customer()->create();
    $profile = prd7Profile([
        'bank_name' => 'HDFC Bank',
        'account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
        'data_visibility' => DataVisibility::BranchWiseRestricted->value,
    ]);

    expect(fn () => prd7Workspace()->createCompany(
        $owner,
        'Restricted Co',
        null,
        $profile,
        requireCompleteProfile: true,
    ))->toThrow(CompanyDomainException::class, 'initial branch');
});

test('duplicate pan across companies is permitted', function () {
    $ownerA = User::factory()->customer()->create();
    $ownerB = User::factory()->customer()->create();
    $profile = prd7Profile(['pan' => 'AAAAA9999A', 'gstin' => '27AAAAA9999A1Z5']);

    prd7Workspace()->createCompany($ownerA, 'Co A', 'HO', $profile, true);
    $companyB = prd7Workspace()->createCompany($ownerB, 'Co B', 'HO', $profile, true);

    expect(Company::query()->where('pan', 'AAAAA9999A')->count())->toBe(2)
        ->and($companyB->pan)->toBe('AAAAA9999A');
});

test('invalid pan and missing gstin are rejected for regular registration', function () {
    $owner = User::factory()->customer()->create();

    expect(fn () => prd7Workspace()->createCompany(
        $owner,
        'Bad Pan',
        null,
        prd7Profile(['pan' => 'BAD']),
        true,
    ))->toThrow(CompanyDomainException::class, 'PAN');

    expect(fn () => prd7Workspace()->createCompany(
        $owner,
        'Missing Gstin',
        null,
        prd7Profile(['gstin' => '']),
        true,
    ))->toThrow(CompanyDomainException::class, 'GSTIN');
});

test('owner can update complete company profile from settings', function () {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
        'email_verified_at' => now(),
    ]);
    $company = prd7Workspace()->createCompany(
        $owner,
        'Editable Co',
        'HO',
        prd7Profile(),
        true,
    );

    prd7LoginCustomer($owner);
    enterManagementCompany($company)->assertRedirect();

    $newRate = GstRate::query()->firstOrCreate(
        ['code' => 'GST12', 'version' => 1],
        [
            'name' => 'GST 12%',
            'rate_percent' => '12.000',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
        ],
    );

    $geography = geographyIdsFor('Gujarat', 'Ahmedabad');

    $this->post(route('owner.settings.update'), [
        'name' => 'Editable Co Renamed',
        'remark' => 'Profile correction',
        'pan' => 'BBBBB1234C',
        'registered_address' => 'New address',
        'state_id' => $geography['state_id'],
        'city_id' => $geography['city_id'],
        'state' => $geography['state'],
        'city' => $geography['city'],
        'pin' => '380001',
        'contact_person' => $owner->name,
        'mobile' => '9988776655',
        'email' => $owner->email,
        'gst_registration_type' => GstRegistrationType::Composition->value,
        'tax_liability' => TaxLiability::Rcm->value,
        'gstin' => '24BBBBB1234C1Z5',
        'gst_rate_id' => $newRate->id,
        'data_visibility' => DataVisibility::SharedData->value,
        'timezone' => 'Asia/Kolkata',
    ])->assertRedirect(route('owner.settings'));

    $company->refresh();
    expect($company->name)->toBe('Editable Co Renamed')
        ->and($company->pan)->toBe('BBBBB1234C')
        ->and($company->city)->toBe('Ahmedabad')
        ->and($company->gst_registration_type)->toBe(GstRegistrationType::Composition)
        ->and($company->tax_liability)->toBe(TaxLiability::Rcm);
});

test('legacy name-only createCompany remains available for seeders and tests', function () {
    $owner = User::factory()->customer()->create();
    $company = prd7Workspace()->createCompany($owner, 'Legacy Seed Co');

    expect($company->name)->toBe('Legacy Seed Co')
        ->and($company->branches()->where('name', 'Head Office')->exists())->toBeTrue()
        ->and(Branch::query()->where('company_id', $company->id)->count())->toBe(1);
});
