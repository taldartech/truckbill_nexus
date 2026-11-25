<?php

namespace App\Domains\Tax;

use App\Domains\Company\Enums\BranchGstMode;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\State;

/**
 * Central resolver for Branch-effective GST (inherit company vs independent).
 */
final class EffectiveGstConfigurationResolver
{
    public function resolve(Company $company, Branch $branch): EffectiveGstConfiguration
    {
        if ($branch->company_id !== $company->id) {
            throw new CompanyDomainException('Branch does not belong to the selected company.');
        }

        $mode = $branch->gst_mode instanceof BranchGstMode
            ? $branch->gst_mode
            : BranchGstMode::tryFrom((string) ($branch->gst_mode ?? '')) ?? BranchGstMode::InheritCompany;

        if ($mode === BranchGstMode::Independent) {
            [$stateName, $gstCode] = $this->resolveStateMeta(
                $branch->state_id ?? null,
                $branch->state ?? null,
            );

            return new EffectiveGstConfiguration(
                companyId: $company->id,
                branchId: $branch->id,
                mode: $mode,
                inheritsCompany: false,
                registrationType: $branch->gst_registration_type,
                taxLiability: $branch->tax_liability,
                gstin: $branch->gstin,
                gstRateId: $branch->gst_rate_id,
                gstRateSnapshot: is_array($branch->gst_rate_snapshot) ? $branch->gst_rate_snapshot : null,
                sourceCompanyId: null,
                supplierState: $stateName,
                supplierStateGstCode: $gstCode,
            );
        }

        [$stateName, $gstCode] = $this->resolveStateMeta(
            $company->state_id ?? null,
            $company->state ?? null,
        );

        return new EffectiveGstConfiguration(
            companyId: $company->id,
            branchId: $branch->id,
            mode: BranchGstMode::InheritCompany,
            inheritsCompany: true,
            registrationType: $company->gst_registration_type,
            taxLiability: $company->tax_liability,
            gstin: $company->gstin,
            gstRateId: $company->gst_rate_id,
            gstRateSnapshot: is_array($company->gst_rate_snapshot) ? $company->gst_rate_snapshot : null,
            sourceCompanyId: $company->id,
            supplierState: $stateName,
            supplierStateGstCode: $gstCode,
        );
    }

    /**
     * @return array{registration_type: ?GstRegistrationType, tax_liability: ?TaxLiability, gstin: ?string, gst_rate_id: ?string, gst_rate_snapshot: ?array<string, mixed>}
     */
    public function companyGstPayload(Company $company): array
    {
        return [
            'registration_type' => $company->gst_registration_type,
            'tax_liability' => $company->tax_liability,
            'gstin' => $company->gstin,
            'gst_rate_id' => $company->gst_rate_id,
            'gst_rate_snapshot' => is_array($company->gst_rate_snapshot) ? $company->gst_rate_snapshot : null,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveStateMeta(?string $stateId, ?string $fallbackName): array
    {
        if (filled($stateId)) {
            $state = State::query()->whereKey($stateId)->first();
            if ($state !== null) {
                return [$state->name, $state->gst_code];
            }
        }

        $name = filled($fallbackName) ? trim((string) $fallbackName) : null;

        return [$name !== '' ? $name : null, null];
    }
}
