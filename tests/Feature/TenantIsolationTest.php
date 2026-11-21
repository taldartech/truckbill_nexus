<?php

use App\Models\User;
use App\Support\Tenant\ExampleTenantJob;
use App\Support\Tenant\FailingTenantJob;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use App\Support\Tenant\TenantStorageMetadata;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    TenantContextHolder::clear();
    ExampleTenantJob::$lastSeenCompanyId = null;
});

afterEach(function () {
    TenantContextHolder::clear();
    ExampleTenantJob::$lastSeenCompanyId = null;
});

test('http rejects spoofed company_id query parameter', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/profile?company_id=spoofed')
        ->assertForbidden();
});

test('http rejects spoofed branch_id query parameter', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/profile?branch_id=spoofed')
        ->assertForbidden();
});

test('http rejects spoofed tenant header', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['X-Company-Id' => 'spoofed'])
        ->get('/settings/profile')
        ->assertForbidden();
});

test('http rejects spoofed owner and tenant headers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['X-Owner-Id' => 'spoofed'])
        ->get('/settings/profile')
        ->assertForbidden();

    $this->actingAs($user)
        ->withHeaders(['X-Tenant-Id' => 'spoofed'])
        ->get('/settings/profile')
        ->assertForbidden();
});

test('http rejects spoofed company_id in request body', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', [
            'name' => 'Updated',
            'company_id' => 'spoofed',
        ])
        ->assertForbidden();
});

test('trusted server injection is available during the request and cleared afterwards', function () {
    Route::middleware('web')->get('/__harness/tenant-context', function () {
        TenantContextHolder::inject(new TenantContext(
            companyId: 'co-injected',
            branchId: 'br-injected',
            ownerId: 'own-1',
            isOwnerScope: true,
        ));

        $payload = TenantContextHolder::require()->toArray();
        $payload['had_context'] = TenantContextHolder::has();

        return response()->json($payload);
    });

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/__harness/tenant-context')
        ->assertOk()
        ->assertJsonPath('company_id', 'co-injected')
        ->assertJsonPath('branch_id', 'br-injected')
        ->assertJsonPath('had_context', true);

    expect(TenantContextHolder::has())->toBeFalse();
});

test('tenant context holder requires non-empty context', function () {
    expect(fn () => TenantContextHolder::require())
        ->toThrow(RuntimeException::class);
});

test('queued job without serialized context fails closed through middleware pipeline', function () {
    expect(fn () => ExampleTenantJob::dispatchSync())
        ->toThrow(RuntimeException::class, 'Queued job executed without tenant context.');

    expect(TenantContextHolder::has())->toBeFalse();
});

test('queued job restores context through middleware before handle and clears afterwards', function () {
    $context = new TenantContext(companyId: 'co-job', branchId: 'br-job');

    dispatch_sync((new ExampleTenantJob)->withTenantContext($context));

    expect(ExampleTenantJob::$lastSeenCompanyId)->toBe('co-job');
    expect(TenantContextHolder::has())->toBeFalse();
});

test('queued job clears context when handle throws', function () {
    $context = new TenantContext(companyId: 'co-fail', branchId: 'br-fail');

    expect(fn () => dispatch_sync((new FailingTenantJob)->withTenantContext($context)))
        ->toThrow(RuntimeException::class, 'Harness job failed intentionally.');

    expect(TenantContextHolder::has())->toBeFalse();
});

test('sequential queued jobs cannot inherit previous job context', function () {
    dispatch_sync((new ExampleTenantJob)->withTenantContext(
        new TenantContext(companyId: 'co-first', branchId: 'br-1')
    ));
    expect(ExampleTenantJob::$lastSeenCompanyId)->toBe('co-first');
    expect(TenantContextHolder::has())->toBeFalse();

    expect(fn () => ExampleTenantJob::dispatchSync())
        ->toThrow(RuntimeException::class, 'Queued job executed without tenant context.');

    expect(TenantContextHolder::has())->toBeFalse();

    dispatch_sync((new ExampleTenantJob)->withTenantContext(
        new TenantContext(companyId: 'co-second', branchId: 'br-2')
    ));
    expect(ExampleTenantJob::$lastSeenCompanyId)->toBe('co-second');
    expect(TenantContextHolder::has())->toBeFalse();
});

test('cache keys distinguish company branch owner internal and owner-scope dimensions', function () {
    $base = new TenantContext(companyId: 'co-a', branchId: 'br-1', ownerId: 'own-1');
    $otherCompany = new TenantContext(companyId: 'co-b', branchId: 'br-1', ownerId: 'own-1');
    $otherBranch = new TenantContext(companyId: 'co-a', branchId: 'br-2', ownerId: 'own-1');
    $otherOwner = new TenantContext(companyId: 'co-a', branchId: 'br-1', ownerId: 'own-2');
    $internal = new TenantContext(companyId: 'co-a', branchId: 'br-1', ownerId: 'own-1', isInternal: true);
    $ownerScope = new TenantContext(companyId: 'co-a', branchId: 'br-1', ownerId: 'own-1', isOwnerScope: true);

    $keys = [
        $base->cacheKey('dashboard'),
        $otherCompany->cacheKey('dashboard'),
        $otherBranch->cacheKey('dashboard'),
        $otherOwner->cacheKey('dashboard'),
        $internal->cacheKey('dashboard'),
        $ownerScope->cacheKey('dashboard'),
    ];

    expect($keys)->toHaveCount(count(array_unique($keys)));

    Cache::put($base->cacheKey('dashboard'), 'base', 60);
    Cache::put($internal->cacheKey('dashboard'), 'internal', 60);

    expect(Cache::get($base->cacheKey('dashboard')))->toBe('base');
    expect(Cache::get($internal->cacheKey('dashboard')))->toBe('internal');
});

test('storage allows same company and same branch', function () {
    TenantContextHolder::inject(new TenantContext(companyId: 'co-1', branchId: 'br-1'));
    $meta = TenantStorageMetadata::forCurrentContext();

    expect(fn () => TenantStorageMetadata::assertSameTenant($meta))
        ->not->toThrow(HttpException::class);
    expect($meta['company_id'])->toBe('co-1');
    expect($meta['branch_id'])->toBe('br-1');
});

test('storage denies different company', function () {
    TenantContextHolder::inject(new TenantContext(companyId: 'co-1', branchId: 'br-1'));
    $meta = TenantStorageMetadata::forCurrentContext();

    TenantContextHolder::inject(new TenantContext(companyId: 'co-2', branchId: 'br-1'));

    expect(fn () => TenantStorageMetadata::assertSameTenant($meta))
        ->toThrow(HttpException::class);
});

test('storage denies different branch', function () {
    $meta = ['company_id' => 'co-1', 'branch_id' => 'br-1'];
    TenantContextHolder::inject(new TenantContext(companyId: 'co-1', branchId: 'br-2'));

    expect(fn () => TenantStorageMetadata::assertSameTenant($meta))
        ->toThrow(HttpException::class);
});

test('storage denies company-level context accessing branch-level metadata', function () {
    $meta = ['company_id' => 'co-1', 'branch_id' => 'br-1'];
    TenantContextHolder::inject(new TenantContext(companyId: 'co-1', branchId: null));

    expect(fn () => TenantStorageMetadata::assertSameTenant($meta))
        ->toThrow(HttpException::class);
});

test('storage allows branch-level context to read company-scoped shared metadata', function () {
    $meta = ['company_id' => 'co-1', 'branch_id' => null];
    TenantContextHolder::inject(new TenantContext(companyId: 'co-1', branchId: 'br-1'));

    expect(fn () => TenantStorageMetadata::assertSameTenant($meta))
        ->not->toThrow(HttpException::class);
});

test('storage allows owner-scope context to access branch-level metadata in same company', function () {
    $meta = ['company_id' => 'co-1', 'branch_id' => 'br-9'];
    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-1',
        branchId: null,
        ownerId: 'own-1',
        isOwnerScope: true,
    ));

    expect(fn () => TenantStorageMetadata::assertSameTenant($meta))
        ->not->toThrow(HttpException::class);
});
