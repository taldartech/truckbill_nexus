<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Domains\OwnFleet\Trip\VendorExpensePaymentService;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VendorExpensePaymentController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly VendorExpensePaymentService $vendorPayments,
    ) {}

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'vendor_party_id' => ['required', 'uuid'],
            'trip_expense_id' => ['nullable', 'uuid'],
            'payment_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_mode' => ['nullable', 'string', 'in:cash,bank'],
            'bank_cash_account_id' => ['required', 'uuid'],
            'reference' => ['nullable', 'string', 'max:120'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->vendorPayments->recordPayment($company, $branch, $actor, $validated);
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripDomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('flash.success', 'Vendor payment recorded.');
    }
}
