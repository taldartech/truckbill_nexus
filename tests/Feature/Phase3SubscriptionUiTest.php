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

    $this->get(route('owner.subscription', $company))
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

    $this->get(route('owner.subscription.catalogue', [
        'company' => $company,
        'plan_ids' => $plans->pluck('id')->all(),
        'tenure' => SubscriptionTenure::Yearly->value,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/catalogue')
            ->has('breakdown')
            ->where('breakdown.final_period_amount', fn ($value) => is_string($value) && $value !== ''));

    $this->post(route('owner.subscription.drafts.store', $company), [
        'catalogue_version_id' => $catalogue->id,
        'plan_ids' => $plans->pluck('id')->all(),
        'tenure' => SubscriptionTenure::Yearly->value,
        'common_renewal_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'remark' => 'Draft from UI test',
    ])->assertRedirect(route('owner.subscription.drafts', $company));

    expect(CompanySubscription::query()->where('company_id', $company->id)->where('status', 'draft')->count())->toBe(1);

    $this->get(route('owner.subscription.drafts', $company))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/drafts')
            ->has('drafts', 1)
            ->where('drafts.0.status', 'draft'));

    app(CompanySubscriptionContractService::class)
        ->ensureCreditLedger($company, 'sms');

    expect(SubscriptionCreditLedger::query()->where('company_id', $company->id)->exists())->toBeTrue();

    $this->get(route('owner.subscription.credits', $company))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/credits')
            ->has('ledgers')
            ->where('ledgers.0.is_cash_wallet', false));
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

    $this->get(route('owner.subscription', $company))->assertForbidden();
    $this->get(route('owner.subscription.catalogue', $company))->assertForbidden();
});
