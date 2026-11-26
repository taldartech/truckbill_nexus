<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Models\CompanySubscription;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionCreditLedger;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function subscriptionUiOwner(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Sub UI Co');

    return compact('owner', 'company');
}

test('owner subscription overview catalogue drafts and credits screens render', function () {
    ['owner' => $owner, 'company' => $company] = subscriptionUiOwner();
    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $owner->email, 'password' => 'owner-secret']);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    enterManagementCompany($company)->assertRedirect();

    $this->get(route('owner.subscription'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/overview')
            ->where('subscription', null)
            ->has('entitlement_note'));

    $catalogue = SubscriptionCatalogueVersion::query()->where('is_active', true)->firstOrFail();
    $plans = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('is_active', true)
        ->get()
        ->unique('module')
        ->take(2)
        ->values();
    expect($plans)->toHaveCount(2);

    $this->get(route('owner.companies.subscription.purchase', ['company' => $company]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/purchase')
            ->has('plans')
            ->has('addons')
            ->has('tenureOptions'));

    $this->withCredentials()->postJson(route('owner.companies.subscription.quote', ['company' => $company]), [
        'plan_ids' => $plans->pluck('id')->all(),
        'tenure' => SubscriptionTenure::Yearly->value,
    ])
        ->assertOk()
        ->assertJsonPath('payable', true)
        ->assertJsonStructure(['subtotal_amount', 'discount_amount', 'taxable_amount', 'total_payable', 'common_renewal_date']);

    $this->post(route('owner.companies.subscription.store', ['company' => $company]), [
        'plan_ids' => $plans->pluck('id')->all(),
        'tenure' => SubscriptionTenure::Yearly->value,
    ])->assertRedirect();

    expect(CompanySubscription::query()->where('company_id', $company->id)->where('status', 'pending_payment')->count())->toBe(1);

    app(CompanySubscriptionContractService::class)
        ->ensureCreditLedger($company, 'sms');

    expect(SubscriptionCreditLedger::query()->where('company_id', $company->id)->exists())->toBeTrue();

    $this->get(route('owner.subscription.credits'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/credits')
            ->has('ledgers')
            ->where('ledgers.0.is_cash_wallet', false));
});

test('obsolete manual draft and catalogue routes are removed and explicit purchase works without session', function () {
    ['owner' => $owner, 'company' => $company] = subscriptionUiOwner();
    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $owner->email, 'password' => 'owner-secret']);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    expect(Route::has('owner.subscription.catalogue'))->toBeFalse()
        ->and(Route::has('owner.subscription.drafts'))->toBeFalse()
        ->and(Route::has('owner.subscription.drafts.edit'))->toBeFalse();

    $this->get(route('owner.subscription.purchase', ['company' => $company->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/purchase')
            ->where('company.id', $company->id));
});

test('non-owner cannot open owner subscription screens', function () {
    $workspace = app(CompanyWorkspaceService::class);
    $owner = User::factory()->customer()->create();
    $company = $workspace->createCompany($owner, 'Locked Sub Co');
    $stranger = User::factory()->customer()->create();

    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $stranger->email, 'password' => 'password']);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    $this->get(route('owner.subscription'))->assertForbidden();
    $this->get(route('owner.companies.subscription.purchase', ['company' => $company]))->assertForbidden();
    $this->withCredentials()->postJson(route('owner.companies.subscription.quote', ['company' => $company]), [
        'plan_ids' => [],
    ])->assertForbidden();
});

test('cross-company checkout routes are rejected', function () {
    $workspace = app(CompanyWorkspaceService::class);
    $ownerA = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $ownerB = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $companyA = $workspace->createCompany($ownerA, 'Company A');
    $companyB = $workspace->createCompany($ownerB, 'Company B');
    $catalogue = SubscriptionCatalogueVersion::query()->where('is_active', true)->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();

    $draftA = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $ownerA,
        company: $companyA,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::now()->addYear(),
    );

    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $ownerB->email, 'password' => 'owner-secret']);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    enterManagementCompany($companyB)->assertRedirect();

    $this->get(route('owner.subscription.checkout', ['subscription' => $draftA]))->assertNotFound();
    $this->get(route('owner.companies.subscription.purchase', ['company' => $companyA]))->assertForbidden();
});
