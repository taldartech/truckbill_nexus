<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\GlLedgerService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Accounting\Concerns\AuthorizesAccountingPanel;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingLedgerQuickCreateController extends Controller
{
    use AuthorizesAccountingPanel;
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly GlLedgerService $ledgers,
    ) {}

    public function store(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, requireMutation: true);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'account_group_id' => ['required', 'uuid'],
        ]);

        $ledger = $this->ledgers->create($company, $actor, $validated);

        return response()->json([
            'data' => [
                'id' => $ledger->id,
                'name' => $ledger->name,
            ],
        ], 201);
    }
}
