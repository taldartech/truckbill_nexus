<?php

namespace App\Domains\Identity\Exceptions;

use RuntimeException;

/**
 * DEC-003 / ADR-004 — hard deletion of identities is prohibited.
 */
final class IdentityHardDeleteProhibitedException extends RuntimeException {}
