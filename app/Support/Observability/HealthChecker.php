<?php

namespace App\Support\Observability;

use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness probes for Phase 1 infrastructure (no secrets in results).
 */
class HealthChecker
{
    /**
     * @return array{status: string, checks: array<string, array{status: string, detail?: string}>}
     */
    public function readiness(): array
    {
        $checks = [
            'database' => $this->database(),
            'redis' => $this->redis(),
            'queue' => $this->queue(),
            'outbox' => $this->outbox(),
        ];

        $ok = collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok');

        return [
            'status' => $ok ? 'ready' : 'degraded',
            'checks' => $checks,
        ];
    }

    /**
     * @return array{status: string, detail?: string}
     */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => 'database unreachable'];
        }
    }

    /**
     * @return array{status: string, detail?: string}
     */
    private function redis(): array
    {
        try {
            Redis::connection()->ping();

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => 'redis unreachable'];
        }
    }

    /**
     * @return array{status: string, detail?: string}
     */
    private function queue(): array
    {
        try {
            $connection = (string) config('queue.default');

            return [
                'status' => 'ok',
                'detail' => 'connection:'.$connection,
            ];
        } catch (Throwable) {
            return ['status' => 'fail', 'detail' => 'queue configuration unavailable'];
        }
    }

    /**
     * @return array{status: string, detail?: string}
     */
    private function outbox(): array
    {
        try {
            $dead = OutboxMessage::query()->where('status', OutboxStatus::Dead->value)->count();
            $pending = OutboxMessage::query()->where('status', OutboxStatus::Pending->value)->count();

            return [
                'status' => 'ok',
                'detail' => 'pending:'.$pending.';dead:'.$dead,
            ];
        } catch (Throwable) {
            return ['status' => 'fail', 'detail' => 'outbox unavailable'];
        }
    }
}
