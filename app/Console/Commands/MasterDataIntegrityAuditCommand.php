<?php

namespace App\Console\Commands;

use App\Models\BankCashAccount;
use App\Models\Company;
use App\Models\Party;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Read-only master relationship integrity audit. Does not mutate records.
 */
final class MasterDataIntegrityAuditCommand extends Command
{
    protected $signature = 'masters:integrity-audit
        {--company= : Limit to a company UUID}
        {--json= : Optional path to write JSON report}';

    protected $description = 'Report cross-company / cross-branch master relationship inconsistencies (read-only)';

    public function handle(): int
    {
        $companyFilter = $this->option('company');
        $companies = Company::query()
            ->when(is_string($companyFilter) && $companyFilter !== '', fn ($q) => $q->whereKey($companyFilter))
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $findings = [];

        foreach ($companies as $company) {
            $vehicles = Vehicle::query()
                ->where('company_id', $company->id)
                ->whereNull('deleted_at')
                ->get(['id', 'registration_number', 'branch_id', 'owner_party_id', 'primary_driver_party_id', 'company_id']);

            foreach ($vehicles as $vehicle) {
                foreach (['owner_party_id' => 'Vehicle Owner', 'primary_driver_party_id' => 'Primary Driver'] as $field => $label) {
                    $partyId = $vehicle->{$field};
                    if (! is_string($partyId) || $partyId === '') {
                        continue;
                    }

                    $party = Party::query()->whereKey($partyId)->first();
                    if ($party === null) {
                        $findings[] = $this->finding($company, 'missing_related_party', $label, $vehicle, $partyId, null);

                        continue;
                    }

                    if ($party->company_id !== $company->id) {
                        $findings[] = $this->finding($company, 'cross_company_party', $label, $vehicle, $partyId, $party->branch_id);

                        continue;
                    }

                    if ($vehicle->branch_id === null && $party->branch_id !== null) {
                        $findings[] = $this->finding($company, 'shared_vehicle_restricted_party', $label, $vehicle, $partyId, $party->branch_id);

                        continue;
                    }

                    if (
                        $vehicle->branch_id !== null
                        && $party->branch_id !== null
                        && $party->branch_id !== $vehicle->branch_id
                    ) {
                        $findings[] = $this->finding($company, 'cross_branch_restricted_party', $label, $vehicle, $partyId, $party->branch_id);
                    }
                }
            }

            $parties = Party::query()
                ->where('company_id', $company->id)
                ->whereNull('deleted_at')
                ->count();
            $bankAccounts = BankCashAccount::query()
                ->where('company_id', $company->id)
                ->whereNull('deleted_at')
                ->count();

            $this->line(sprintf(
                'Company %s — vehicles=%d parties=%d bank_cash=%d',
                $company->name,
                $vehicles->count(),
                $parties,
                $bankAccounts,
            ));
        }

        $this->newLine();
        $this->info('Findings: '.count($findings));
        foreach ($findings as $finding) {
            $this->line(sprintf(
                '[%s] company=%s vehicle=%s (%s) party=%s type=%s',
                $finding['code'],
                $finding['company_name'],
                $finding['vehicle_registration'],
                $finding['relationship'],
                $finding['party_id'],
                $finding['code'],
            ));
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'companies_inspected' => $companies->count(),
            'finding_count' => count($findings),
            'findings' => $findings,
        ];

        $jsonPath = $this->option('json');
        if (is_string($jsonPath) && $jsonPath !== '') {
            File::ensureDirectoryExists(dirname($jsonPath));
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $this->error('Failed to encode integrity audit JSON.');

                return self::FAILURE;
            }
            File::put($jsonPath, $encoded);
            $this->info('Wrote '.$jsonPath);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(
        Company $company,
        string $code,
        string $relationship,
        Vehicle $vehicle,
        string $partyId,
        ?string $partyBranchId,
    ): array {
        return [
            'code' => $code,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'vehicle_id' => $vehicle->id,
            'vehicle_registration' => $vehicle->registration_number,
            'vehicle_branch_id' => $vehicle->branch_id,
            'relationship' => $relationship,
            'party_id' => $partyId,
            'party_branch_id' => $partyBranchId,
        ];
    }
}
