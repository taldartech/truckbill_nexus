<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\AccountingWorkspaceService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingReportController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly AccountingWorkspaceService $workspace,
    ) {}

    public function trialBalance(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW);
        $this->workspace->bootstrap($company);

        $from = $request->string('from_date')->toString() ?: null;
        $to = $request->string('to_date')->toString() ?: null;
        $rows = $this->workspace->trialBalance($company, $from, $to, $branch);

        $totalClosingDebit = '0.00';
        $totalClosingCredit = '0.00';
        foreach ($rows as $row) {
            $totalClosingDebit = bcadd($totalClosingDebit, $row['closing_debit'], 2);
            $totalClosingCredit = bcadd($totalClosingCredit, $row['closing_credit'], 2);
        }

        return Inertia::render('accounting/reports/trial-balance', [
            'filters' => [
                'from_date' => $from,
                'to_date' => $to,
            ],
            'rows' => $rows,
            'totals' => [
                'closing_debit' => $totalClosingDebit,
                'closing_credit' => $totalClosingCredit,
                'balanced' => bccomp($totalClosingDebit, $totalClosingCredit, 2) === 0,
            ],
            'urls' => [
                'index' => route('branch.accounting.reports.trial-balance', absolute: false),
                'vouchers' => route('branch.accounting.vouchers.index', absolute: false),
                'profit_and_loss' => route('branch.accounting.reports.profit-and-loss', absolute: false),
                'balance_sheet' => route('branch.accounting.reports.balance-sheet', absolute: false),
            ],
        ]);
    }

    public function profitAndLoss(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW);
        $this->workspace->bootstrap($company);

        $from = $request->string('from_date')->toString() ?: null;
        $to = $request->string('to_date')->toString() ?: null;
        $report = $this->workspace->profitAndLoss($company, $from, $to, $branch);

        return Inertia::render('accounting/reports/profit-and-loss', [
            'filters' => [
                'from_date' => $report['from_date'],
                'to_date' => $report['to_date'],
            ],
            'report' => $report,
            'urls' => [
                'index' => route('branch.accounting.reports.profit-and-loss', absolute: false),
                'trial_balance' => route('branch.accounting.reports.trial-balance', absolute: false),
                'balance_sheet' => route('branch.accounting.reports.balance-sheet', absolute: false),
                'vouchers' => route('branch.accounting.vouchers.index', absolute: false),
            ],
        ]);
    }

    public function balanceSheet(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW);
        $this->workspace->bootstrap($company);

        $asOf = $request->string('as_of')->toString() ?: null;
        $report = $this->workspace->balanceSheet($company, $asOf, $branch);

        return Inertia::render('accounting/reports/balance-sheet', [
            'filters' => [
                'as_of' => $report['as_of'],
            ],
            'report' => $report,
            'urls' => [
                'index' => route('branch.accounting.reports.balance-sheet', absolute: false),
                'trial_balance' => route('branch.accounting.reports.trial-balance', absolute: false),
                'profit_and_loss' => route('branch.accounting.reports.profit-and-loss', absolute: false),
                'vouchers' => route('branch.accounting.vouchers.index', absolute: false),
            ],
        ]);
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403);
        }
    }
}
