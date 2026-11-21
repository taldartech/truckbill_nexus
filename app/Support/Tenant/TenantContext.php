<?php

namespace App\Support\Tenant;

/**
 * Explicit tenant context carrier (ADR-002).
 *
 * Phase 1: context is injected by trusted server code / harnesses.
 * Full membership resolution is Phase 2. Client-supplied tenant IDs are never trusted.
 */
final class TenantContext
{
    public function __construct(
        public readonly ?string $companyId = null,
        public readonly ?string $branchId = null,
        public readonly ?string $ownerId = null,
        public readonly bool $isInternal = false,
        public readonly bool $isOwnerScope = false,
    ) {}

    public function hasCompany(): bool
    {
        return $this->companyId !== null && $this->companyId !== '';
    }

    public function isEmpty(): bool
    {
        return ! $this->hasCompany()
            && ($this->branchId === null || $this->branchId === '')
            && ($this->ownerId === null || $this->ownerId === '')
            && ! $this->isInternal
            && ! $this->isOwnerScope;
    }

    /**
     * @return array{company_id: ?string, branch_id: ?string, owner_id: ?string, is_internal: bool, is_owner_scope: bool}
     */
    public function toArray(): array
    {
        return [
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'owner_id' => $this->ownerId,
            'is_internal' => $this->isInternal,
            'is_owner_scope' => $this->isOwnerScope,
        ];
    }

    /**
     * @param  array{company_id?: ?string, branch_id?: ?string, owner_id?: ?string, is_internal?: bool, is_owner_scope?: bool}  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            companyId: $payload['company_id'] ?? null,
            branchId: $payload['branch_id'] ?? null,
            ownerId: $payload['owner_id'] ?? null,
            isInternal: (bool) ($payload['is_internal'] ?? false),
            isOwnerScope: (bool) ($payload['is_owner_scope'] ?? false),
        );
    }

    public function cacheKey(string $suffix): string
    {
        $company = $this->companyId ?? 'none';
        $branch = $this->branchId ?? 'none';

        return "tenant:{$company}:{$branch}:{$suffix}";
    }
}
