<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\AccountingWorkspaceService;
use App\Domains\Accounting\OpeningBalanceService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Accounting\Concerns\AuthorizesAccountingPanel;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingOpeningBalanceController extends Controller
{
    use AuthorizesAccountingPanel;
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly OpeningBalanceService $openingBalances,
        private readonly AccountingWorkspaceService $workspace,
    ) {}

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, requireMutation: true);
        $this->workspace->bootstrap($company);

        return Inertia::render('accounting/opening-balances/create', [
            'ledgers' => $this->workspace->activeLedgers($company)->map(fn ($ledger): array => [
                'id' => $ledger->id,
                'name' => $ledger->name,
            ])->values()->all(),
            'urls' => [
                'store' => route('branch.accounting.opening-balances.store', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, requireMutation: true);

        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'cutover_key' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.gl_ledger_id' => ['required', 'uuid'],
            'lines.*.debit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
        ]);

        $voucher = $this->openingBalances->post(
            company: $company,
            actor: $actor,
            lines: $validated['lines'],
            branch: $branch,
            asOfDate: $validated['as_of_date'] ?? null,
            cutoverKey: $validated['cutover_key'] ?? null,
        );

        return redirect()
            ->route('branch.accounting.vouchers.show', $voucher)
            ->with('success', 'Opening balances '.$voucher->voucher_number.' posted.');
    }
}
