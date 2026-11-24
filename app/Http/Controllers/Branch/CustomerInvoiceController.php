<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\CustomerInvoice\Enums\CustomerInvoiceSource;
use App\Domains\CustomerInvoice\UnifiedCustomerInvoiceReadFacade;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerInvoiceController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly UnifiedCustomerInvoiceReadFacade $invoices,
        private readonly FreightAmountPresentation $freightPresentation,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        $filters = [
            'q' => $request->string('q')->toString(),
            'customer_id' => $request->string('customer_id')->toString() ?: null,
            'source' => $request->string('source')->toString() ?: null,
            'payment_status' => $request->string('payment_status')->toString() ?: null,
            'invoice_status' => $request->string('invoice_status')->toString() ?: null,
            'page' => (int) $request->input('page', 1),
            'per_page' => (int) $request->input('per_page', 15),
        ];

        $page = $this->invoices->search($actor, $company, $branch, $filters, $maySeeFreight);

        $customers = Party::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('roles', function ($query): void {
                $query->where('role', PartyRoleType::Customer->value)
                    ->whereNull('deleted_at')
                    ->whereNull('deactivated_at');
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Party $party): array => ['id' => $party->id, 'name' => $party->name])
            ->values()
            ->all();

        return Inertia::render('branch/invoices/index', [
            'filters' => $filters,
            'permissions' => [
                'can_create_own_fleet' => $this->invoices->canCreateOwnFleet($actor, $company, $branch),
                'can_create_broker' => $this->invoices->canCreateBroker($actor, $company, $branch),
                'can_view_own_fleet' => $this->invoices->canViewOwnFleet($actor, $company, $branch),
                'can_view_broker' => $this->invoices->canViewBroker($actor, $company, $branch),
                'can_view_freight' => $maySeeFreight,
            ],
            'sourceOptions' => collect(CustomerInvoiceSource::cases())->map(fn (CustomerInvoiceSource $source): array => [
                'value' => $source->value,
                'label' => $source->label(),
            ])->values()->all(),
            'customerOptions' => $customers,
            'invoices' => [
                'data' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.invoices.index', absolute: false),
                'create' => route('branch.invoices.create', absolute: false),
            ],
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        if (! $this->invoices->canCreateOwnFleet($actor, $company, $branch)
            && ! $this->invoices->canCreateBroker($actor, $company, $branch)) {
            abort(403, 'Not authorized to create customer invoices.');
        }

        return Inertia::render('branch/invoices/create', [
            'eligibleReceipts' => $this->invoices
                ->listEligibleLorryReceipts($actor, $company, $branch, $maySeeFreight)
                ->values()
                ->all(),
            'permissions' => [
                'can_view_freight' => $maySeeFreight,
            ],
            'urls' => [
                'index' => route('branch.invoices.index', absolute: false),
                'route_create' => route('branch.invoices.route-create', absolute: false),
            ],
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function routeCreate(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'lorry_receipt_ids' => ['required', 'array', 'min:1'],
            'lorry_receipt_ids.*' => ['uuid'],
        ]);

        $source = $this->invoices->resolveSingleSource($company, $branch, $validated['lorry_receipt_ids']);

        if ($source === CustomerInvoiceSource::OwnFleet) {
            if (! $this->invoices->canCreateOwnFleet($actor, $company, $branch)) {
                abort(403, 'Not authorized to create Own Fleet invoices.');
            }

            if (count($validated['lorry_receipt_ids']) === 1) {
                return redirect()->route('branch.trip-invoices.create', [
                    'lorry_receipt_id' => $validated['lorry_receipt_ids'][0],
                ]);
            }

            return redirect()->route('branch.trip-invoices.consolidate', [
                'lorry_receipt_ids' => $validated['lorry_receipt_ids'],
            ]);
        }

        if (! $this->invoices->canCreateBroker($actor, $company, $branch)) {
            abort(403, 'Not authorized to create Broker invoices.');
        }

        return redirect()->route('branch.broker.invoices.create', [
            'lorry_receipt_ids' => $validated['lorry_receipt_ids'],
        ]);
    }
}
