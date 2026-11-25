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

class AccountingOutstandingController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly AccountingWorkspaceService $workspace,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW);
        $this->workspace->bootstrap($company);

        $asOf = $request->string('as_of')->toString() ?: null;
        $rows = $this->workspace->outstandingControlAccounts($company, $asOf, $branch);

        return Inertia::render('accounting/reports/outstanding', [
            'filters' => [
                'as_of' => $asOf,
            ],
            'rows' => $rows,
            'urls' => [
                'index' => route('branch.accounting.reports.outstanding', absolute: false),
                'trial_balance' => route('branch.accounting.reports.trial-balance', absolute: false),
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
