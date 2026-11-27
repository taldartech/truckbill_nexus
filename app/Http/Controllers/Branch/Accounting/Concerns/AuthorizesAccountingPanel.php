<?php

namespace App\Http\Controllers\Branch\Accounting\Concerns;

use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;

/**
 * Shared RBAC, No-Freight View (§17), and active-Accounting mutation checks across Accounting controllers.
 */
trait AuthorizesAccountingPanel
{
    protected function authorizeAccounting(
        User $actor,
        Company $company,
        Branch $branch,
        PermissionAuthorizer $authorizer,
        string $permissionCode,
        bool $requireMutation = false,
    ): bool {
        if (! $authorizer->allowsCustomer($actor, $permissionCode, $company, $branch)) {
            abort(403, 'You are not authorized for this Accounting action.');
        }

        $maySeeFreight = app(FreightAmountPresentation::class)->viewerMaySeeFreight($actor, $company, $branch);
        if (! $maySeeFreight) {
            abort(403, 'Accounting statements and ledgers are restricted for No-Freight roles.');
        }

        if ($requireMutation && ! app(CompanyCapabilityResolver::class)->allowsMutate($company, CompanyCapabilities::ACCOUNTING)) {
            abort(403, 'Active Accounting add-on entitlement is required for accounting mutations.');
        }

        return $maySeeFreight;
    }

    /**
     * UI-facing abilities; every target route re-checks them server-side.
     *
     * @return array{can_view: bool, can_create: bool, can_report: bool, can_mutate: bool}
     */
    protected function accountingAbilities(
        User $actor,
        Company $company,
        Branch $branch,
        PermissionAuthorizer $authorizer,
    ): array {
        $isOwner = (int) $actor->id === (int) $company->owner_user_id;
        $canMutate = app(CompanyCapabilityResolver::class)->allowsMutate($company, CompanyCapabilities::ACCOUNTING);
        $allows = fn (string $permissionCode): bool => $isOwner
            || $authorizer->allowsCustomer($actor, $permissionCode, $company, $branch);

        return [
            'can_view' => $allows(PermissionCodes::BRANCH_ACCOUNTING_VIEW),
            'can_create' => $canMutate && $allows(PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE),
            'can_report' => $allows(PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW),
            'can_mutate' => $canMutate,
        ];
    }
}
