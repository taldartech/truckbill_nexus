<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Private files disk
    |--------------------------------------------------------------------------
    |
    | Dedicated private disk for document uploads. Must not be publicly served.
    |
    */

    'disk' => env('FILES_DISK', 'private_files'),

    'max_bytes' => 2 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Allowed extensions and MIME types
    |--------------------------------------------------------------------------
    */

    'allowed_extensions' => [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
    ],

    'allowed_mimes' => [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Malware scanner
    |--------------------------------------------------------------------------
    |
    | Drivers: "pass" (always clean), "fake" (test deterministic), "reject" (always fail).
    | Real external scanners require PO-P1-DEPS approval before installation.
    |
    */

    'scanner' => env('FILES_SCANNER', 'pass'),

];
