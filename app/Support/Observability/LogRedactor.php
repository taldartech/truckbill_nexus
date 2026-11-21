<?php

namespace App\Support\Observability;

/**
 * Masks sensitive keys in log context arrays (WS-7).
 */
final class LogRedactor
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
        'session_id',
        'php_session',
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function redact(array $context): array
    {
        return $this->redactArray($context);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactArray(array $payload): array
    {
        $filtered = [];

        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);

            if (in_array($normalized, self::SENSITIVE_KEYS, true)
                || str_contains($normalized, 'password')
                || str_contains($normalized, 'secret')
                || str_ends_with($normalized, '_token')
                || str_contains($normalized, 'authorization')) {
                $filtered[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $filtered[$key] = $this->redactArray($value);

                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }
}
