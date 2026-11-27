<?php

namespace App\Domains\Document;

use Carbon\CarbonImmutable;

/**
 * Optional identification / validity metadata captured for validity-tracked document types.
 */
final readonly class DocumentValidity
{
    public function __construct(
        public ?string $documentNumber = null,
        public ?CarbonImmutable $issuedOn = null,
        public ?CarbonImmutable $expiresOn = null,
    ) {}
}
