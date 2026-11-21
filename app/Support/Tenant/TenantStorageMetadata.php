<?php

namespace App\Support\Tenant;

/**
 * Tenant metadata helpers for private file storage (TB-SEC-001 / TB-DOC-001 prep).
 *
 * Branch access fails closed unless the active context explicitly supports the metadata scope.
 *
 * Phase 1 rules (fail closed; no invented membership):
 * - Different company → deny.
 * - Branch-scoped metadata requires matching branch_id, or Owner-scope context for the same company.
 * - Company-scoped metadata (branch_id null) is Shared Data: same-company branch contexts may access.
 * - Company-level context (no branch, not Owner-scope) may NOT access branch-scoped metadata.
 *
 * PO decision needed later: whether Internal Team / other roles may widen branch access without Owner-scope.
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
        $metaCompany = $metadata['company_id'] ?? null;
        $metaBranch = array_key_exists('branch_id', $metadata) ? $metadata['branch_id'] : null;

        if ($metaCompany !== $context->companyId) {
            abort(403, 'Cross-tenant file access denied.');
        }

        $metaIsBranchScoped = $metaBranch !== null && $metaBranch !== '';
        $contextIsBranchScoped = $context->hasBranch();

        if ($metaIsBranchScoped) {
            if ($context->isOwnerScope && $context->hasCompany()) {
                return;
            }

            if ($contextIsBranchScoped && $context->branchId === $metaBranch) {
                return;
            }

            abort(403, 'Cross-tenant file access denied.');
        }

        // Company-scoped (Shared Data) metadata: same company is required (already checked).
        // Branch context may read company-scoped shared files; company-level context may also.
    }
}
