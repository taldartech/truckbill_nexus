<?php

namespace App\Domains\Numbering\Exceptions;

use RuntimeException;
use Throwable;

final class DocumentNumberConflictException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
