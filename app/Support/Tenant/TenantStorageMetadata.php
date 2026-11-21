<?php

namespace App\Support\Tenant;

/**
 * Tenant metadata helpers for private file storage (TB-SEC-001 / TB-DOC-001 prep).
 */
final class TenantStorageMetadata
{
    /**
     * @return array{company_id: string, branch_id: ?string, owner_id: ?string}
     */
    public static function forCurrentContext(): array
    {
        $context = TenantContextHolder::require();

        return [
            'company_id' => (string) $context->companyId,
            'branch_id' => $context->branchId,
            'owner_id' => $context->ownerId,
        ];
    }

    /**
     * @param  array{company_id?: mixed, branch_id?: mixed}  $metadata
     */
    public static function assertSameTenant(array $metadata): void
    {
        $context = TenantContextHolder::require();

        if (($metadata['company_id'] ?? null) !== $context->companyId) {
            abort(403, 'Cross-tenant file access denied.');
        }

        if (array_key_exists('branch_id', $metadata)
            && $metadata['branch_id'] !== null
            && $context->branchId !== null
            && $metadata['branch_id'] !== $context->branchId) {
            abort(403, 'Cross-tenant file access denied.');
        }
    }
}
