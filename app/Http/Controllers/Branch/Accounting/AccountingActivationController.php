<?php

namespace App\Http\Controllers\Branch\Accounting;

use App\Domains\Accounting\AccountingActivationLifecycleService;
use App\Domains\Accounting\AccountingReconciliationService;
use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Accounting\Concerns\AuthorizesAccountingPanel;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountingActivationController extends Controller
{
    use AuthorizesAccountingPanel;
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly AccountingActivationLifecycleService $activation,
        private readonly AccountingReconciliationService $reconciliation,
    ) {}

    public function show(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VIEW);

        return Inertia::render('accounting/activation', [
            'activation' => $this->activation->statusPayload($company),
            'urls' => [
                'dry_run' => route('branch.accounting.activation.dry-run', absolute: false),
                'confirm' => route('branch.accounting.activation.confirm', absolute: false),
                'catch_up' => route('branch.accounting.activation.catch-up', absolute: false),
            ],
        ]);
    }

    public function dryRun(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, requireMutation: true);

        $validated = $request->validate([
            'cutover_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.gl_ledger_id' => ['required', 'uuid'],
            'lines.*.debit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->activation->dryRunCutover(
            $company,
            $validated['lines'],
            $validated['cutover_date'] ?? null,
        ));
    }

    public function confirm(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, requireMutation: true);

        $validated = $request->validate([
            'cutover_date' => ['nullable', 'date'],
            'lines' => ['nullable', 'array'],
            'lines.*.gl_ledger_id' => ['required_with:lines', 'uuid'],
            'lines.*.debit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->activation->activateCutover(
                company: $company,
                actor: $actor,
                lines: $validated['lines'] ?? null,
                branch: $branch,
                cutoverDate: $validated['cutover_date'] ?? null,
            );
        } catch (AccountingDomainException $e) {
            throw ValidationException::withMessages(['cutover_date' => $e->getMessage()]);
        }

        $msg = $result['voucher'] !== null
            ? 'Accounting cutover confirmed and Opening Balance voucher '.$result['voucher']->voucher_number.' posted.'
            : 'Accounting cutover confirmed.';

        return back()->with('flash.success', $msg);
    }

    public function catchUp(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE, requireMutation: true);

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        try {
            $result = $this->activation->runCatchUp(
                company: $company,
                actor: $actor,
                fromDate: $validated['from_date'] ?? null,
                toDate: $validated['to_date'] ?? null,
            );
        } catch (AccountingDomainException $e) {
            throw ValidationException::withMessages(['from_date' => $e->getMessage()]);
        }

        return back()->with(
            'flash.success',
            "Catch-up posted {$result['posted_count']} missing financial transaction(s) ({$result['from_date']} to {$result['to_date']}).",
        );
    }

    public function reconciliation(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->authorizeAccounting($actor, $company, $branch, $this->authorizer, PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW);

        $from = $request->string('from_date')->toString() ?: null;
        $to = $request->string('to_date')->toString() ?: null;

        return Inertia::render('accounting/reconciliation', [
            'filters' => [
                'from_date' => $from,
                'to_date' => $to,
            ],
            'reconciliation' => $this->reconciliation->reconcile($company, $branch, $from, $to),
            'urls' => [
                'index' => route('branch.accounting.reports.reconciliation', absolute: false),
            ],
        ]);
    }
}
