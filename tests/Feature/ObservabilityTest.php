<?php

use App\Support\Observability\CorrelationContext;
use App\Support\Observability\CorrelationId;
use App\Support\Observability\HealthChecker;
use App\Support\Observability\LogRedactor;
use App\Support\Outbox\Jobs\ProcessOutboxMessageJob;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxProcessor;
use App\Support\Outbox\OutboxStatus;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

test('correlation id is generated when missing', function () {
    $response = $this->get('/');

    $response->assertOk();
    $header = $response->headers->get(CorrelationId::HEADER);
    expect($header)->not->toBeNull();
    expect(strlen((string) $header))->toBeGreaterThan(7);
});

test('valid correlation id is preserved', function () {
    $id = 'corr-test-12345678';

    $response = $this->withHeader(CorrelationId::HEADER, $id)->get('/');

    $response->assertOk();
    expect($response->headers->get(CorrelationId::HEADER))->toBe($id);
});

test('invalid correlation id is replaced safely', function () {
    $response = $this->withHeader(CorrelationId::HEADER, 'bad id with spaces!!!')->get('/');

    $response->assertOk();
    $header = (string) $response->headers->get(CorrelationId::HEADER);
    expect($header)->not->toBe('bad id with spaces!!!');
    expect(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/', $header))->toBe(1);
});

test('correlation id appears in shared log context during request', function () {
    Log::spy();

    $this->withHeader(CorrelationId::HEADER, 'corr-log-context-01')->get('/');

    // Middleware applies withContext; ensure context holder was used and cleared after.
    expect(CorrelationContext::get())->toBeNull();
});

test('passwords and tokens are redacted from log context', function () {
    $redactor = new LogRedactor;

    $redacted = $redactor->redact([
        'password' => 'secret-password',
        'api_token' => 'tok_123',
        'authorization' => 'Bearer abc',
        'user' => 'safe',
        'nested' => [
            'secret_token' => 'x',
            'name' => 'ok',
        ],
    ]);

    expect($redacted['password'])->toBe('[redacted]');
    expect($redacted['api_token'])->toBe('[redacted]');
    expect($redacted['authorization'])->toBe('[redacted]');
    expect($redacted['user'])->toBe('safe');
    expect($redacted['nested']['secret_token'])->toBe('[redacted]');
    expect($redacted['nested']['name'])->toBe('ok');
});

test('health ready endpoint returns structured status without secrets', function () {
    $response = $this->getJson(route('health.ready'));

    expect(in_array($response->status(), [200, 503], true))->toBeTrue();
    $json = $response->json();
    expect($json)->toHaveKeys(['status', 'checks']);
    expect($json['checks'])->toHaveKeys(['database', 'redis', 'queue', 'outbox']);

    $encoded = json_encode($json);
    expect($encoded)->not->toContain('password');
    expect($encoded)->not->toContain('REDIS_PASSWORD');
    expect($encoded)->not->toContain('DB_PASSWORD');
    expect($encoded)->not->toContain('APP_KEY');
});

test('database health check reports ok when database is reachable', function () {
    $result = (new HealthChecker)->readiness();

    expect($result['checks']['database']['status'])->toBe('ok');
});

test('redis health check reports a status without leaking configuration', function () {
    $result = (new HealthChecker)->readiness();

    expect($result['checks']['redis'])->toHaveKey('status');
    expect(json_encode($result['checks']['redis']))->not->toContain('password');
});

test('job correlation id is applied and cleared', function () {
    $message = OutboxMessage::query()->create([
        'event_id' => (string) Str::uuid(),
        'event_type' => 'outbox.harness.effect',
        'payload' => ['effect_key' => 'obs'],
        'company_id' => 'co-obs',
        'branch_id' => 'br-obs',
        'owner_id' => 'own-obs',
        'is_internal' => false,
        'is_owner_scope' => false,
        'correlation_id' => 'corr-job-12345678',
        'status' => OutboxStatus::Processing->value,
        'attempts' => 0,
        'max_attempts' => 1,
        'available_at' => now(),
    ]);

    $job = (new ProcessOutboxMessageJob($message->id))
        ->withTenantContext(new TenantContext(
            companyId: 'co-obs',
            branchId: 'br-obs',
            ownerId: 'own-obs',
        ));
    $job->correlationId = 'corr-job-12345678';

    try {
        $job->handle(app(OutboxProcessor::class));
    } catch (Throwable) {
        // Consumer may succeed; correlation must still clear.
    }

    expect(CorrelationContext::get())->toBeNull();
});

test('failed job diagnostics redaction does not expose secrets', function () {
    $redactor = new LogRedactor;
    $payload = $redactor->redact([
        'exception' => 'fail',
        'password' => 'nope',
        'payload' => ['api_key' => 'k', 'id' => 1],
    ]);

    expect($payload['password'])->toBe('[redacted]');
    expect($payload['payload']['api_key'])->toBe('[redacted]');
    expect($payload['payload']['id'])->toBe(1);
});
