<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Domains\Accounting\FundTransferService;
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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountingFundTransferController extends Controller
{
    use AuthorizesAccountingPanel;
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly FundTransferService $transfers,
    ) {}

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, requireMutation: true);

        return Inertia::render('accounting/fund-transfers/create', [
            'urls' => [
                'store' => route('branch.accounting.fund-transfers.store', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, requireMutation: true);

        $validated = $request->validate([
            'from_ledger' => ['required', 'in:cash,bank'],
            'to_ledger' => ['required', 'in:cash,bank', 'different:from_ledger'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'voucher_date' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $voucher = $this->transfers->transfer($company, $actor, $validated, $branch);
        } catch (AccountingDomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.accounting.vouchers.show', $voucher)
            ->with('success', 'Fund transfer '.$voucher->voucher_number.' posted.');
    }
}
