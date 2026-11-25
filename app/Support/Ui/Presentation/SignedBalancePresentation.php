<?php

namespace App\Support\Ui\Presentation;

/**
 * Split a signed financial position into non-negative Balance Due and Negative Adjusted Amount.
 */
final class SignedBalancePresentation
{
    /**
     * @param  numeric-string  $signedBalance
     * @return array{balance_due: numeric-string, negative_adjusted_amount: numeric-string, signed_balance: numeric-string}
     */
    public function split(string $signedBalance): array
    {
        $signed = $this->money($signedBalance);

        if (bccomp($signed, '0.00', 2) >= 0) {
            return [
                'balance_due' => $signed,
                'negative_adjusted_amount' => '0.00',
                'signed_balance' => $signed,
            ];
        }

        return [
            'balance_due' => '0.00',
            'negative_adjusted_amount' => bcmul($signed, '-1', 2),
            'signed_balance' => $signed,
        ];
    }

    /**
     * @param  numeric-string|string|float|int  $value
     * @return numeric-string
     */
    private function money(string|float|int $value): string
    {
        if (! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
