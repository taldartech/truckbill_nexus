<?php

use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCacheInvalidator;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Support\Cache\RequestScopedMemo;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/../../Support/RbacPersonaHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    app()->forgetInstance(SubscriptionClock::class);
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
    app()->forgetInstance(SubscriptionClock::class);
    app(RequestScopedMemo::class)->deactivate();
});

/**
 * Counts cache retrievals (hit or miss) per key while the callback runs.
 *
 * @return array<string, int>
 */
function capabilityCacheRetrievalsDuring(Closure $callback): array
{
    $counts = [];
    $record = function (CacheHit|CacheMissed $event) use (&$counts): void {
        $counts[$event->key] = ($counts[$event->key] ?? 0) + 1;
    };

    Event::listen(CacheHit::class, $record);
    Event::listen(CacheMissed::class, $record);

    try {
        $callback();
    } finally {
        Event::forget(CacheHit::class);
        Event::forget(CacheMissed::class);
    }

    return array_filter(
        $counts,
        fn (string $key): bool => str_starts_with($key, 'company_capabilities:') || str_starts_with($key, 'rbac:'),
        ARRAY_FILTER_USE_KEY,
    );
}

test('branch page with full navigation retrieves each capability and permission cache key at most once per request', function (string $persona) {
    $ctx = tripWorkspace([
        SubscriptionModule::Broker->value,
        SubscriptionModule::OwnFleet->value,
        SubscriptionModule::Partload->value,
    ]);
    $user = $ctx[$persona];

    rbacHttpAs($user, $ctx);
    $this->get(route('branch.dashboard'))->assertOk();

    rbacHttpAs($user, $ctx);
    $counts = capabilityCacheRetrievalsDuring(function (): void {
        $this->get(route('branch.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workspace.navigation.bilty_register', true)
                ->where('workspace.navigation.masters_parties', true));
    });

    expect($counts)->not->toBeEmpty()
        ->and(max($counts))->toBe(1);
})->with(['owner', 'member']);

test('capability invalidation within an active request scope returns freshly resolved capabilities', function () {
    $ctx = tripWorkspace([SubscriptionModule::Broker->value]);
    $resolver = app(CompanyCapabilityResolver::class);
    app(RequestScopedMemo::class)->activate();

    expect($resolver->allowsRead($ctx['company'], CompanyCapabilities::OWNFLEET_LR))->toBeFalse();
    expect(capabilityCacheRetrievalsDuring(
        fn () => expect($resolver->allowsRead($ctx['company'], CompanyCapabilities::BROKER_LR))->toBeTrue(),
    ))->toBe([]);

    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $ownFleetPlan = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('module', SubscriptionModule::OwnFleet->value)
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->firstOrFail();

    $this->actingAs($ctx['owner']);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $ctx['owner'],
        company: $ctx['company'],
        catalogue: $catalogue,
        planIds: [$ownFleetPlan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::now()->addYear(),
    );
    app(SubscriptionLifecycleService::class)->activateAfterVerifiedPayment(
        $draft,
        CarbonImmutable::now()->addYear(),
        $ctx['owner'],
        'Upgrade Broker company with OwnFleet',
    );

    expect($resolver->allowsRead($ctx['company'], CompanyCapabilities::OWNFLEET_LR))->toBeTrue()
        ->and((new CompanyCapabilityResolver)->allowsRead($ctx['company'], CompanyCapabilities::OWNFLEET_LR))->toBeTrue();
});

test('permission generation bump within an active request scope returns freshly resolved permission codes', function () {
    $ctx = tripWorkspace([SubscriptionModule::Broker->value]);
    $authorizer = app(PermissionAuthorizer::class);
    app(RequestScopedMemo::class)->activate();

    expect($authorizer->allowsCustomer($ctx['member'], PermissionCodes::BROKER_LORRY_HIRE_VIEW, $ctx['company'], $ctx['branch']))->toBeFalse();

    tripGrant('customer.admin', PermissionCodes::BROKER_LORRY_HIRE_VIEW);
    app(PermissionCacheInvalidator::class)->bumpUser($ctx['member']);

    expect($authorizer->allowsCustomer($ctx['member'], PermissionCodes::BROKER_LORRY_HIRE_VIEW, $ctx['company'], $ctx['branch']))->toBeTrue();
});

test('company switching within one request scope resolves each company capabilities independently', function () {
    $broker = tripWorkspace([SubscriptionModule::Broker->value]);
    $ownFleet = tripWorkspace([SubscriptionModule::OwnFleet->value]);
    $resolver = app(CompanyCapabilityResolver::class);
    app(RequestScopedMemo::class)->activate();

    expect($resolver->allowsRead($broker['company'], CompanyCapabilities::BROKER_LR))->toBeTrue()
        ->and($resolver->allowsRead($broker['company'], CompanyCapabilities::OWNFLEET_LR))->toBeFalse()
        ->and($resolver->allowsRead($ownFleet['company'], CompanyCapabilities::BROKER_LR))->toBeFalse()
        ->and($resolver->allowsRead($ownFleet['company'], CompanyCapabilities::OWNFLEET_LR))->toBeTrue();

    rbacHttpAs($broker['owner'], $broker);
    $this->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.capabilities.active_modules', [SubscriptionModule::Broker->value])
            ->where('workspace.navigation.broker_lorry_hires', true)
            ->where('workspace.navigation.ownfleet_vehicle_drivers', false));

    rbacHttpAs($ownFleet['owner'], $ownFleet);
    $this->get(route('branch.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.capabilities.active_modules', [SubscriptionModule::OwnFleet->value])
            ->where('workspace.navigation.broker_lorry_hires', false)
            ->where('workspace.navigation.ownfleet_vehicle_drivers', true));
});
