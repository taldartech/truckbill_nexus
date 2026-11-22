<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Credits\CreditReservationService;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionLifecycleStatus;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionTrial;
use App\Models\SubscriptionCatalogueVersion;
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

test('confirmed entitlement gate allows trial mutations and blocks paid grace mutations', function () {
    $clock = new SubscriptionClock;
    $clock->freeze(CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC'));
    $gate = new SubscriptionEntitlementGate($clock);

    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Entitlement Co');

    expect($gate->evaluate($company)['status'])->toBe(SubscriptionLifecycleStatus::Trial)
        ->and($gate->allowsBranchMutation($company))->toBeTrue();

    CompanySubscriptionTrial::query()->where('owner_user_id', $owner->id)->delete();
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-03-01'),
    );

    $lifecycle = new SubscriptionLifecycleService(clock: $clock);
    $active = $lifecycle->activateAfterVerifiedPayment(
        $draft,
        CarbonImmutable::parse('2026-03-10'),
        $owner,
    );

    expect($gate->evaluate($company)['status'])->toBe(SubscriptionLifecycleStatus::Active)
        ->and($gate->allowsBranchMutation($company))->toBeTrue();

    $clock->freeze(CarbonImmutable::parse('2026-03-12 00:00:00', 'UTC'));
    expect($gate->evaluate($company)['status'])->toBe(SubscriptionLifecycleStatus::PaidGrace)
        ->and($gate->allowsBranchMutation($company))->toBeFalse()
        ->and($gate->evaluate($company)['owner_billing_allowed'])->toBeTrue();

    $clock->freeze(CarbonImmutable::parse('2026-03-25 00:00:00', 'UTC'));
    expect($gate->evaluate($company)['status'])->toBe(SubscriptionLifecycleStatus::Restricted)
        ->and($gate->allowsBranchMutation($company))->toBeFalse();

    expect($active->fresh()->status)->toBe('active');
});

function finalPhase3LoginAs(User $user, string $password = 'owner-secret'): void
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }
    test()->flushSession();
    $login = test()->post(route('login.store'), ['email' => $user->email, 'password' => $password]);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

test('owner subscription overview exposes lifecycle capacity and payment gate notes', function () {
    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Overview Co');
    finalPhase3LoginAs($owner);

    $this->get(route('owner.subscription', $company))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/overview')
            ->where('lifecycle.status', 'trial')
            ->where('lifecycle.branch_mutations_allowed', true)
            ->has('capacity.included_branches')
            ->has('payment_gate_note')
            ->has('links.payments')
            ->has('links.invoices'));

    $this->get(route('owner.subscription.payments', $company))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('owner/subscription/payments'));

    $this->get(route('owner.subscription.invoices', $company))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('owner/subscription/invoices'));
});

test('owner can schedule and withdraw cancellation from owner panel', function () {
    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Cancel UI Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);

    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-08-01'),
    );
    $active = app(SubscriptionLifecycleService::class)->activateAfterVerifiedPayment(
        $draft,
        CarbonImmutable::parse('2027-08-01'),
        $owner,
    );

    finalPhase3LoginAs($owner);

    $this->post(route('owner.subscription.cancel', $company), [
        'subscription_id' => $active->id,
        'remark' => 'Cancel at renewal',
    ])->assertRedirect(route('owner.subscription', $company));

    expect($active->fresh()->cancellation_effective_on?->toDateString())->toBe('2027-08-01');

    $this->post(route('owner.subscription.cancel.withdraw', $company), [
        'subscription_id' => $active->id,
        'remark' => 'Keep plan',
    ])->assertRedirect(route('owner.subscription', $company));

    expect($active->fresh()->cancellation_requested_at)->toBeNull();
});
test('credit reservation is blocked during paid grace', function () {
    $clock = new SubscriptionClock;
    $clock->freeze(CarbonImmutable::parse('2026-04-01 12:00:00', 'UTC'));
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Grace Credits Co');
    CompanySubscriptionTrial::query()->where('owner_user_id', $owner->id)->delete();

    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-04-01'),
    );
    $lifecycle = new SubscriptionLifecycleService(clock: $clock);
    $lifecycle->activateAfterVerifiedPayment($draft, CarbonImmutable::parse('2026-04-05'), $owner);
    $clock->freeze(CarbonImmutable::parse('2026-04-07 00:00:00', 'UTC'));

    $contracts = app(CompanySubscriptionContractService::class);
    $ledger = $contracts->ensureCreditLedger($company, 'broker');
    $contracts->appendCreditEntry($ledger, '20.0000', 'grant', 'Seed', 'grace-grant');

    $gate = new SubscriptionEntitlementGate($clock);
    expect($gate->allowsCreditConsumption($company))->toBeFalse();

    $reservations = new CreditReservationService(entitlements: new SubscriptionEntitlementGate($clock));
    expect(fn () => $reservations->reserve($ledger->fresh(), '5.0000', 'grace-block', 'Should fail'))
        ->toThrow(SubscriptionDomainException::class);
});

test('lifecycle evaluate command finalizes due cancellations', function () {
    $clock = new SubscriptionClock;
    $clock->freeze(CarbonImmutable::parse('2026-05-01 12:00:00', 'UTC'));
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Finalize Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);

    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2026-05-10'),
    );
    $lifecycle = new SubscriptionLifecycleService(clock: $clock);
    $active = $lifecycle->activateAfterVerifiedPayment($draft, CarbonImmutable::parse('2026-05-10'), $owner);
    $lifecycle->requestCancellation($owner, $active, 'Due soon');

    $clock->freeze(CarbonImmutable::parse('2026-05-10 00:00:00', 'UTC'));
    $count = $lifecycle->finalizeDueCancellations();

    expect($count)->toBe(1)
        ->and(CompanySubscription::query()->whereKey($active->id)->value('status'))->toBe('cancelled');
});
