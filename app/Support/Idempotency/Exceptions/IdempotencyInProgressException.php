<?php

namespace App\Support\Idempotency\Exceptions;

use RuntimeException;

class IdempotencyInProgressException extends RuntimeException {}
