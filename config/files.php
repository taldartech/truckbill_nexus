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
    | Non-production drivers: "pass" (always clean), "fake" (deterministic test),
    | "reject" (always unclean). These drivers are refused in production.
    |
    | Production must set FILES_SCANNER to an approved production driver after
    | PO-P1-DEPS approval. Until then, production scanner resolution fails closed.
    | Never rely on a silent pass-through default in production.
    |
    */

    'scanner' => env('FILES_SCANNER', 'pass'),

];
