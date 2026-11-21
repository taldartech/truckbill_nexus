<?php

use App\Support\Audit\AuditEntry;
use App\Support\Audit\Harness\AuditHarnessRecord;
use App\Support\Idempotency\Exceptions\IdempotencyConflictException;
use App\Support\Idempotency\Exceptions\IdempotencyInProgressException;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Idempotency\IdempotencyRecord;
use App\Support\Idempotency\IdempotencyStatus;
use App\Support\Outbox\Consumers\OutboxConsumer;
use App\Support\Outbox\Consumers\OutboxConsumerRegistry;
use App\Support\Outbox\Harness\OutboxHarnessEffect;
use App\Support\Outbox\Harness\OutboxHarnessService;
use App\Support\Outbox\Jobs\ProcessOutboxMessageJob;
use App\Support\Outbox\OutboxDispatcher;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxProcessor;
use App\Support\Outbox\OutboxStatus;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenant\RequiresTenantContext;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

beforeEach(function () {
    TenantContextHolder::clear();
    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-outbox',
        branchId: 'br-outbox',
        ownerId: 'own-outbox',
    ));
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('business audit and outbox commit together', function () {
    $result = (new OutboxHarnessService)->createWithOutbox([
        'name' => 'Atomic',
        'effect_key' => 'created',
    ], correlationId: 'corr-1');

    expect(AuditHarnessRecord::query()->count())->toBe(1);
    expect(AuditEntry::query()->count())->toBe(1);
    expect(OutboxMessage::query()->count())->toBe(1);

    $message = OutboxMessage::query()->sole();
    expect($message->event_id)->toBe($result['event_id']);
    expect($message->status)->toBe(OutboxStatus::Pending->value);
    expect($message->company_id)->toBe('co-outbox');
    expect($message->correlation_id)->toBe('corr-1');
    expect($message->payload)->not->toHaveKey('password');
});

test('business failure creates no audit or outbox', function () {
    expect(fn () => (new OutboxHarnessService)->createThenFailBeforeOutbox(['name' => 'NoOutbox']))
        ->toThrow(RuntimeException::class);

    expect(AuditHarnessRecord::query()->count())->toBe(0);
    expect(AuditEntry::query()->count())->toBe(0);
    expect(OutboxMessage::query()->count())->toBe(0);
});

test('outbox insert failure rolls back business and audit', function () {
    expect(fn () => (new OutboxHarnessService)->createThenFailAfterOutbox(['name' => 'Rollback']))
        ->toThrow(RuntimeException::class);

    expect(AuditHarnessRecord::query()->count())->toBe(0);
    expect(AuditEntry::query()->count())->toBe(0);
    expect(OutboxMessage::query()->count())->toBe(0);
});

test('dispatcher cannot publish rolled-back outbox records', function () {
    Queue::fake();

    try {
        DB::transaction(function (): void {
            (new OutboxWriter)->enqueue('outbox.harness.effect', ['effect_key' => 'uncommitted']);
            throw new RuntimeException('abort before commit');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(OutboxMessage::query()->count())->toBe(0);
    expect((new OutboxDispatcher)->dispatchBatch())->toBe([]);
    Queue::assertNothingPushed();
});

test('successful dispatch marks delivery and applies side effect once', function () {
    $result = (new OutboxHarnessService)->createWithOutbox([
        'name' => 'Publish',
        'effect_key' => 'once',
    ]);

    $claimed = (new OutboxDispatcher)->dispatchBatch();
    expect($claimed)->toHaveCount(1);

    $message = OutboxMessage::query()->where('event_id', $result['event_id'])->sole();
    expect($message->status)->toBe(OutboxStatus::Published->value);

    expect(OutboxHarnessEffect::query()->where('event_id', $result['event_id'])->count())->toBe(1);
    expect(OutboxHarnessEffect::query()->sole()->apply_count)->toBe(1);
});

test('duplicate delivery does not duplicate side effects', function () {
    $result = (new OutboxHarnessService)->createWithOutbox([
        'name' => 'Dup',
        'effect_key' => 'dup',
    ]);

    (new OutboxDispatcher)->dispatchBatch();

    // Sync queue middleware clears tenant context after the job.
    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-outbox',
        branchId: 'br-outbox',
        ownerId: 'own-outbox',
    ));

    $message = OutboxMessage::query()->where('event_id', $result['event_id'])->sole();
    expect($message->status)->toBe(OutboxStatus::Published->value);

    // Force a re-delivery attempt.
    $message->status = OutboxStatus::Processing->value;
    $message->save();

    (new OutboxProcessor)->process($message->refresh());

    expect(OutboxHarnessEffect::query()->where('event_id', $result['event_id'])->count())->toBe(1);
    expect(OutboxHarnessEffect::query()->sole()->apply_count)->toBe(1);
});

test('concurrent claim does not assign the same message twice', function () {
    Queue::fake();

    (new OutboxHarnessService)->createWithOutbox(['name' => 'Claim']);

    $first = (new OutboxDispatcher)->dispatchBatch(limit: 10, workerId: 'w1');
    $second = (new OutboxDispatcher)->dispatchBatch(limit: 10, workerId: 'w2');

    expect($first)->toHaveCount(1);
    expect($second)->toHaveCount(0);
    expect(OutboxMessage::query()->where('status', OutboxStatus::Processing->value)->count())->toBe(1);
});

test('retry count is bounded and exhausted messages become dead', function () {
    $message = (new OutboxWriter)->enqueue(
        eventType: 'outbox.harness.effect',
        payload: ['effect_key' => 'fail'],
        maxAttempts: 2,
    );

    app()->bind(OutboxConsumerRegistry::class, function () {
        $registry = new OutboxConsumerRegistry([]);
        $registry->register('outbox.harness.effect', new class implements OutboxConsumer
        {
            public function handle(OutboxMessage $message): void
            {
                throw new RuntimeException('consumer boom');
            }
        });

        return $registry;
    });

    $message->status = OutboxStatus::Processing->value;
    $message->save();

    expect(fn () => app(OutboxProcessor::class)->process($message->refresh()))
        ->toThrow(RuntimeException::class);

    $message->refresh();
    expect($message->attempts)->toBe(1);
    expect($message->status)->toBe(OutboxStatus::Pending->value);

    $message->status = OutboxStatus::Processing->value;
    $message->available_at = now()->subSecond();
    $message->save();

    expect(fn () => app(OutboxProcessor::class)->process($message->refresh()))
        ->toThrow(RuntimeException::class);

    $message->refresh();
    expect($message->attempts)->toBe(2);
    expect($message->status)->toBe(OutboxStatus::Dead->value);
    expect($message->last_error)->toContain('consumer boom');
});

test('missing tenant context fails closed for outbox enqueue', function () {
    TenantContextHolder::clear();

    expect(fn () => (new OutboxWriter)->enqueue('outbox.harness.effect', ['effect_key' => 'x']))
        ->toThrow(RuntimeException::class);
});

test('cross-tenant job execution is denied', function () {
    $result = (new OutboxHarnessService)->createWithOutbox(['name' => 'Tenant']);
    $message = OutboxMessage::query()->where('event_id', $result['event_id'])->sole();
    $message->status = OutboxStatus::Processing->value;
    $message->save();

    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-other',
        branchId: 'br-outbox',
        ownerId: 'own-outbox',
    ));

    $job = (new ProcessOutboxMessageJob($message->id))
        ->withTenantContext(TenantContextHolder::require());

    expect(fn () => $job->handle(app(OutboxProcessor::class)))
        ->toThrow(RuntimeException::class);
});

test('tenant context is cleared after job middleware success and failure', function () {
    $result = (new OutboxHarnessService)->createWithOutbox(['name' => 'Clear']);
    $message = OutboxMessage::query()->where('event_id', $result['event_id'])->sole();
    $message->status = OutboxStatus::Processing->value;
    $message->save();

    $job = (new ProcessOutboxMessageJob($message->id))
        ->withTenantContext($message->tenantContext());

    $middleware = new RequiresTenantContext;
    $middleware->handle($job, function ($job) {
        expect(TenantContextHolder::has())->toBeTrue();
        $job->handle(app(OutboxProcessor::class));

        return null;
    });

    expect(TenantContextHolder::has())->toBeFalse();

    $failing = OutboxMessage::query()->create([
        'event_id' => (string) Str::uuid(),
        'event_type' => 'outbox.harness.effect',
        'payload' => ['effect_key' => 'x'],
        'company_id' => 'co-outbox',
        'branch_id' => 'br-outbox',
        'owner_id' => 'own-outbox',
        'is_internal' => false,
        'is_owner_scope' => false,
        'status' => OutboxStatus::Processing->value,
        'attempts' => 0,
        'max_attempts' => 1,
        'available_at' => now(),
    ]);

    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-outbox',
        branchId: 'br-outbox',
        ownerId: 'own-outbox',
    ));

    app()->bind(OutboxConsumerRegistry::class, function () {
        $registry = new OutboxConsumerRegistry([]);
        $registry->register('outbox.harness.effect', new class implements OutboxConsumer
        {
            public function handle(OutboxMessage $message): void
            {
                throw new RuntimeException('fail clear');
            }
        });

        return $registry;
    });

    $failJob = (new ProcessOutboxMessageJob($failing->id))
        ->withTenantContext($failing->tenantContext());

    try {
        $middleware->handle($failJob, function ($job) {
            $job->handle(app(OutboxProcessor::class));

            return null;
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(TenantContextHolder::has())->toBeFalse();
});

test('idempotent replay returns stored result without re-running callback', function () {
    $calls = 0;
    $guard = new IdempotencyGuard;

    $first = $guard->run('test.scope', 'key-1', function () use (&$calls) {
        $calls++;

        return ['ok' => true];
    }, requestHash: 'hash-a');

    $second = $guard->run('test.scope', 'key-1', function () use (&$calls) {
        $calls++;

        return ['ok' => false];
    }, requestHash: 'hash-a');

    expect($calls)->toBe(1);
    expect($first)->toBe(['ok' => true]);
    expect($second)->toBe(['ok' => true]);
});

test('conflicting idempotency key reuse is rejected', function () {
    $guard = new IdempotencyGuard;

    $guard->run('test.scope', 'key-2', fn () => ['a' => 1], requestHash: 'hash-1');

    expect(fn () => $guard->run('test.scope', 'key-2', fn () => ['a' => 2], requestHash: 'hash-2'))
        ->toThrow(IdempotencyConflictException::class);
});

test('concurrent duplicate idempotency requests are safe', function () {
    $guard = new IdempotencyGuard;

    IdempotencyRecord::query()->create([
        'scope' => 'test.scope',
        'idempotency_key' => 'key-3',
        'company_id' => 'co-outbox',
        'status' => IdempotencyStatus::Processing->value,
        'request_hash' => 'hash-x',
        'locked_at' => now(),
        'expires_at' => now()->addDays(2),
    ]);

    expect(fn () => $guard->run('test.scope', 'key-3', fn () => ['x' => 1], requestHash: 'hash-x'))
        ->toThrow(IdempotencyInProgressException::class);
});

test('sensitive payload fields are redacted in outbox', function () {
    $message = (new OutboxWriter)->enqueue('outbox.harness.effect', [
        'effect_key' => 'secret',
        'password' => 'super-secret',
        'token' => 'abc',
        'safe' => 'ok',
    ]);

    expect($message->payload['password'] ?? null)->toBe('[redacted]');
    expect($message->payload['token'] ?? null)->toBe('[redacted]');
    expect($message->payload['safe'])->toBe('ok');
});
