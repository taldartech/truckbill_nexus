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
use App\Models\GlLedger;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingLedgerController extends Controller
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
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_VIEW);
        $this->workspace->bootstrap($company);

        return Inertia::render('accounting/ledgers/index', [
            'ledgers' => $this->workspace->activeLedgers($company)->map(fn (GlLedger $ledger): array => [
                'id' => $ledger->id,
                'name' => $ledger->name,
                'group_name' => $ledger->accountGroup?->name,
                'urls' => [
                    'show' => route('branch.accounting.ledgers.show', $ledger, absolute: false),
                ],
            ])->values()->all(),
            'urls' => [
                'vouchers' => route('branch.accounting.vouchers.index', absolute: false),
            ],
        ]);
    }

    public function show(Request $request, Company $company, Branch $branch, GlLedger $glLedger): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW);

        if ($glLedger->company_id !== $company->id) {
            abort(404);
        }

        $from = $request->string('from_date')->toString() ?: null;
        $to = $request->string('to_date')->toString() ?: null;
        $statement = $this->workspace->ledgerStatement($company, $glLedger, $from, $to, $branch);

        return Inertia::render('accounting/ledgers/show', [
            'filters' => [
                'from_date' => $from,
                'to_date' => $to,
            ],
            'ledger' => [
                'id' => $glLedger->id,
                'name' => $glLedger->name,
            ],
            'opening_debit' => $statement['opening_debit'],
            'opening_credit' => $statement['opening_credit'],
            'closing_debit' => $statement['closing_debit'],
            'closing_credit' => $statement['closing_credit'],
            'rows' => $statement['rows'],
            'urls' => [
                'index' => route('branch.accounting.ledgers.index', absolute: false),
                'show' => route('branch.accounting.ledgers.show', $glLedger, absolute: false),
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
