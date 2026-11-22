<?php

namespace App\Support\Tenant;

use RuntimeException;

/**
 * Request/job scoped holder for the active tenant context.
 *
 * Only trusted server-side code may call set()/inject(). Client input is never authoritative.
 */
final class TenantContextHolder
{
    private static ?TenantContext $context = null;

    public static function set(TenantContext $context): void
    {
        self::$context = $context;
    }

    /**
     * Trusted server-side injection helper.
     * Prefer CompanyAccessService::activateContext() when resolving from membership/Owner.
     */
    public static function inject(TenantContext $context): void
    {
        self::set($context);
    }

    public static function clear(): void
    {
        self::$context = null;
    }

    public static function get(): ?TenantContext
    {
        return self::$context;
    }

    public static function require(): TenantContext
    {
        if (self::$context === null || self::$context->isEmpty()) {
            throw new RuntimeException('Tenant context is required but was not set.');
        }

        return self::$context;
    }

    public static function has(): bool
    {
        return self::$context !== null && ! self::$context->isEmpty();
    }
}
