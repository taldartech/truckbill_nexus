<?php

namespace App\Support\Tenant;

/**
 * Trait for queued jobs that must carry and restore tenant context.
 */
trait TenantAware
{
    /** @var array{company_id: ?string, branch_id: ?string, owner_id: ?string, is_internal: bool, is_owner_scope: bool}|null */
    public ?array $tenantContextPayload = null;

    public function withTenantContext(?TenantContext $context = null): static
    {
        $context ??= TenantContextHolder::require();
        $this->tenantContextPayload = $context->toArray();

        return $this;
    }

    public function restoreTenantContext(): void
    {
        if ($this->tenantContextPayload === null) {
            TenantContextHolder::clear();

            return;
        }

        TenantContextHolder::set(TenantContext::fromArray($this->tenantContextPayload));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RequiresTenantContext];
    }
}
