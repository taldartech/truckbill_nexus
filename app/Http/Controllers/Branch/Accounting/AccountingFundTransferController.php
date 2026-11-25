<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Domains\Accounting\FundTransferService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountingFundTransferController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly FundTransferService $transfers,
    ) {}

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);

        return Inertia::render('accounting/fund-transfers/create', [
            'urls' => [
                'store' => route('branch.accounting.fund-transfers.store', absolute: false),
                'index' => route('branch.accounting.vouchers.index', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);

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
