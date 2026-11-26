<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountNature;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Models\GlLedger;
use App\Support\Audit\AuditedTransaction;
use Illuminate\Support\Str;

/**
 * Seeds a minimal transport-oriented Chart of Accounts for a company.
 */
final class ChartOfAccountsBootstrapService
{
    public function __construct(
        private readonly AuditedTransaction $audited = new AuditedTransaction,
    ) {}

    /**
     * @return array{groups: int, ledgers: int}
     */
    public function ensureDefaults(Company $company): array
    {
        return $this->audited->run(function () use ($company): array {
            $groupsCreated = 0;
            $ledgersCreated = 0;

            foreach ($this->defaultTree() as $groupDef) {
                $group = AccountGroup::query()
                    ->where('company_id', $company->id)
                    ->where('name', $groupDef['name'])
                    ->whereNull('deleted_at')
                    ->first();

                if ($group === null) {
                    $group = AccountGroup::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'parent_id' => null,
                        'name' => $groupDef['name'],
                        'nature' => $groupDef['nature'],
                        'sort_order' => $groupDef['sort_order'],
                        'is_system' => true,
                    ]);
                    $groupsCreated++;
                }

                foreach ($groupDef['ledgers'] as $ledgerName) {
                    $exists = GlLedger::query()
                        ->where('company_id', $company->id)
                        ->where('name', $ledgerName)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    GlLedger::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'account_group_id' => $group->id,
                        'name' => $ledgerName,
                        'nature' => $groupDef['nature'],
                        'is_system' => true,
                        'is_active' => true,
                    ]);
                    $ledgersCreated++;
                }
            }

            return [
                'groups' => $groupsCreated,
                'ledgers' => $ledgersCreated,
            ];
        });
    }

    /**
     * @return list<array{name: string, nature: AccountNature, sort_order: int, ledgers: list<string>}>
     */
    private function defaultTree(): array
    {
        return [
            [
                'name' => 'Cash-in-Hand',
                'nature' => AccountNature::Asset,
                'sort_order' => 10,
                'ledgers' => ['Cash'],
            ],
            [
                'name' => 'Bank Accounts',
                'nature' => AccountNature::Asset,
                'sort_order' => 20,
                'ledgers' => ['Bank'],
            ],
            [
                'name' => 'Sundry Debtors',
                'nature' => AccountNature::Asset,
                'sort_order' => 30,
                'ledgers' => ['Customer Receivables'],
            ],
            [
                'name' => 'Loans and Advances (Asset)',
                'nature' => AccountNature::Asset,
                'sort_order' => 40,
                'ledgers' => ['Transporter Advances', 'Driver Advances', 'Vendor Advances'],
            ],
            [
                'name' => 'Duties & Taxes',
                'nature' => AccountNature::Liability,
                'sort_order' => 50,
                'ledgers' => ['Output CGST', 'Output SGST', 'Output IGST', 'Input CGST', 'Input SGST', 'Input IGST', 'TDS Payable', 'TDS Receivable'],
            ],
            [
                'name' => 'Sundry Creditors',
                'nature' => AccountNature::Liability,
                'sort_order' => 60,
                'ledgers' => ['Transporter Payables', 'Vendor Payables', 'Driver Payables'],
            ],
            [
                'name' => 'Current Liabilities',
                'nature' => AccountNature::Liability,
                'sort_order' => 70,
                'ledgers' => ['Customer Advances'],
            ],
            [
                'name' => 'Direct Incomes',
                'nature' => AccountNature::Income,
                'sort_order' => 80,
                'ledgers' => ['Freight Income'],
            ],
            [
                'name' => 'Direct Expenses',
                'nature' => AccountNature::Expense,
                'sort_order' => 90,
                'ledgers' => [
                    'Transporter Freight Expense',
                    'Diesel Expenses',
                    'Driver Expenses',
                    'Toll and FASTag Expenses',
                    'Loading and Unloading Expenses',
                    'Vehicle Expenses',
                ],
            ],
            [
                'name' => 'Indirect Expenses',
                'nature' => AccountNature::Expense,
                'sort_order' => 95,
                'ledgers' => ['Software Subscription Expense'],
            ],
            [
                'name' => 'Capital Account',
                'nature' => AccountNature::Equity,
                'sort_order' => 100,
                'ledgers' => ['Capital', 'Retained Earnings'],
            ],
        ];
    }
}
