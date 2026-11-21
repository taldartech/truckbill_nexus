<?php

namespace App\Support\Audit;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Atomic mutation + audit pattern (ADR-003).
 *
 * Usage:
 *   $tx->run(function () use ($writer) {
 *       $model = ... mutate ...
 *       $writer->write(...);
 *       // WS-6 extension point: insert outbox row(s) here in the same transaction.
 *       return $model;
 *   });
 *
 * Nested calls participate in Laravel's transaction nesting (savepoints).
 * Do not dispatch external side effects before commit.
 */
class AuditedTransaction
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws Throwable
     */
    public function run(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            return $callback();
        });
    }
}
