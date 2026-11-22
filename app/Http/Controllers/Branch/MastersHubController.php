<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch Panel masters navigation hub (PO-P3-UI-AUTH).
 */
class MastersHubController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
    ) {}

    public function __invoke(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_view']) {
            abort(403, 'Not authorized to view masters.');
        }

        $categories = [
            [
                'key' => 'parties',
                'title' => 'Party Master',
                'description' => 'Customers, transporters, drivers, vendors and multi-role parties.',
                'href' => route('branch.parties.index', [$company, $branch]),
                'visible' => true,
            ],
            [
                'key' => 'vehicles',
                'title' => 'Vehicle Master',
                'description' => 'Registration, capacity and vehicle attributes.',
                'href' => route('branch.vehicles.index', [$company, $branch]),
                'visible' => true,
            ],
            [
                'key' => 'bank_cash',
                'title' => 'Bank / Cash Accounts',
                'description' => 'Bank and cash accounts with masked account numbers.',
                'href' => route('branch.bank-cash.index', [$company, $branch]),
                'visible' => true,
            ],
            [
                'key' => 'stations',
                'title' => 'Stations',
                'description' => 'Shared station directory and default transporters.',
                'href' => route('branch.stations.index', [$company, $branch]),
                'visible' => true,
            ],
            [
                'key' => 'catalogues',
                'title' => 'Supporting Masters',
                'description' => 'Goods types, truck types, document types and more.',
                'href' => route('branch.catalogues.index', [$company, $branch]),
                'visible' => true,
            ],
        ];

        return Inertia::render('branch/masters/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'categories' => $categories,
            'links' => [
                'dashboard' => route('branch.dashboard', [$company, $branch]),
            ],
        ]);
    }
}
