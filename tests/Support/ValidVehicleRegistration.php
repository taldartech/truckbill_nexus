<?php

/**
 * Deterministic valid Indian registration numbers for HTTP/feature tests.
 */
function validVehicleRegistration(string $token): string
{
    $token = strtoupper(preg_replace('/[^A-Z0-9]/', '', $token) ?? '');
    $series = substr(str_pad($token, 2, 'X'), 0, 2);
    $number = str_pad((string) (abs(crc32($token)) % 10000), 4, '0', STR_PAD_LEFT);

    return 'MH12'.$series.$number;
}
