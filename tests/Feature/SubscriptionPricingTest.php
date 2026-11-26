<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Pricing\SubscriptionPricingCalculator;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;

beforeEach(function () {
    TenantContextHolder::clear();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('decimal math rejects non-numeric money inputs', function () {
    expect(fn () => DecimalMath::of('not-a-number'))->toThrow(InvalidArgumentException::class);
});

test('display list price divides catalogue annual by 0.75', function () {
    $calc = new SubscriptionPricingCalculator;

    expect($calc->displayListPrice('5000.00'))->toBe('6666.67');
});

test('discount order applies base bundle tenure renewal then coupon under monetary cap', function () {
    $calc = new SubscriptionPricingCalculator;

    // Two modules: Broker Micro 5000 + OwnFleet Lite 5000 = 10000
    // Bundle 10% → 9000 annual after bundle
    // Yearly tenure: period = 9000, tenure 15% → 7650
    // Renewal 5% → 7267.50
    $result = $calc->calculate(
        coreModuleAnnualPrices: ['5000.00', '5000.00'],
        tenure: SubscriptionTenure::Yearly,
        renewalEligible: true,
        couponPercent: '50', // would exceed remaining monetary cap
    );

    expect($result['bundle_percent'])->toBe('10')
        ->and($result['after_bundle'])->toBe('9000.00')
        ->and($result['tenure_percent'])->toBe('15')
        ->and($result['after_tenure'])->toBe('7650.00')
        ->and($result['after_renewal'])->toBe('7267.50')
        ->and($result['monetary_cap'])->toBe('2700.00') // 30% of 9000
        ->and(bccomp($result['coupon_monetary_applied'], '0', 2))->toBe(1)
        ->and(bccomp($result['final_period_amount'], '7267.50', 2))->toBe(-1);
});

test('three year full advance multiplies annual by three before tenure discount', function () {
    $calc = new SubscriptionPricingCalculator;

    $result = $calc->calculate(
        coreModuleAnnualPrices: ['5000.00'],
        tenure: SubscriptionTenure::ThreeYearFullAdvance,
    );

    expect($result['period_before_tenure'])->toBe('15000.00')
        ->and($result['tenure_percent'])->toBe('25')
        ->and($result['final_period_amount'])->toBe('11250.00');
});

test('actual day proration is leap aware and includes activation day', function () {
    $calc = new SubscriptionPricingCalculator;

    // Non-leap: 2025-03-01 to 2026-03-01 = 365 days; activation 2025-12-01 → remaining 91 days inclusive?
    // activation 2025-12-01, renewal 2026-03-01 → diffInDays=90, +1 include activation = 91
    // term days from 2025-03-01 to 2026-03-01 = 365
    $amount = $calc->prorateAnnual(
        '3650.00',
        CarbonImmutable::parse('2025-12-01'),
        CarbonImmutable::parse('2026-03-01'),
    );

    expect($amount)->toBe('910.00'); // 3650 * 91 / 365

    // Term spanning Feb 29 2024: 2024-01-01 → 2025-01-01 = 366 days
    $leap = $calc->prorateAnnual(
        '3660.00',
        CarbonImmutable::parse('2024-12-01'),
        CarbonImmutable::parse('2025-01-01'),
    );

    // remaining days Dec 1 inclusive through Jan 1 = 32; 3660 * 32 / 366 = 320.00
    expect($leap)->toBe('320.00');
});

test('mid term module discount percent follows second and third module rules', function () {
    $calc = new SubscriptionPricingCalculator;

    expect($calc->midTermModuleDiscountPercent(0))->toBe('0')
        ->and($calc->midTermModuleDiscountPercent(1))->toBe('10')
        ->and($calc->midTermModuleDiscountPercent(2))->toBe('15');
});

test('draft contract snapshots prices immutably and rejects snapshot mutation', function () {
    $owner = User::factory()->customer()->create(['password' => Hash::make('secret')]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Sub Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plans = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->whereIn('code', ['micro', 'lite'])
        ->whereIn('module', ['broker', 'ownfleet'])
        ->get();

    $this->actingAs($owner);

    $contract = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: $plans->pluck('id')->all(),
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: now()->addYear(),
        renewalEligible: false,
    );

    expect($contract->status)->toBe('draft')
        ->and($contract->common_renewal_date)->not->toBeNull()
        ->and($contract->items)->toHaveCount(2)
        ->and($contract->snapshots()->count())->toBe(1);

    $snapshot = $contract->snapshots()->firstOrFail();

    expect(fn () => $snapshot->update(['reason' => 'tamper']))
        ->toThrow(SubscriptionDomainException::class);

    $ledger = app(CompanySubscriptionContractService::class)->ensureCreditLedger($company, 'broker');
    $entry = app(CompanySubscriptionContractService::class)->appendCreditEntry(
        $ledger,
        '20.0000',
        'grant',
        'Top-up foundation',
        'idem-1',
    );
    $dup = app(CompanySubscriptionContractService::class)->appendCreditEntry(
        $ledger,
        '20.0000',
        'grant',
        'Top-up foundation',
        'idem-1',
    );

    expect($entry->id)->toBe($dup->id)
        ->and((string) $ledger->fresh()->balance)->toBe('20.0000')
        ->and(fn () => $entry->delete())->toThrow(SubscriptionDomainException::class);
});

test('catalogue seeder is idempotent with configurable starting prices', function () {
    (new SubscriptionCatalogueSeeder)->run();

    expect(SubscriptionPlan::query()->count())->toBe(11)
        ->and(SubscriptionAddon::query()->count())->toBe(17)
        ->and(SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->count())->toBe(1);
});

test('draft contract update recalculates prices preserves catalogue and records history', function () {
    $owner = User::factory()->customer()->create(['password' => Hash::make('secret')]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Draft Edit Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plans = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->whereIn('code', ['micro', 'lite'])
        ->whereIn('module', ['broker', 'ownfleet'])
        ->get();
    $addon = SubscriptionAddon::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('is_active', true)
        ->firstOrFail();

    $this->actingAs($owner);
    $contracts = app(CompanySubscriptionContractService::class);

    $draft = $contracts->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plans->first()->id],
        tenure: SubscriptionTenure::Monthly,
        commonRenewalDate: now()->addYear(),
        renewalEligible: false,
    );

    $beforeTotal = (string) $draft->discounted_annual_total;
    $beforeSnapshots = $draft->snapshots()->count();

    $updated = $contracts->updateDraftContract(
        actor: $owner,
        subscription: $draft,
        planIds: $plans->pluck('id')->all(),
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: now()->addMonths(18),
        renewalEligible: true,
        addonIds: [$addon->id],
        expectedSnapshotCount: $beforeSnapshots,
    );

    expect($updated->status)->toBe('draft')
        ->and($updated->catalogue_version_id)->toBe($catalogue->id)
        ->and($updated->tenure)->toBe(SubscriptionTenure::Yearly->value)
        ->and($updated->renewal_eligible)->toBeTrue()
        ->and($updated->items)->toHaveCount(3)
        ->and((string) $updated->discounted_annual_total)->not->toBe($beforeTotal)
        ->and($updated->snapshots()->count())->toBe($beforeSnapshots + 2)
        ->and($updated->snapshots()->where('reason', 'draft_before_update')->exists())->toBeTrue()
        ->and($updated->snapshots()->where('reason', 'draft_updated')->exists())->toBeTrue()
        ->and($updated->activated_at)->toBeNull();

    $calc = (new SubscriptionPricingCalculator)->calculate(
        coreModuleAnnualPrices: array_values($plans->pluck('annual_price')->map(fn ($p) => (string) $p)->all()),
        tenure: SubscriptionTenure::Yearly,
        renewalEligible: true,
    );
    expect((string) $updated->list_annual_total)->toBe($calc['base_annual'])
        ->and((string) $updated->discounted_annual_total)->toBe($calc['after_bundle']);
});

test('draft update rejects finalized contracts unauthorized actors invalid selections and stale concurrency', function () {
    $owner = User::factory()->customer()->create();
    $stranger = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Guard Co');
    $otherCompany = app(CompanyWorkspaceService::class)->createCompany($stranger, 'Other Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('is_active', true)
        ->firstOrFail();
    $this->actingAs($owner);
    $contracts = app(CompanySubscriptionContractService::class);
    $draft = $contracts->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: now()->addYear(),
    );

    expect(fn () => $contracts->updateDraftContract(
        actor: $stranger,
        subscription: $draft,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: now()->addYear(),
        expectedSnapshotCount: $draft->snapshots()->count(),
    ))->toThrow(SubscriptionDomainException::class);

    expect(fn () => $contracts->updateDraftContract(
        actor: $owner,
        subscription: $draft,
        planIds: [(string) Str::uuid()],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: now()->addYear(),
        expectedSnapshotCount: $draft->snapshots()->count(),
    ))->toThrow(SubscriptionDomainException::class);

    $sameModulePlans = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('module', $plan->module)
        ->where('is_active', true)
        ->take(2)
        ->get();
    if ($sameModulePlans->count() === 2) {
        expect(fn () => $contracts->updateDraftContract(
            actor: $owner,
            subscription: $draft->fresh(),
            planIds: $sameModulePlans->pluck('id')->all(),
            tenure: SubscriptionTenure::Yearly,
            commonRenewalDate: now()->addYear(),
            expectedSnapshotCount: $draft->snapshots()->count(),
        ))->toThrow(SubscriptionDomainException::class);
    }

    $staleCount = $draft->fresh()->snapshots()->count();
    $contracts->updateDraftContract(
        actor: $owner,
        subscription: $draft->fresh(),
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Quarterly,
        commonRenewalDate: now()->addYear(),
        expectedSnapshotCount: $staleCount,
    );

    expect(fn () => $contracts->updateDraftContract(
        actor: $owner,
        subscription: $draft->fresh(),
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: now()->addYear(),
        expectedSnapshotCount: $staleCount,
    ))->toThrow(SubscriptionDomainException::class);

    $finalized = $draft->fresh();
    $finalized->forceFill(['status' => 'active'])->save();
    expect(fn () => $contracts->updateDraftContract(
        actor: $owner,
        subscription: $finalized,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: now()->addYear(),
        expectedSnapshotCount: $finalized->snapshots()->count(),
    ))->toThrow(SubscriptionDomainException::class);

    expect($otherCompany->id)->not->toBe($company->id);
});

test('draft update rolls back atomically when audit write fails', function () {
    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Atomic Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('is_active', true)
        ->firstOrFail();
    $otherPlan = SubscriptionPlan::query()
        ->where('catalogue_version_id', $catalogue->id)
        ->where('is_active', true)
        ->where('id', '!=', $plan->id)
        ->where('module', '!=', $plan->module)
        ->firstOrFail();

    $this->actingAs($owner);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: now()->addYear(),
    );

    $itemCountBefore = $draft->items()->count();
    $snapshotCountBefore = $draft->snapshots()->count();
    $tenureBefore = $draft->tenure;

    $failingAudit = Mockery::mock(AuditWriter::class);
    $failingAudit->shouldReceive('write')->andThrow(new RuntimeException('forced audit failure'));

    $service = new CompanySubscriptionContractService(
        pricing: new SubscriptionPricingCalculator,
        auditedTransaction: new AuditedTransaction,
        audit: $failingAudit,
    );

    expect(fn () => $service->updateDraftContract(
        actor: $owner,
        subscription: $draft,
        planIds: [$otherPlan->id],
        tenure: SubscriptionTenure::Monthly,
        commonRenewalDate: now()->addMonths(6),
        expectedSnapshotCount: $snapshotCountBefore,
    ))->toThrow(RuntimeException::class);

    $fresh = $draft->fresh(['items']);
    expect($fresh->tenure)->toBe($tenureBefore)
        ->and($fresh->items()->count())->toBe($itemCountBefore)
        ->and($fresh->items()->first()->plan_id)->toBe($plan->id)
        ->and($fresh->snapshots()->count())->toBe($snapshotCountBefore);
});
