<?php

namespace App\Domains\Rbac;

use App\Models\User;
use App\Support\Cache\RequestScopedMemo;
use Illuminate\Support\Facades\Cache;

/**
 * Version-stamp invalidation (no cache-tag dependency; works with array/database/redis).
 *
 * Cached permission sets are never authoritative — stamps force recompute.
 */
final class PermissionCacheInvalidator
{
    private const GLOBAL_VERSION_KEY = 'rbac:generation';

    private const MEMO_KEY_PREFIX = 'rbac:memo:';

    public function globalGeneration(): int
    {
        return (int) Cache::get(self::GLOBAL_VERSION_KEY, 0);
    }

    public function userGeneration(int|string $userId): int
    {
        return (int) Cache::get($this->userVersionKey($userId), 0);
    }

    public function bumpGlobal(): void
    {
        Cache::forever(self::GLOBAL_VERSION_KEY, $this->globalGeneration() + 1);
        app(RequestScopedMemo::class)->forgetByPrefix(self::MEMO_KEY_PREFIX);
    }

    public function bumpUser(User|int|string $user): void
    {
        $userId = $user instanceof User ? $user->getAuthIdentifier() : $user;
        Cache::forever($this->userVersionKey($userId), $this->userGeneration($userId) + 1);
        app(RequestScopedMemo::class)->forgetByPrefix($this->userMemoKeyPrefix($userId));
    }

    public function cacheKey(
        string $domain,
        int|string $userId,
        ?string $branchId,
    ): string {
        return implode(':', [
            'rbac',
            'codes',
            $domain,
            'u'.$userId,
            'b'.($branchId ?? '_'),
            'g'.$this->globalGeneration(),
            'uv'.$this->userGeneration($userId),
        ]);
    }

    /**
     * Request-scoped memo key; generation-free because bumps forget matching memo entries.
     */
    public function memoKey(
        string $domain,
        int|string $userId,
        ?string $branchId,
    ): string {
        return $this->userMemoKeyPrefix($userId).$domain.':b'.($branchId ?? '_');
    }

    private function userMemoKeyPrefix(int|string $userId): string
    {
        return self::MEMO_KEY_PREFIX.'u'.$userId.':';
    }

    private function userVersionKey(int|string $userId): string
    {
        return 'rbac:user-generation:'.$userId;
    }
}
