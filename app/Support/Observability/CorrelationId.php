<?php

namespace App\Support\Observability;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Request/job correlation identifier (WS-7).
 *
 * Client-supplied values are accepted only when they match a strict safe pattern.
 */
final class CorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/';

    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    public static function normalize(?string $candidate): string
    {
        if ($candidate === null) {
            return self::generate();
        }

        $candidate = trim($candidate);

        if ($candidate === '' || preg_match(self::PATTERN, $candidate) !== 1) {
            throw new InvalidArgumentException('Invalid correlation id.');
        }

        return $candidate;
    }

    public static function tryNormalize(?string $candidate): string
    {
        try {
            return self::normalize($candidate);
        } catch (InvalidArgumentException) {
            return self::generate();
        }
    }
}
