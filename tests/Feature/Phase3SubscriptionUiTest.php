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

    $this->get(route('owner.subscription.catalogue', [
        'plan_ids' => $plans->pluck('id')->all(),
        'tenure' => SubscriptionTenure::Yearly->value,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/catalogue')
            ->has('breakdown')
            ->where('breakdown.final_period_amount', fn ($value) => is_string($value) && $value !== ''));

    $this->post(route('owner.subscription.drafts.store'), [
        'catalogue_version_id' => $catalogue->id,
        'plan_ids' => $plans->pluck('id')->all(),
        'tenure' => SubscriptionTenure::Yearly->value,
        'common_renewal_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'remark' => 'Draft from UI test',
    ])->assertRedirect(route('owner.subscription.drafts'));

    expect(CompanySubscription::query()->where('company_id', $company->id)->where('status', 'draft')->count())->toBe(1);

    $this->get(route('owner.subscription.drafts'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/drafts')
            ->has('drafts', 1)
            ->where('drafts.0.status', 'draft'));

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

test('owner can open and update draft contract from subscription UI', function () {
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

    $catalogue = SubscriptionCatalogueVersion::query()->where('is_active', true)->firstOrFail();
    $plans = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('is_active', true)
        ->get()
        ->unique('module')
        ->take(2)
        ->values();

    $this->post(route('owner.subscription.drafts.store'), [
        'catalogue_version_id' => $catalogue->id,
        'plan_ids' => [$plans->first()->id],
        'tenure' => SubscriptionTenure::Monthly->value,
        'common_renewal_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'remark' => 'Draft for edit UI',
    ])->assertRedirect();

    $draft = CompanySubscription::query()->where('company_id', $company->id)->where('status', 'draft')->firstOrFail();

    $this->get(route('owner.subscription.drafts.edit', ['subscription' => $draft]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/draft-edit')
            ->where('draft.id', $draft->id)
            ->where('catalogue.id', $catalogue->id)
            ->has('before_breakdown'));

    $snapshotCount = $draft->snapshots()->count();

    $this->put(route('owner.subscription.drafts.update', ['subscription' => $draft]), [
        'plan_ids' => $plans->pluck('id')->all(),
        'tenure' => SubscriptionTenure::Yearly->value,
        'common_renewal_date' => CarbonImmutable::now()->addMonths(14)->toDateString(),
        'renewal_eligible' => true,
        'expected_snapshot_count' => $snapshotCount,
        'remark' => 'UI draft update',
    ])->assertRedirect(route('owner.subscription.drafts.edit', ['subscription' => $draft]));

    $draft->refresh();
    expect($draft->tenure)->toBe(SubscriptionTenure::Yearly->value)
        ->and($draft->renewal_eligible)->toBeTrue()
        ->and($draft->items()->count())->toBe(2)
        ->and($draft->status)->toBe('draft')
        ->and($draft->activated_at)->toBeNull();

    $this->put(route('owner.subscription.drafts.update', ['subscription' => $draft]), [
        'plan_ids' => $plans->pluck('id')->all(),
        'tenure' => SubscriptionTenure::Monthly->value,
        'common_renewal_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'expected_snapshot_count' => $snapshotCount,
        'remark' => 'Stale edit',
    ])->assertForbidden();
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
    $this->get(route('owner.subscription.catalogue'))->assertForbidden();

    $catalogue = SubscriptionCatalogueVersion::query()->where('is_active', true)->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::now()->addYear(),
    );

    $this->get(route('owner.subscription.drafts.edit', ['subscription' => $draft]))->assertForbidden();
    $this->put(route('owner.subscription.drafts.update', ['subscription' => $draft]), [
        'plan_ids' => [$plan->id],
        'tenure' => SubscriptionTenure::Yearly->value,
        'common_renewal_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'expected_snapshot_count' => $draft->snapshots()->count(),
        'remark' => 'cross actor',
    ])->assertForbidden();
});

test('cross-company draft edit routes are rejected', function () {
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

    $this->get(route('owner.subscription.drafts.edit', ['subscription' => $draftA]))->assertNotFound();
    $this->put(route('owner.subscription.drafts.update', ['subscription' => $draftA]), [
        'plan_ids' => [$plan->id],
        'tenure' => SubscriptionTenure::Yearly->value,
        'common_renewal_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'expected_snapshot_count' => $draftA->snapshots()->count(),
        'remark' => 'cross company',
    ])->assertNotFound();
});
