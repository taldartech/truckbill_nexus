<?php

namespace App\Domains\Identity\Exceptions;

use RuntimeException;

/**
 * Scaffolding for TB-AUTH-008 — last active Super Admin cannot be deactivated or demoted.
 */
final class LastSuperAdminProtectedException extends RuntimeException {}
