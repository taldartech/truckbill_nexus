<?php

namespace App\Support\Cache;

use Closure;

/**
 * In-memory memo layered over the cross-request Cache store for the lifetime of one HTTP request.
 *
 * Bound as a scoped instance and activated only by ScopeRequestMemo middleware, so console
 * commands, queue jobs and code outside a request always fall through to the callback.
 * Entries are discarded when the request ends; tenant state is never shared across requests.
 */
final class RequestScopedMemo
{
    private bool $active = false;

    /** @var array<string, mixed> */
    private array $entries = [];

    public function activate(): void
    {
        $this->entries = [];
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->entries = [];
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function remember(string $key, Closure $callback): mixed
    {
        if (! $this->active) {
            return $callback();
        }

        if (array_key_exists($key, $this->entries)) {
            /** @var TValue */
            return $this->entries[$key];
        }

        $value = $callback();
        $this->entries[$key] = $value;

        return $value;
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function forgetByPrefix(string $prefix): void
    {
        foreach (array_keys($this->entries) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->entries[$key]);
            }
        }
    }
}
