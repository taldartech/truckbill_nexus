<?php

namespace App\Support\Presentation;

/**
 * Indian lakh/crore grouping and amount-in-words (legacy NumberHelpers parity).
 */
final class IndianNumber
{
    /**
     * @param  float|int|numeric-string  $number
     */
    public static function format(float|int|string $number, int $decimals = 2): string
    {
        $isNegative = (float) $number < 0;
        $number = abs((float) $number);

        $rounded = number_format($number, $decimals, '.', '');
        [$integer, $fraction] = array_pad(explode('.', $rounded), 2, '');

        $lastThree = substr($integer, -3);
        $otherDigits = substr($integer, 0, -3);

        if ($otherDigits !== '') {
            $lastThree = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $otherDigits).','.$lastThree;
        }

        $formatted = $lastThree;
        if ($decimals > 0) {
            $formatted .= '.'.$fraction;
        }

        return ($isNegative ? '-' : '').$formatted;
    }

    /**
     * @param  float|int|numeric-string  $number
     */
    public static function amountInWords(float|int|string $number): string
    {
        $number = (float) $number;
        $decimal = round($number - ($no = floor($number)), 2) * 100;
        $digitsLength = strlen((string) $no);
        $i = 0;
        $parts = [];
        $words = [
            0 => '', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
            6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
            11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen',
            16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen', 20 => 'twenty',
            30 => 'thirty', 40 => 'forty', 50 => 'fifty', 60 => 'sixty', 70 => 'seventy',
            80 => 'eighty', 90 => 'ninety',
        ];
        $digits = ['', 'hundred', 'thousand', 'lakh', 'crore'];
        $hundred = null;

        while ($i < $digitsLength) {
            $divider = $i === 2 ? 10 : 100;
            $chunk = floor($no % $divider);
            $no = floor($no / $divider);
            $i += $divider === 10 ? 1 : 2;
            if ($chunk) {
                $counter = count($parts);
                $plural = $counter && $chunk > 9 ? 's' : null;
                $hundred = $counter === 1 && ($parts[0] ?? null) ? ' and ' : null;
                $parts[] = $chunk < 21
                    ? $words[(int) $chunk].' '.$digits[$counter].$plural.' '.$hundred
                    : $words[(int) floor($chunk / 10) * 10].' '.$words[(int) $chunk % 10].' '.$digits[$counter].$plural.' '.$hundred;
            } else {
                $parts[] = null;
            }
        }

        $rupees = implode('', array_reverse($parts));
        $paise = $decimal
            ? ($rupees ? 'and ' : ' ').($decimal < 21
                ? $words[(int) $decimal]
                : $words[(int) floor($decimal / 10) * 10].' '.$words[(int) $decimal % 10]).' Paise'
            : '';

        return ucwords(($rupees ? $rupees.'Rupees ' : '').$paise);
    }
}
