<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\AccountingWorkspaceService;
use App\Domains\Accounting\SystemLedgerResolver;
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

class AccountingCashBankBookController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly AccountingWorkspaceService $workspace,
        private readonly SystemLedgerResolver $ledgers = new SystemLedgerResolver,
    ) {}

    public function cash(Request $request, Company $company, Branch $branch): Response
    {
        return $this->book($request, $company, $branch, 'Cash', 'Cash Book');
    }

    public function bank(Request $request, Company $company, Branch $branch): Response
    {
        return $this->book($request, $company, $branch, 'Bank', 'Bank Book');
    }

    private function book(
        Request $request,
        Company $company,
        Branch $branch,
        string $ledgerName,
        string $title,
    ): Response {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW);
        $this->workspace->bootstrap($company);

        $from = $request->string('from_date')->toString() ?: null;
        $to = $request->string('to_date')->toString() ?: null;
        $ledger = $this->ledgers->requireByName($company, $ledgerName);
        $statement = $this->workspace->ledgerStatement($company, $ledger, $from, $to, $branch);

        return Inertia::render('accounting/reports/cash-bank-book', [
            'title' => $title,
            'ledgerName' => $ledgerName,
            'filters' => [
                'from_date' => $from,
                'to_date' => $to,
            ],
            'opening_debit' => $statement['opening_debit'],
            'opening_credit' => $statement['opening_credit'],
            'closing_debit' => $statement['closing_debit'],
            'closing_credit' => $statement['closing_credit'],
            'rows' => $statement['rows'],
            'urls' => [
                'index' => $ledgerName === 'Cash'
                    ? route('branch.accounting.reports.cash-book', absolute: false)
                    : route('branch.accounting.reports.bank-book', absolute: false),
                'cash_book' => route('branch.accounting.reports.cash-book', absolute: false),
                'bank_book' => route('branch.accounting.reports.bank-book', absolute: false),
                'vouchers' => route('branch.accounting.vouchers.index', absolute: false),
                'trial_balance' => route('branch.accounting.reports.trial-balance', absolute: false),
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
