<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\OwnFleet\Driver\DriverAdvanceService;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DriverAdvanceController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly DriverAdvanceService $advances,
    ) {}

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'driver_party_id' => ['required', 'uuid'],
            'trip_id' => ['nullable', 'uuid'],
            'entry_date' => ['nullable', 'date'],
            'entry_type' => ['required', 'string', 'in:advance,settlement,recovery'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_mode' => ['nullable', 'string', 'in:cash,bank,adjustment'],
            'bank_cash_account_id' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:120'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->advances->record($company, $branch, $actor, $validated);
        } catch (TripAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (TripDomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('flash.success', 'Driver advance entry recorded.');
    }
}
