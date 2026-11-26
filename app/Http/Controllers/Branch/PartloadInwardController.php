<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\Inward\PartloadInwardService;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartloadInwardRegister;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PartloadInwardController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PartloadInwardService $inwards,
    ) {}

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $validated = $request->validate([
            'partload_challan_id' => ['required', 'uuid'],
            'inward_date' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'array'],
            'lines.*.lorry_receipt_id' => ['required_with:lines', 'uuid'],
            'lines.*.disposition' => ['nullable', 'in:delivery,forward'],
            'lines.*.packages' => ['nullable', 'numeric', 'min:0'],
            'lines.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'lines.*.remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $inward = $this->inwards->createFromChallan($company, $branch, $actor, $validated);
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException $e) {
            throw ValidationException::withMessages(['partload_challan_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.partload.inwards.show', $inward)
            ->with('success', 'Inward Register '.$inward->inward_number.' created.');
    }

    public function show(Request $request, Company $company, Branch $branch, PartloadInwardRegister $partloadInward): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        if ((int) $actor->id !== (int) $company->owner_user_id) {
            // view permission checked via service find after soft gate
        }

        $inward = $this->inwards->findForBranch($company, $branch, $partloadInward->id);

        return Inertia::render('partload/inwards/show', [
            'inward' => [
                'id' => $inward->id,
                'inward_number' => $inward->inward_number,
                'inward_date' => $inward->inward_date?->toDateString(),
                'challan_number' => $inward->challan?->challan_number,
                'from_station_name' => $inward->fromStation?->name,
                'to_station_name' => $inward->toStation?->name,
                'lines' => $inward->lines->map(fn ($line): array => [
                    'id' => $line->id,
                    'lorry_receipt_id' => $line->lorry_receipt_id,
                    'lr_number' => $line->lorryReceipt?->lr_number,
                    'packages' => (string) $line->packages,
                    'weight_kg' => (string) $line->weight_kg,
                    'disposition' => $line->disposition->value,
                    'disposition_label' => $line->disposition->label(),
                    'create_dr_url' => $line->disposition->value === 'delivery'
                        ? route('branch.partload.delivery-receipts.create', [
                            'lorry_receipt_id' => $line->lorry_receipt_id,
                            'partload_inward_line_id' => $line->id,
                        ], absolute: false)
                        : null,
                ])->values()->all(),
            ],
            'urls' => [
                'challan' => $inward->challan
                    ? route('branch.partload.challans.show', $inward->challan, absolute: false)
                    : null,
            ],
        ]);
    }
}
