<?php

namespace App\Console\Commands;

use App\Domains\Tax\TransporterDeclarationAnnualResetService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tax:reset-transporter-declarations {--year= : Financial year start year (e.g. 2026 for FY 2026-27)}')]
#[Description('Idempotent April-1 reset of transporter declarations for the India financial year')]
class TaxResetTransporterDeclarationsCommand extends Command
{
    public function handle(TransporterDeclarationAnnualResetService $reset): int
    {
        $yearOption = $this->option('year');
        $result = is_numeric($yearOption)
            ? $reset->resetForFinancialYear((int) $yearOption)
            : $reset->resetForCurrentFinancialYear();

        if ($result['reset']) {
            $this->info(sprintf(
                'FY %d declaration reset complete. Transporters updated: %d.',
                $result['financial_year'],
                $result['transporters_updated'],
            ));
        } else {
            $this->info(sprintf(
                'FY %d already reset (marker id %s). No changes.',
                $result['financial_year'],
                $result['marker_id'] ?? 'n/a',
            ));
        }

        return self::SUCCESS;
    }
}
