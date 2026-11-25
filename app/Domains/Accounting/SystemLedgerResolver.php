<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Models\Company;
use App\Models\GlLedger;

/**
 * Resolves system COA ledgers by stable name for operational posting adapters.
 */
final class SystemLedgerResolver
{
    public function __construct(
        private readonly ChartOfAccountsBootstrapService $coa = new ChartOfAccountsBootstrapService,
    ) {}

    public function requireByName(Company $company, string $name): GlLedger
    {
        $this->coa->ensureDefaults($company);

        $ledger = GlLedger::query()
            ->where('company_id', $company->id)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->first();

        if ($ledger === null) {
            throw new AccountingDomainException("Required accounting ledger [{$name}] is missing.");
        }

        return $ledger;
    }
}
