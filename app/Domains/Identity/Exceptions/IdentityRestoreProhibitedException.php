<?php

namespace App\Domains\Identity\Exceptions;

use RuntimeException;

/**
 * DEC-003 / ADR-004 — identity restoration is prohibited.
 */
final class IdentityRestoreProhibitedException extends RuntimeException {}
