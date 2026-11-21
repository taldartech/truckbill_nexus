<?php

use App\Models\User;
use App\Support\Tenant\ExampleTenantJob;
use App\Support\Tenant\RequiresTenantContext;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use App\Support\Tenant\TenantStorageMetadata;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('http rejects spoofed company_id query parameter', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/profile?company_id=spoofed')
        ->assertForbidden();
});

test('http rejects spoofed tenant header', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['X-Company-Id' => 'spoofed'])
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

test('tenant context holder requires non-empty context', function () {
    expect(fn () => TenantContextHolder::require())
        ->toThrow(RuntimeException::class);
});

test('jobs fail closed without tenant context', function () {
    $middleware = new RequiresTenantContext;

    expect(fn () => $middleware->handle(new stdClass, fn () => true))
        ->toThrow(RuntimeException::class, 'Queued job executed without tenant context.');
});

test('jobs proceed when tenant context is present', function () {
    TenantContextHolder::set(new TenantContext(companyId: 'co-1', branchId: 'br-1'));

    $middleware = new RequiresTenantContext;
    $result = $middleware->handle(new stdClass, fn () => 'ok');

    expect($result)->toBe('ok');
});

test('cache keys are tenant scoped and do not collide across companies', function () {
    $a = new TenantContext(companyId: 'co-a', branchId: 'br-1');
    $b = new TenantContext(companyId: 'co-b', branchId: 'br-1');

    expect($a->cacheKey('dashboard'))->not->toBe($b->cacheKey('dashboard'));

    Cache::put($a->cacheKey('dashboard'), 'alpha', 60);
    Cache::put($b->cacheKey('dashboard'), 'beta', 60);

    expect(Cache::get($a->cacheKey('dashboard')))->toBe('alpha');
    expect(Cache::get($b->cacheKey('dashboard')))->toBe('beta');
});

test('storage metadata asserts same tenant and denies cross-company access', function () {
    TenantContextHolder::set(new TenantContext(companyId: 'co-1', branchId: 'br-1'));

    $meta = TenantStorageMetadata::forCurrentContext();
    expect($meta['company_id'])->toBe('co-1');

    TenantStorageMetadata::assertSameTenant($meta);

    TenantContextHolder::set(new TenantContext(companyId: 'co-2', branchId: 'br-1'));

    expect(fn () => TenantStorageMetadata::assertSameTenant($meta))
        ->toThrow(HttpException::class);
});

test('tenant-aware job restores context from payload', function () {
    $context = new TenantContext(companyId: 'co-job', branchId: 'br-job');
    TenantContextHolder::set($context);

    $job = (new ExampleTenantJob)->withTenantContext();
    TenantContextHolder::clear();

    $job->handle();

    expect(TenantContextHolder::require()->companyId)->toBe('co-job');
});

test('outbox style payloads carry tenant identifiers from context', function () {
    TenantContextHolder::set(new TenantContext(
        companyId: 'co-9',
        branchId: 'br-9',
        ownerId: 'own-1',
        isOwnerScope: true,
    ));

    $payload = [
        'event' => 'ExampleOccurred',
        'tenant' => TenantContextHolder::require()->toArray(),
    ];

    expect($payload['tenant']['company_id'])->toBe('co-9');
    expect($payload['tenant']['branch_id'])->toBe('br-9');
    expect($payload['tenant']['owner_id'])->toBe('own-1');
    expect($payload['tenant']['is_owner_scope'])->toBeTrue();
});
