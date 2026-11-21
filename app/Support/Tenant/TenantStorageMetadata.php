<?php

namespace App\Support\Tenant;

/**
 * Tenant metadata helpers for private file storage (TB-SEC-001 / TB-DOC-001 prep).
 *
 * Phase 1 rules (fail closed; no invented membership / Internal Team privileges):
 * - Different company → deny.
 * - When metadata carries owner_id, context.owner_id must match (Owner-specific artefacts).
 * - Branch-scoped metadata requires matching branch_id, or Owner-scope with matching owner identity.
 * - Company-scoped metadata (branch_id null) is Shared Data: same-company contexts may access
 *   when owner_id constraints (if present) also match.
 * - Company-level context (no branch, not Owner-scope) may NOT access branch-scoped metadata.
 * - Owner-scope without owner_id → deny for branch-scoped access (fail closed).
 *
 * Pending PO: whether Internal Team may access branch-scoped files without matching branch/owner.
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
     * @param  array{company_id?: mixed, branch_id?: mixed, owner_id?: mixed}  $metadata
     */
    public static function assertSameTenant(array $metadata): void
    {
        $context = TenantContextHolder::require();
        $metaCompany = $metadata['company_id'] ?? null;
        $metaBranch = array_key_exists('branch_id', $metadata) ? $metadata['branch_id'] : null;
        $metaOwner = array_key_exists('owner_id', $metadata) ? $metadata['owner_id'] : null;

        if ($metaCompany !== $context->companyId) {
            abort(403, 'Cross-tenant file access denied.');
        }

        if (self::ownerIdentityMismatch($context, $metaOwner)) {
            abort(403, 'Cross-tenant file access denied.');
        }

        $metaIsBranchScoped = $metaBranch !== null && $metaBranch !== '';
        $contextIsBranchScoped = $context->hasBranch();

        if ($metaIsBranchScoped) {
            if ($contextIsBranchScoped && $context->branchId === $metaBranch) {
                return;
            }

            if (self::allowsOwnerScopeBranchAccess($context, $metaOwner)) {
                return;
            }

            abort(403, 'Cross-tenant file access denied.');
        }

        // Company-scoped Shared Data: company (+ owner when present) already verified.
    }

    private static function ownerIdentityMismatch(TenantContext $context, mixed $metaOwner): bool
    {
        if ($metaOwner === null || $metaOwner === '') {
            return false;
        }

        return $context->ownerId !== $metaOwner;
    }

    private static function allowsOwnerScopeBranchAccess(TenantContext $context, mixed $metaOwner): bool
    {
        if (! $context->isOwnerScope || ! $context->hasCompany()) {
            return false;
        }

        // Fail closed: Owner-scope branch access requires an explicit owner identity.
        if ($context->ownerId === null || $context->ownerId === '') {
            return false;
        }

        // When metadata names an owner, it must match the acting Owner.
        if ($metaOwner !== null && $metaOwner !== '' && $metaOwner !== $context->ownerId) {
            return false;
        }

        return true;
    }
}
