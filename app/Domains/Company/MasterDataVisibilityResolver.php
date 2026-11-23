<?php

namespace App\Domains\Company;

use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\Vehicle;

/**
 * Company data_visibility is the sole authority for Party / Vehicle / Bank-Cash scope
 * (PO-MASTER-COMPANY-VISIBILITY).
 */
final class MasterDataVisibilityResolver
{
    public function visibility(Company $company): DataVisibility
    {
        return $company->data_visibility ?? DataVisibility::SharedData;
    }

    public function isShared(Company $company): bool
    {
        return $this->visibility($company) === DataVisibility::SharedData;
    }

    /**
     * Branch argument for create: null = company-wide shared; Branch = restricted to that branch.
     */
    public function scopeBranchForCreate(Company $company, Branch $currentBranch): ?Branch
    {
        return $this->isShared($company) ? null : $currentBranch;
    }

    /**
     * @return array{mode: string, label: string, message: string}
     */
    public function presentation(Company $company): array
    {
        if ($this->isShared($company)) {
            return [
                'mode' => 'shared',
                'label' => 'Shared Data',
                'message' => 'Data visibility: Shared across company branches.',
            ];
        }

        return [
            'mode' => 'branch',
            'label' => 'Branch-wise Restricted',
            'message' => 'Data visibility: Restricted to this branch.',
        ];
    }

    /**
     * Block company visibility transitions when operational masters already exist
     * until an explicit migration policy is approved.
     */
    public function assertVisibilityChangeAllowed(Company $company, DataVisibility $next): void
    {
        $current = $this->visibility($company);
        if ($current === $next) {
            return;
        }

        if ($this->hasOperationalMasterRecords($company)) {
            throw new CompanyDomainException(
                'Company Data Visibility cannot change while Party, Vehicle, or Bank/Cash records exist. A Product Owner migration policy is required before remapping historical master scope.'
            );
        }
    }

    public function hasOperationalMasterRecords(Company $company): bool
    {
        return Party::query()->where('company_id', $company->id)->exists()
            || Vehicle::query()->where('company_id', $company->id)->exists()
            || BankCashAccount::query()->where('company_id', $company->id)->exists();
    }
}
