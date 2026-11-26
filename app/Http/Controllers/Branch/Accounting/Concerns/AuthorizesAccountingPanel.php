<?php

namespace App\Http\Controllers\Branch\Accounting\Concerns;

use App\Domains\Rbac\PermissionAuthorizer;
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
        $isOwner = (int) $actor->id === (int) $company->owner_user_id;

        if (! $isOwner && ! $authorizer->allowsCustomer($actor, $permissionCode, $company, $branch)) {
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
}
