<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\GlLedgerService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingLedgerQuickCreateController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly GlLedgerService $ledgers,
    ) {}

    public function store(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);

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
