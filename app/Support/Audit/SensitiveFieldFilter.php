<?php

namespace App\Support\Audit;

/**
 * Removes or masks sensitive keys from audit before/after payloads.
 */
final class SensitiveFieldFilter
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'api_token',
        'api_key',
        'secret',
        'secret_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'authorization',
        'cookie',
        'credit_card',
        'card_number',
        'cvv',
    ];

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function filter(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return $this->filterArray($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterArray(array $payload): array
    {
        $filtered = [];

        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);

            if (in_array($normalized, self::SENSITIVE_KEYS, true)
                || str_contains($normalized, 'password')
                || str_contains($normalized, 'secret')
                || str_ends_with($normalized, '_token')) {
                $filtered[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $filtered[$key] = $this->filterArray($value);

                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }
}
