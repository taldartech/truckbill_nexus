<?php

namespace App\Domains\Enquiry\Support;

/**
 * Stable semantic fingerprint for durable external-reference deduplication.
 * Field order in raw JSON must not affect the hash.
 */
final class EnquiryPayloadFingerprint
{
    /**
     * @param  array<string, mixed>  $normalized
     */
    public static function hash(array $normalized): string
    {
        return hash('sha256', self::canonicalJson($normalized));
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public static function canonicalJson(array $normalized): string
    {
        return json_encode(self::ksortRecursive($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::ksortRecursive($item);
            }
        }

        ksort($value);

        return $value;
    }
}
