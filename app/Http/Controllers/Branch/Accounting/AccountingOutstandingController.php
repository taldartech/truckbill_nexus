<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\AccountingWorkspaceService;
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

class AccountingOutstandingController extends Controller
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
        return $this->renderSnapshot(
            $request,
            $company,
            $branch,
            'Outstanding Control Accounts',
            'outstanding',
            fn (): array => $this->workspace->outstandingControlAccounts(
                $company,
                $request->string('as_of')->toString() ?: null,
                $branch,
            ),
        );
    }

    public function tax(Request $request, Company $company, Branch $branch): Response
    {
        return $this->renderSnapshot(
            $request,
            $company,
            $branch,
            'GST / TDS Accounting Summary',
            'tax',
            fn (): array => $this->workspace->taxControlAccounts(
                $company,
                $request->string('as_of')->toString() ?: null,
                $branch,
            ),
        );
    }

    /**
     * @param  callable(): list<array{ledger_id: string, ledger_name: string, debit: string, credit: string, net: string, side: string}>  $rows
     */
    private function renderSnapshot(
        Request $request,
        Company $company,
        Branch $branch,
        string $title,
        string $kind,
        callable $rows,
    ): Response {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW);
        $this->workspace->bootstrap($company);

        $asOf = $request->string('as_of')->toString() ?: null;

        return Inertia::render('accounting/reports/outstanding', [
            'title' => $title,
            'kind' => $kind,
            'filters' => [
                'as_of' => $asOf,
            ],
            'rows' => $rows(),
            'urls' => [
                'index' => $kind === 'tax'
                    ? route('branch.accounting.reports.tax-summary', absolute: false)
                    : route('branch.accounting.reports.outstanding', absolute: false),
                'outstanding' => route('branch.accounting.reports.outstanding', absolute: false),
                'tax_summary' => route('branch.accounting.reports.tax-summary', absolute: false),
                'trial_balance' => route('branch.accounting.reports.trial-balance', absolute: false),
                'vouchers' => route('branch.accounting.vouchers.index', absolute: false),
            ],
        ]);
    }
}
