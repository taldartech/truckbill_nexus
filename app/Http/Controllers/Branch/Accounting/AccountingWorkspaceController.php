<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\AccountingWorkspaceService;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Accounting\Concerns\AuthorizesAccountingPanel;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounting gateway — groups the existing Accounting screens the viewer may open.
 */
class AccountingWorkspaceController extends Controller
{
    use AuthorizesAccountingPanel;
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly AccountingWorkspaceService $workspace,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VIEW);
        $bootstrap = $this->workspace->bootstrap($company);
        $abilities = $this->accountingAbilities($actor, $company, $branch, $this->authorizer);

        return Inertia::render('accounting/workspace', [
            'periodLabel' => $bootstrap['period_label'],
            'permissions' => [
                'can_create' => $abilities['can_create'],
            ],
            'sections' => $this->sections($abilities),
            'urls' => [
                'day_book' => route('branch.accounting.vouchers.index', absolute: false),
                'create_voucher' => $abilities['can_create']
                    ? route('branch.accounting.vouchers.create', absolute: false)
                    : null,
            ],
        ]);
    }

    /**
     * @param  array{can_view: bool, can_create: bool, can_report: bool, can_mutate: bool}  $abilities
     * @return list<array{key: string, title: string, links: list<array{key: string, label: string, description: string, href: string}>}>
     */
    private function sections(array $abilities): array
    {
        $canCreate = $abilities['can_create'];
        $canReport = $abilities['can_report'];

        $voucherEntryLinks = $canCreate
            ? collect(AccountingVoucherType::cases())
                ->reject(fn (AccountingVoucherType $type): bool => $type === AccountingVoucherType::Opening)
                ->map(fn (AccountingVoucherType $type): array => $this->link(
                    'voucher_'.$type->value,
                    $type->label(),
                    'Record a new '.strtolower($type->label()).' voucher.',
                    route('branch.accounting.vouchers.create', ['voucher_type' => $type->value], absolute: false),
                ))
                ->values()
                ->all()
            : [];

        $sections = [
            $this->section('transactions', 'Transactions', [
                $this->link('day_book', 'Day Book', 'All posted vouchers by date, type and financial year.', route('branch.accounting.vouchers.index', absolute: false)),
                ...$voucherEntryLinks,
                $canCreate ? $this->link('fund_transfer', 'Fund Transfer', 'Move money between cash and bank.', route('branch.accounting.fund-transfers.create', absolute: false)) : null,
            ]),
            $this->section('masters', 'Masters', [
                $this->link('ledgers', 'Ledgers', 'Chart of accounts ledgers and their groups.', route('branch.accounting.ledgers.index', absolute: false)),
                $canCreate ? $this->link('opening_balances', 'Opening Balances', 'Post opening ledger balances.', route('branch.accounting.opening-balances.create', absolute: false)) : null,
            ]),
            $this->section('reports', 'Financial Reports', $canReport ? [
                $this->link('trial_balance', 'Trial Balance', 'Closing debit and credit balances per ledger.', route('branch.accounting.reports.trial-balance', absolute: false)),
                $this->link('profit_and_loss', 'Profit & Loss', 'Income and expenses for a period.', route('branch.accounting.reports.profit-and-loss', absolute: false)),
                $this->link('balance_sheet', 'Balance Sheet', 'Assets, liabilities and capital as of a date.', route('branch.accounting.reports.balance-sheet', absolute: false)),
                $this->link('cash_book', 'Cash Book', 'Cash ledger movements and running balance.', route('branch.accounting.reports.cash-book', absolute: false)),
                $this->link('bank_book', 'Bank Book', 'Bank ledger movements and running balance.', route('branch.accounting.reports.bank-book', absolute: false)),
                $this->link('outstanding', 'Outstanding', 'Receivable and payable control accounts.', route('branch.accounting.reports.outstanding', absolute: false)),
                $this->link('ledger_statements', 'Ledger Statements', 'Pick a ledger to view its statement.', route('branch.accounting.ledgers.index', absolute: false)),
            ] : []),
            $this->section('compliance', 'Compliance and Controls', [
                $canReport ? $this->link('tax_summary', 'GST / TDS Summary', 'GST and TDS control account balances.', route('branch.accounting.reports.tax-summary', absolute: false)) : null,
                $canReport ? $this->link('reconciliation', 'Reconciliation', 'Compare operational documents with posted vouchers.', route('branch.accounting.reports.reconciliation', absolute: false)) : null,
                $this->link('activation', 'Activation / Cutover', 'Accounting cutover status and catch-up posting.', route('branch.accounting.activation.show', absolute: false)),
            ]),
        ];

        return array_values(array_filter(
            $sections,
            fn (array $section): bool => $section['links'] !== [],
        ));
    }

    /**
     * @param  list<array{key: string, label: string, description: string, href: string}|null>  $links
     * @return array{key: string, title: string, links: list<array{key: string, label: string, description: string, href: string}>}
     */
    private function section(string $key, string $title, array $links): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'links' => array_values(array_filter($links)),
        ];
    }

    /**
     * @return array{key: string, label: string, description: string, href: string}
     */
    private function link(string $key, string $label, string $description, string $href): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'href' => $href,
        ];
    }
}
