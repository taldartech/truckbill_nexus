<?php

namespace App\Domains\Tax;

use App\Domains\Company\Enums\BranchGstMode;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;

/**
 * Authoritative effective GST configuration for a Company + Branch pair.
 *
 * @phpstan-type GstRateSnapshot array{id?: string, code?: string, name?: string, rate_percent?: string}|null
 */
final readonly class EffectiveGstConfiguration
{
    /**
     * @param  array<string, mixed>|null  $gstRateSnapshot
     */
    public function __construct(
        public string $companyId,
        public string $branchId,
        public BranchGstMode $mode,
        public bool $inheritsCompany,
        public ?GstRegistrationType $registrationType,
        public ?TaxLiability $taxLiability,
        public ?string $gstin,
        public ?string $gstRateId,
        public ?array $gstRateSnapshot,
        public ?string $sourceCompanyId,
        public ?string $supplierState = null,
        public ?string $supplierStateGstCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'mode' => $this->mode->value,
            'inherits_company' => $this->inheritsCompany,
            'gst_registration_type' => $this->registrationType?->value,
            'tax_liability' => $this->taxLiability?->value,
            'gstin' => $this->gstin,
            'gst_rate_id' => $this->gstRateId,
            'gst_rate_snapshot' => $this->gstRateSnapshot,
            'source_company_id' => $this->sourceCompanyId,
            'supplier_state' => $this->supplierState,
            'supplier_state_gst_code' => $this->supplierStateGstCode,
        ];
    }
}
