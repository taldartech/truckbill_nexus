<?php

namespace App\Support\Observability;

final class CorrelationContext
{
    private static ?string $correlationId = null;

    public static function set(string $correlationId): void
    {
        self::$correlationId = $correlationId;
    }

    public static function get(): ?string
    {
        return self::$correlationId;
    }

    public static function require(): string
    {
        return self::$correlationId ?? CorrelationId::generate();
    }

    public static function clear(): void
    {
        self::$correlationId = null;
    }
}
