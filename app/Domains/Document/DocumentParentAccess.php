<?php

namespace App\Domains\Document;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Document\Exceptions\DocumentDomainException;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;

/**
 * Parent-type authorization for document attachments.
 *
 * Master parents continue to use PLATFORM_* via MasterAccess.
 * Operational parents (LR / Trip / Broker Delivery) require their own
 * permission codes — Party Master edit must not imply Trip/POD mutate.
 */
final class DocumentParentAccess
{
    public function __construct(
        private readonly MasterAccess $masters = new MasterAccess,
        private readonly CompanyAccessService $companyAccess = new CompanyAccessService,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
    ) {}

    public function assertCanView(User $actor, Company $company, Branch $branch, string $parentType): void
    {
        if (DocumentParentTypes::isMasterParent($parentType)) {
            $this->masters->assertCanView($actor, $company, $branch);

            return;
        }

        $this->assertOperationalMembership($actor, $company, $branch);
        $code = $this->viewCode($parentType);
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            throw new DocumentDomainException('Not authorized to view documents for this parent.');
        }
    }

    public function assertCanMutate(User $actor, Company $company, ?Branch $branch, string $parentType): void
    {
        if (DocumentParentTypes::isMasterParent($parentType)) {
            $this->masters->assertCanMutate($actor, $company, $branch);

            return;
        }

        if ($branch === null) {
            throw new DocumentDomainException('Branch context is required for operational documents.');
        }

        $this->assertOperationalMembership($actor, $company, $branch);
        $code = $this->mutateCode($parentType);
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            throw new DocumentDomainException('Not authorized to modify documents for this parent.');
        }
    }

    public function assertCanEdit(User $actor, Company $company, ?Branch $branch, string $parentType): void
    {
        if (DocumentParentTypes::isMasterParent($parentType)) {
            $this->masters->assertCanEdit($actor, $company, $branch);

            return;
        }

        $this->assertCanMutate($actor, $company, $branch, $parentType);
    }

    public function assertCanDelete(User $actor, Company $company, ?Branch $branch, string $parentType): void
    {
        if (DocumentParentTypes::isMasterParent($parentType)) {
            $this->masters->assertCanDelete($actor, $company, $branch);

            return;
        }

        $this->assertCanMutate($actor, $company, $branch, $parentType);
    }

    /**
     * @return array{can_view: bool, can_create: bool, can_edit: bool, can_delete: bool}
     */
    public function permissionFlags(User $actor, Company $company, Branch $branch, string $parentType): array
    {
        try {
            $this->assertCanView($actor, $company, $branch, $parentType);
            $canView = true;
        } catch (DocumentDomainException|MasterDomainException) {
            $canView = false;
        }

        try {
            $this->assertCanMutate($actor, $company, $branch, $parentType);
            $canMutate = true;
        } catch (DocumentDomainException|MasterDomainException) {
            $canMutate = false;
        }

        try {
            $this->assertCanDelete($actor, $company, $branch, $parentType);
            $canDelete = true;
        } catch (DocumentDomainException|MasterDomainException) {
            $canDelete = false;
        }

        return [
            'can_view' => $canView,
            'can_create' => $canMutate,
            'can_edit' => $canMutate,
            'can_delete' => $canDelete,
        ];
    }

    private function assertOperationalMembership(User $actor, Company $company, Branch $branch): void
    {
        if (! $actor->isActive() || ! $company->isActive()) {
            throw new DocumentDomainException('Not authorized for document access.');
        }

        if ($this->companyAccess->activeMembership($actor, $company) === null) {
            throw new DocumentDomainException('Not authorized for document access.');
        }

        if (! $this->companyAccess->canAccessBranch($actor, $company, $branch)) {
            throw new DocumentDomainException('Not authorized for this branch.');
        }
    }

    private function viewCode(string $parentType): string
    {
        return match ($parentType) {
            DocumentParentTypes::LORRY_RECEIPT => PermissionCodes::LR_VIEW,
            DocumentParentTypes::TRIP => PermissionCodes::TRIP_VIEW,
            DocumentParentTypes::BROKER_DELIVERY => PermissionCodes::BROKER_DELIVERY_VIEW,
            default => throw new DocumentDomainException('Unsupported document parent type.'),
        };
    }

    private function mutateCode(string $parentType): string
    {
        return match ($parentType) {
            DocumentParentTypes::LORRY_RECEIPT => PermissionCodes::LR_EDIT,
            DocumentParentTypes::TRIP => PermissionCodes::TRIP_EDIT,
            DocumentParentTypes::BROKER_DELIVERY => PermissionCodes::BROKER_DELIVERY_MANAGE,
            default => throw new DocumentDomainException('Unsupported document parent type.'),
        };
    }
}
