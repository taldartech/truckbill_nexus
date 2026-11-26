<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Company\OwnerGlobalPanelService;
use App\Domains\Company\OwnerUserProvisioningService;
use App\Domains\Invitation\Exceptions\InvitationException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Cross-company membership listing and global user create for Customer Owners.
 */
class OwnerGlobalUsersController extends Controller
{
    use AuthorizesOwnerPanel;

    public function __construct(
        private readonly OwnerGlobalPanelService $panel,
        private readonly OwnerUserProvisioningService $provisioning,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->panel->assertOwnsAtLeastOneCompany($user);

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'active');
        $companyId = $request->query('company');
        $companyId = is_string($companyId) ? $companyId : null;

        $paginator = $this->panel->paginateOwnedMemberships(
            $user,
            $companyId,
            $search,
            $status,
        );

        return Inertia::render('owner/users/all', [
            'filters' => [
                'q' => $search,
                'status' => $status,
                'company' => $companyId ?? '',
            ],
            'companyOptions' => $this->panel->ownedCompanyFilterOptions($user),
            'memberships' => [
                'data' => $paginator->getCollection()->map(
                    fn (CompanyMembership $membership): array => $this->panel->presentMembershipRow($membership),
                )->values(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'links' => [
                'index' => route('owner.users.all'),
                'companies' => route('owner.companies.index'),
                'create' => route('owner.users.create.global'),
            ],
            'canManage' => true,
        ]);
    }

    public function createGlobal(Request $request): Response|RedirectResponse
    {
        $user = $this->authorizeOwnerIdentity($request);
        $companyOptions = $this->eligibleCompanyOptions($user);

        if ($companyOptions === []) {
            return redirect()
                ->route('owner.companies.create')
                ->with('info', 'Create a company before inviting users.');
        }

        $requestedId = $request->query('company');
        $requestedId = is_string($requestedId) && $requestedId !== '' ? $requestedId : null;

        if ($requestedId !== null && ! collect($companyOptions)->contains(fn (array $row): bool => $row['id'] === $requestedId)) {
            abort(403, 'You do not own the selected company.');
        }

        $preselectedCompanyId = $requestedId;
        if ($preselectedCompanyId === null && count($companyOptions) === 1) {
            $preselectedCompanyId = $companyOptions[0]['id'];
        }

        return Inertia::render('owner/users/create', [
            'preselectedCompanyId' => $preselectedCompanyId,
            'companyOptions' => $companyOptions,
            'customerRoles' => $this->provisioning->assignableCustomerRoles(),
            'links' => [
                'index' => route('owner.users.all'),
                'store' => route('owner.users.store.global'),
                'create' => route('owner.users.create.global'),
            ],
        ]);
    }

    public function storeGlobal(Request $request): RedirectResponse
    {
        $user = $this->authorizeOwnerIdentity($request);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'companies' => ['required', 'array', 'min:1'],
            'companies.*.company_id' => ['required', 'uuid'],
            'companies.*.assignments' => ['required', 'array', 'min:1'],
            'companies.*.assignments.*.branch_id' => ['required', 'uuid'],
            'companies.*.assignments.*.role_id' => ['required', 'uuid'],
        ]);

        try {
            $result = $this->provisioning->provision(
                $user,
                $validated['email'],
                $validated['name'] ?? null,
                $validated['companies'],
                'Owner user access grant',
            );
        } catch (InvitationException|CompanyDomainException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        $message = $result['messages'] !== [] ? implode(' ', $result['messages']) : 'User access updated.';

        $companyFilter = $validated['companies'][0]['company_id'] ?? null;

        return redirect()
            ->route('owner.users.all', array_filter([
                'company' => is_string($companyFilter) ? $companyFilter : null,
            ]))
            ->with('success', $message);
    }

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     status: string,
     *     available_user_capacity: int,
     *     branches: list<array{id: string, name: string}>
     * }>
     */
    private function eligibleCompanyOptions(User $user): array
    {
        return Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->orderBy('name')
            ->get()
            ->map(function (Company $company): array {
                $capacity = $this->provisioning->seatCapacitySnapshot($company);

                $branches = Branch::query()
                    ->where('company_id', $company->id)
                    ->whereNull('deactivated_at')
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Branch $branch): array => [
                        'id' => $branch->id,
                        'name' => $branch->name,
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'status' => 'Active',
                    'available_user_capacity' => (int) $capacity['available_user_capacity'],
                    'branches' => $branches,
                ];
            })
            ->values()
            ->all();
    }
}
