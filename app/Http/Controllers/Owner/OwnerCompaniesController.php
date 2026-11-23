<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Company\OwnerGlobalPanelService;
use App\Domains\Tax\GstRateService;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Authenticated Customer company create / global list (D1 / PO-OWNER-GLOBAL-MANAGEMENT).
 */
class OwnerCompaniesController extends Controller
{
    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly CompanyWorkspaceService $workspace,
        private readonly GstRateService $gstRates,
        private readonly OwnerGlobalPanelService $panel,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->panel->assertCustomerOwnerActor($user);
        WorkspaceSession::clear($request);

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'name');
        $direction = (string) $request->query('direction', 'asc');

        $paginator = $this->panel->paginateOwnedCompanies(
            $user,
            $search,
            $status,
            $sort,
            $direction,
        );

        return Inertia::render('owner/companies/index', [
            'filters' => [
                'q' => $search,
                'status' => $status,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'companies' => [
                'data' => $paginator->getCollection()->map(
                    fn (Company $company): array => $this->panel->presentCompanyRow($company, $user),
                )->values(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'links' => [
                'index' => route('owner.companies.index'),
                'create' => route('owner.companies.create'),
                'branches_all' => route('owner.branches.all'),
                'subscriptions' => route('owner.subscriptions.index'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->isCustomer() || ! $user->isActive()) {
            abort(403, 'Only active Customer identities may create companies.');
        }

        return Inertia::render('owner/companies/create', [
            'defaults' => [
                'contact_person' => $user->name,
                'email' => $user->email,
                'mobile' => '',
                'timezone' => 'Asia/Kolkata',
                'gst_registration_type' => GstRegistrationType::Regular->value,
                'tax_liability' => TaxLiability::Fcm->value,
                'data_visibility' => DataVisibility::SharedData->value,
                'create_initial_branch' => false,
                'initial_branch_name' => 'Head Office',
            ],
            'gstRates' => $this->gstRates->listActive()->map(fn ($rate): array => [
                'id' => $rate->id,
                'code' => $rate->code,
                'name' => $rate->name,
                'rate_percent' => (string) $rate->rate_percent,
            ])->values(),
            'options' => [
                'gst_registration_types' => array_map(
                    fn (GstRegistrationType $case): array => ['value' => $case->value, 'label' => $case->name],
                    GstRegistrationType::cases(),
                ),
                'tax_liabilities' => array_map(
                    fn (TaxLiability $case): array => ['value' => $case->value, 'label' => strtoupper($case->value)],
                    TaxLiability::cases(),
                ),
                'data_visibilities' => [
                    ['value' => DataVisibility::SharedData->value, 'label' => 'Shared Data'],
                    ['value' => DataVisibility::BranchWiseRestricted->value, 'label' => 'Branch-wise Restricted'],
                ],
            ],
            'links' => [
                'store' => route('owner.companies.store'),
                'workspace' => route('workspace.landing'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->isCustomer() || ! $user->isActive()) {
            abort(403, 'Only active Customer identities may create companies.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'pan' => ['required', 'string', 'max:10'],
            'registered_address' => ['required', 'string', 'max:2000'],
            'state' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'pin' => ['required', 'string', 'max:10'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'gst_registration_type' => ['required', Rule::enum(GstRegistrationType::class)],
            'tax_liability' => ['required', Rule::enum(TaxLiability::class)],
            'gstin' => ['nullable', 'string', 'max:15'],
            'gst_rate_id' => ['nullable', 'uuid', 'exists:gst_rates,id'],
            'data_visibility' => ['required', Rule::enum(DataVisibility::class)],
            'timezone' => ['nullable', 'string', 'max:64'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'create_initial_branch' => ['sometimes', 'boolean'],
            'initial_branch_name' => ['nullable', 'string', 'max:255'],
        ]);

        $createBranch = (bool) ($validated['create_initial_branch'] ?? false);
        $branchName = $createBranch
            ? (trim((string) ($validated['initial_branch_name'] ?? '')) ?: 'Head Office')
            : null;

        try {
            $company = $this->workspace->createCompany(
                $user,
                $validated['name'],
                $branchName,
                $validated,
                requireCompleteProfile: true,
            );
        } catch (CompanyDomainException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        $this->access->activateContext($user, $company);
        WorkspaceSession::put($request, $company->id);

        return redirect()
            ->route('owner.dashboard', ['company' => $company])
            ->with('success', $createBranch
                ? 'Company created with initial branch.'
                : 'Company created. You may create a branch next or continue in Owner Dashboard.');
    }
}
