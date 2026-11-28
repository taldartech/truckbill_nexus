<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\LorryHire\LorryHireRevisionService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Consignment\Support\FreightRateTypeCatalog;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsLorryHireForm;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\LorryHireGoodsLine;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lorry Hire edit — reuses the create form; inherited Bilty data stays read only (Rules §37).
 */
class LorryHireEditController extends Controller
{
    use AuthorizesBranchPanel;
    use PresentsLorryHireForm;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly LorryHireRevisionService $revisions,
        private readonly LorryHireCalculationService $calculations,
    ) {}

    public function edit(Request $request, LorryHire $lorryHire, Company $company, Branch $branch): Response
    {
        $this->authorizeEdit($request, $lorryHire, $company, $branch);
        $lorryHire->load(['lorryReceipt', 'transporter', 'tdsSnapshot', 'goodsLines.lrGoodsRow']);
        $lorryReceipt = $lorryHire->lorryReceipt ?? abort(404);

        $summary = $this->calculations->summarize($lorryHire);
        $hireRowIds = $lorryHire->goodsLines->pluck('lr_goods_row_id')->all();
        $formProps = $this->lorryHireFormProps($lorryReceipt);
        $formProps['goodsRows'] = array_values(array_filter(
            $formProps['goodsRows'],
            fn (array $row): bool => in_array($row['id'], $hireRowIds, true),
        ));

        return Inertia::render('broker/lorry-hires/edit', [
            ...$formProps,
            'payee' => $lorryHire->transporter !== null
                ? ['id' => $lorryHire->transporter->id, 'name' => $lorryHire->transporter->name]
                : null,
            'payeeBlockedReason' => null,
            'tdsPreview' => [
                'applicable_percent' => number_format((float) $lorryHire->tds_percent, 3, '.', ''),
                'explanation' => (string) ($lorryHire->tdsSnapshot->explanation ?? ''),
            ],
            'defaults' => [
                'hire_date' => $lorryHire->hire_date?->toDateString() ?? '',
                'rate_type' => FreightRateTypeCatalog::default()->value,
            ],
            'permissions' => ['can_create_voucher' => false],
            'hire' => [
                'id' => $lorryHire->id,
                'hire_number' => $lorryHire->hire_number,
                'hire_date' => $lorryHire->hire_date?->toDateString(),
                'remarks' => $lorryHire->remarks,
                'goods_lines' => $lorryHire->goodsLines->map(fn (LorryHireGoodsLine $line): array => [
                    'lr_goods_row_id' => $line->lr_goods_row_id,
                    'rate_type' => $line->rate_type->value,
                    'rate' => $this->plainDecimal($line->rate),
                    'custom_amount' => $line->custom_amount !== null ? $this->plainDecimal($line->custom_amount) : null,
                    'calculated_freight_amount' => $this->calculations->calculatedLineFreight($line),
                    'freight_amount' => number_format((float) $line->freight_amount, 2, '.', ''),
                ])->values()->all(),
                'freight_total' => $summary['freight_total'],
                'tds_amount' => $summary['tds_amount'],
                'net_payable' => $summary['net_payable'],
                'outstanding_amount' => $summary['outstanding_amount'],
                'settled_amount' => bcsub($summary['net_payable'], $summary['outstanding_amount'], 2),
                'reserved_voucher_amount' => $summary['reserved_voucher_amount'],
                'tds_locked_reason' => $this->revisions->tdsLockedReason($lorryHire, $company, $summary['outstanding_amount']),
                'has_unloading_adjustment' => $this->calculations->latestUnloadingWeight($lorryHire) !== null,
            ],
            'urls' => [
                'store' => route('branch.broker.lorry-hires.update', [$lorryHire], absolute: false),
                'preview' => route('branch.broker.lorry-hires.revision-preview', [$lorryHire], absolute: false),
                'lr_show' => route('branch.broker.lorry-hires.show', [$lorryHire], absolute: false),
                'lr_edit' => route('branch.lorry-receipts.edit', [$lorryReceipt], absolute: false),
            ],
        ]);
    }

    public function preview(Request $request, LorryHire $lorryHire, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->authorizeEdit($request, $lorryHire, $company, $branch);
        $validated = $request->validate($this->lorryHireFormRules());

        return response()->json($this->guard(fn (): array => $this->revisions->preview($lorryHire, $actor, $validated)));
    }

    public function update(Request $request, LorryHire $lorryHire, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->authorizeEdit($request, $lorryHire, $company, $branch);
        $validated = $request->validate([
            ...$this->lorryHireFormRules(),
            'edit_remark' => ['required', 'string', 'max:2000'],
        ], [
            'edit_remark.required' => 'Enter the reason for editing this Lorry Hire.',
        ]);

        $this->guard(fn () => $this->revisions->revise($lorryHire, $actor, $validated));

        return redirect()
            ->route('branch.broker.lorry-hires.show', [$lorryHire])
            ->with('success', 'Lorry Hire updated.');
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerDomainException|AccountingDomainException $e) {
            throw ValidationException::withMessages(['goods_lines' => $e->getMessage()]);
        }
    }

    private function authorizeEdit(Request $request, LorryHire $lorryHire, Company $company, Branch $branch): User
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        if ($lorryHire->company_id !== $company->id || $lorryHire->branch_id !== $branch->id || $lorryHire->trashed()) {
            abort(404);
        }

        if (! $this->revisions->canEdit($actor, $company, $branch, $lorryHire)) {
            abort(403, 'Not authorized to edit this Lorry Hire.');
        }

        return $actor;
    }

    private function plainDecimal(mixed $value): string
    {
        $formatted = number_format((float) $value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
