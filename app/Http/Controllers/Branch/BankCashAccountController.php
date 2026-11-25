<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\MasterDataVisibilityResolver;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Master\BankCash\BankCashAccountService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankCashAccountController extends Controller
{
    use AuthorizesBranchPanel;
    use PresentsDocumentDrawer;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly BankCashAccountService $accounts,
        private readonly DocumentAttachmentService $documents,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $page = $this->accounts->search(
                actor: $actor,
                company: $company,
                branch: $branch,
                query: $request->string('q')->toString() ?: null,
                accountKind: $request->string('kind')->toString() ?: null,
                perPage: min(100, max(1, (int) $request->input('per_page', 15))),
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return Inertia::render('branch/masters/bank-cash/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'filters' => [
                'q' => $request->string('q')->toString(),
                'kind' => $request->string('kind')->toString(),
            ],
            'accounts' => [
                'data' => collect($page->items())->map(fn (BankCashAccount $account): array => $this->accounts->present($account))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ],
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.bank-cash.index', absolute: false),
                'create' => route('branch.bank-cash.create', absolute: false),
                'store' => route('branch.bank-cash.store', absolute: false),
            ]),
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_create']) {
            abort(403);
        }

        return Inertia::render('branch/masters/bank-cash/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'create',
            'account' => null,
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.bank-cash.index', absolute: false),
                'store' => route('branch.bank-cash.store', absolute: false),
            ]),
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'account_kind' => ['required', Rule::in(['bank', 'cash'])],
            'name' => ['required', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'account_holder_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $scopeBranch = app(MasterDataVisibilityResolver::class)
            ->scopeBranchForCreate($company, $branch);

        try {
            $account = $this->accounts->create(
                $actor,
                $company,
                $scopeBranch,
                $validated,
                $validated['remark'] ?? 'Create bank/cash account',
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'account_kind' => $account->account_kind,
                ],
            ], 201);
        }

        return redirect()
            ->route('branch.bank-cash.show', [$account])
            ->with('success', 'Account created.');
    }

    public function show(Request $request, BankCashAccount $bankCashAccount, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAccount($company, $branch, $bankCashAccount);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $this->accounts->search($actor, $company, $branch, perPage: 1);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return Inertia::render('branch/masters/bank-cash/show', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'account' => $this->accounts->present($bankCashAccount),
            'documentDrawer' => $this->documentDrawerPayload(
                $actor,
                $company,
                $branch,
                'bank_cash_account',
                $bankCashAccount->id,
                $this->documents,
                $permissions,
            ),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.bank-cash.index', absolute: false),
                'edit' => route('branch.bank-cash.edit', [$bankCashAccount], absolute: false),
                'update' => route('branch.bank-cash.update', [$bankCashAccount], absolute: false),
                'destroy' => route('branch.bank-cash.destroy', [$bankCashAccount], absolute: false),
                'documents' => route('branch.documents.index', [
                    'company' => $company,
                    'branch' => $branch,
                    'parentType' => 'bank_cash_account',
                    'parentId' => $bankCashAccount->id,
                ], absolute: false),
            ]),
        ]);
    }

    public function edit(Request $request, BankCashAccount $bankCashAccount, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAccount($company, $branch, $bankCashAccount);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_edit']) {
            abort(403);
        }

        // Never expose full stored account numbers on edit — only masked presentation.
        return Inertia::render('branch/masters/bank-cash/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'edit',
            'account' => $this->accounts->present($bankCashAccount),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.bank-cash.index', absolute: false),
                'show' => route('branch.bank-cash.show', [$bankCashAccount], absolute: false),
                'update' => route('branch.bank-cash.update', [$bankCashAccount], absolute: false),
            ]),
        ]);
    }

    public function update(Request $request, BankCashAccount $bankCashAccount, Company $company, Branch $branch): RedirectResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAccount($company, $branch, $bankCashAccount);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'account_holder_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        // Empty account_number means "keep existing" — do not blank the stored value from masked UI.
        if (! array_key_exists('account_number', $validated) || $validated['account_number'] === null || trim((string) $validated['account_number']) === '') {
            unset($validated['account_number']);
        }

        try {
            $this->accounts->update($actor, $bankCashAccount, $validated, $validated['remark']);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.bank-cash.show', [$bankCashAccount])
            ->with('success', 'Account updated.');
    }

    public function destroy(Request $request, BankCashAccount $bankCashAccount, Company $company, Branch $branch): RedirectResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAccount($company, $branch, $bankCashAccount);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->accounts->softDelete($actor, $bankCashAccount, $validated['remark']);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.bank-cash.index')
            ->with('success', 'Account deleted.');
    }

    private function assertAccount(Company $company, Branch $branch, BankCashAccount $account): void
    {
        if ($account->company_id !== $company->id) {
            abort(404);
        }

        try {
            app(MasterAccess::class)
                ->assertRecordVisibleInBranch($company, $branch, BankCashAccount::class, $account->id);
        } catch (MasterDomainException) {
            abort(404);
        }
    }
}
