<?php

declare(strict_types=1);

namespace App\Support\Presentation;

use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;

/**
 * Server-side freight and revenue redaction for No-Freight roles (PRD §6).
 */
final class FreightAmountPresentation
{
    public function __construct(
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
    ) {}

    public function viewerMaySeeFreight(User $actor, Company $company, Branch $branch): bool
    {
        return $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::PLATFORM_VIEW_FREIGHT,
            $company,
            $branch,
        );
    }

    public function presentAmount(mixed $amount, bool $maySeeFreight): ?string
    {
        if (! $maySeeFreight) {
            return null;
        }

        if ($amount === null) {
            return null;
        }

        return is_string($amount) ? $amount : (string) $amount;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function redactKeys(array $payload, array $keys, bool $maySeeFreight): array
    {
        if ($maySeeFreight) {
            return $payload;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = null;
            }
        }

        return $payload;
    }
}
