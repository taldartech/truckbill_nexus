<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Master\BankCash\BankCashAccountService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
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
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.bank-cash.index', [$company, $branch]),
                'create' => route('branch.bank-cash.create', [$company, $branch]),
                'store' => route('branch.bank-cash.store', [$company, $branch]),
            ],
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
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.bank-cash.index', [$company, $branch]),
                'store' => route('branch.bank-cash.store', [$company, $branch]),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'scope' => ['required', Rule::in(['shared', 'branch'])],
            'account_kind' => ['required', Rule::in(['bank', 'cash'])],
            'name' => ['required', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'account_holder_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $account = $this->accounts->create(
                $actor,
                $company,
                $validated['scope'] === 'branch' ? $branch : null,
                $validated,
                $validated['remark'] ?? 'Create bank/cash account',
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.bank-cash.show', [$company, $branch, $account])
            ->with('success', 'Account created.');
    }

    public function show(Request $request, Company $company, Branch $branch, BankCashAccount $bankCashAccount): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAccount($company, $bankCashAccount);
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
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.bank-cash.index', [$company, $branch]),
                'edit' => route('branch.bank-cash.edit', [$company, $branch, $bankCashAccount]),
                'update' => route('branch.bank-cash.update', [$company, $branch, $bankCashAccount]),
                'destroy' => route('branch.bank-cash.destroy', [$company, $branch, $bankCashAccount]),
                'documents' => route('branch.documents.index', [
                    'company' => $company,
                    'branch' => $branch,
                    'parentType' => 'bank_cash_account',
                    'parentId' => $bankCashAccount->id,
                ]),
            ],
        ]);
    }

    public function edit(Request $request, Company $company, Branch $branch, BankCashAccount $bankCashAccount): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAccount($company, $bankCashAccount);
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
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.bank-cash.index', [$company, $branch]),
                'show' => route('branch.bank-cash.show', [$company, $branch, $bankCashAccount]),
                'update' => route('branch.bank-cash.update', [$company, $branch, $bankCashAccount]),
            ],
        ]);
    }

    public function update(Request $request, Company $company, Branch $branch, BankCashAccount $bankCashAccount): RedirectResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAccount($company, $bankCashAccount);

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
            ->route('branch.bank-cash.show', [$company, $branch, $bankCashAccount])
            ->with('success', 'Account updated.');
    }

    public function destroy(Request $request, Company $company, Branch $branch, BankCashAccount $bankCashAccount): RedirectResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAccount($company, $bankCashAccount);

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
            ->route('branch.bank-cash.index', [$company, $branch])
            ->with('success', 'Account deleted.');
    }

    private function assertAccount(Company $company, BankCashAccount $account): void
    {
        if ($account->company_id !== $company->id) {
            abort(404);
        }
    }
}
