<?php

namespace App\Domains\Numbering\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Precondition / input validation failures for document numbering.
 * Distinct from uniqueness conflicts ({@see DocumentNumberConflictException}).
 */
final class DocumentNumberValidationException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
