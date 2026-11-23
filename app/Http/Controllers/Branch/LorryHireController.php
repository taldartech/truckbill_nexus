<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\LorryHire\LorryHireCancellationService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Broker Lorry Hire Inertia UI — Phase 6 Batch A foundation.
 */
class LorryHireController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly LorryHireService $lorryHires,
        private readonly LorryHireCancellationService $cancellations,
        private readonly LorryHireCalculationService $calculations,
        private readonly BrokerAdjustmentService $adjustments,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertBrokerModule($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LORRY_HIRE_VIEW);

        $page = $this->lorryHires->search(
            company: $company,
            branch: $branch,
            status: $request->string('status')->toString() ?: null,
            perPage: min(100, max(1, (int) $request->input('per_page', 15))),
        );

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('broker/lorry-hires/index', [
            'filters' => [
                'status' => $request->string('status')->toString(),
            ],
            'permissions' => $this->lorryHirePermissions($actor, $company, $branch, $maySeeFreight),
            'statusOptions' => collect(LorryHireStatus::cases())->map(fn (LorryHireStatus $status): array => [
                'value' => $status->value,
                'label' => match ($status) {
                    LorryHireStatus::Pending => 'Pending',
                    LorryHireStatus::Active => 'Active',
                    LorryHireStatus::Cancelled => 'Cancelled',
                },
            ])->values()->all(),
            'lorryHires' => [
                'data' => collect($page->items())->map(fn (LorryHire $hire): array => $this->listPayload($hire, $maySeeFreight))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.broker.lorry-hires.index', absolute: false),
            ],
        ]);
    }

    public function createFromLorryReceipt(
        Request $request,
        LorryReceipt $lorryReceipt,
        Company $company,
        Branch $branch,
    ): Response|RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LORRY_HIRE_CREATE);
        $this->assertLr($lorryReceipt, $company, $branch);

        if ($lorryReceipt->workflow_context !== LrWorkflowContext::Broker) {
            abort(422, 'Only Broker Lorry Receipts can create a Lorry Hire.');
        }

        $existing = $this->lorryHires->lorryHireForLorryReceipt($lorryReceipt);
        if ($existing !== null) {
            return redirect()
                ->route('branch.broker.lorry-hires.show', [$existing])
                ->with('info', 'This Broker LR already has a Lorry Hire.');
        }

        $lorryReceipt->load(['fromStation', 'toStation', 'consignor', 'consignee', 'vehicle', 'goodsRows']);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('broker/lorry-hires/create', [
            'lorryReceipt' => [
                'id' => $lorryReceipt->id,
                'lr_number' => $lorryReceipt->lr_number,
                'lr_date' => $lorryReceipt->lr_date?->format('d-m-Y'),
                'from_station_name' => $lorryReceipt->fromStation?->name,
                'to_station_name' => $lorryReceipt->toStation?->name,
                'consignor_name' => $lorryReceipt->consignor?->name,
                'consignee_name' => $lorryReceipt->consignee?->name,
                'vehicle_registration' => $lorryReceipt->vehicle?->registration_number,
            ],
            'goodsRows' => $lorryReceipt->goodsRows->map(fn ($row): array => [
                'id' => $row->id,
                'goods_description' => $row->goods_description,
                'freight_rate_type' => $row->freight_rate_type->value,
                'freight_rate' => $this->freightPresentation->presentAmount($row->freight_rate, $maySeeFreight),
                'freight_amount' => $this->freightPresentation->presentAmount($row->freight_amount, $maySeeFreight),
                'loading_weight' => $row->charged_weight ?? $row->actual_weight,
            ])->values()->all(),
            'permissions' => $this->lorryHirePermissions($actor, $company, $branch, $maySeeFreight),
            'urls' => [
                'store' => route('branch.broker.lorry-hires.store-from-lr', [$lorryReceipt], absolute: false),
                'lr_show' => route('branch.lorry-receipts.show', [$lorryReceipt], absolute: false),
                'index' => route('branch.broker.lorry-hires.index', absolute: false),
            ],
        ]);
    }

    public function storeFromLorryReceipt(
        Request $request,
        LorryReceipt $lorryReceipt,
        Company $company,
        Branch $branch,
    ): RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LORRY_HIRE_CREATE);
        $this->assertLr($lorryReceipt, $company, $branch);

        $validated = $request->validate([
            'hire_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'goods_lines' => ['required', 'array', 'min:1'],
            'goods_lines.*.lr_goods_row_id' => ['required', 'uuid'],
            'goods_lines.*.rate_type' => ['nullable', 'string'],
            'goods_lines.*.rate' => ['required', 'numeric', 'min:0'],
            'goods_lines.*.is_fixed_amount' => ['sometimes', 'boolean'],
        ]);

        try {
            $hire = $this->lorryHires->createFromLorryReceipt($lorryReceipt, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerConflictException $e) {
            throw ValidationException::withMessages(['goods_lines' => $e->getMessage()]);
        } catch (BrokerDomainException $e) {
            throw ValidationException::withMessages(['goods_lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.broker.lorry-hires.show', [$hire])
            ->with('success', 'Broker Lorry Hire created.');
    }

    public function show(Request $request, LorryHire $lorryHire, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LORRY_HIRE_VIEW);
        $this->assertLorryHire($lorryHire, $company, $branch);

        try {
            $lorryHire = $this->lorryHires->findForBranch($company, $branch, $lorryHire->id);
        } catch (BrokerDomainException $e) {
            abort(404, $e->getMessage());
        }

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $summary = $this->calculations->summarize($lorryHire);
        $adjustmentRows = $this->adjustments->listForHire($company, $branch, $lorryHire);
        $lorryHire->loadMissing(['lorryReceipt.delivery', 'delivery']);

        $canCancel = $this->canCancelLorryHire($actor, $company, $branch)
            && $lorryHire->status !== LorryHireStatus::Cancelled;

        return Inertia::render('broker/lorry-hires/show', [
            'lorryHire' => $this->detailPayload($lorryHire, $maySeeFreight, $summary),
            'cancelPreview' => $canCancel ? $this->cancellations->preview($lorryHire) : null,
            'adjustments' => $adjustmentRows->map(fn ($row): array => [
                'id' => $row->id,
                'type' => $row->type->value,
                'amount' => $this->freightPresentation->presentAmount($row->amount, $maySeeFreight),
                'previous_weight' => $row->previous_weight,
                'new_weight' => $row->new_weight,
                'quantity' => $row->quantity,
                'rate' => $row->rate,
                'reason' => $row->reason,
                'created_at' => $row->created_at?->format('d-m-Y H:i'),
            ])->values()->all(),
            'adjustmentTypeOptions' => collect(BrokerAdjustmentType::cases())->map(fn (BrokerAdjustmentType $type): array => [
                'value' => $type->value,
                'label' => ucwords(str_replace('_', ' ', $type->value)),
            ])->values()->all(),
            'delivery' => $this->deliveryPanelPayload($lorryHire),
            'permissions' => array_merge(
                $this->lorryHirePermissions($actor, $company, $branch, $maySeeFreight),
                $this->adjustmentDeliveryPermissions($actor, $company, $branch),
            ),
            'urls' => [
                'index' => route('branch.broker.lorry-hires.index', absolute: false),
                'lr_show' => route('branch.lorry-receipts.show', [$lorryHire->lorry_receipt_id], absolute: false),
                'adjustment_store' => route('branch.broker.lorry-hires.adjustments.store', [$lorryHire], absolute: false),
                'deliveries' => route('branch.broker.deliveries.index', absolute: false),
                'cancel' => $canCancel
                    ? route('branch.broker.lorry-hires.cancel', [$lorryHire], absolute: false)
                    : null,
            ],
        ]);
    }

    public function cancel(
        Request $request,
        LorryHire $lorryHire,
        Company $company,
        Branch $branch,
    ): RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertLorryHire($lorryHire, $company, $branch);

        if (! $this->canCancelLorryHire($actor, $company, $branch)) {
            abort(403, 'Not authorized to cancel Broker Lorry Hires.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'settle_outstanding' => ['required', 'boolean'],
            'cancelled_at' => ['nullable', 'date'],
        ]);

        try {
            $this->cancellations->cancel($lorryHire, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerConflictException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        } catch (BrokerDomainException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.broker.lorry-hires.show', [$lorryHire])
            ->with('success', 'Broker Lorry Hire cancelled.');
    }

    private function assertLorryHire(LorryHire $lorryHire, Company $company, Branch $branch): void
    {
        if ($lorryHire->company_id !== $company->id || $lorryHire->branch_id !== $branch->id || $lorryHire->trashed()) {
            abort(404);
        }
    }

    private function assertLr(LorryReceipt $lorryReceipt, Company $company, Branch $branch): void
    {
        if ($lorryReceipt->company_id !== $company->id || $lorryReceipt->branch_id !== $branch->id) {
            abort(404);
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        $this->assertBrokerModule($company);

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Broker Lorry Hire operation.');
        }
    }

    private function assertBrokerModule(Company $company): void
    {
        if (! $this->moduleEntitlements->isEntitled($company, SubscriptionModule::Broker)) {
            abort(403, 'Broker module is not entitled for this company.');
        }
    }

    /**
     * @return array{
     *     status: string|null,
     *     memo_number: string|null,
     *     pod_number: string|null,
     *     delivery_date: string|null,
     *     lr_delivered_at: string|null,
     *     lr_status: string|null
     * }
     */
    private function deliveryPanelPayload(LorryHire $hire): array
    {
        $delivery = $hire->delivery ?? $hire->lorryReceipt?->delivery;
        $lr = $hire->lorryReceipt;

        return [
            'status' => $delivery?->status?->value,
            'memo_number' => $delivery?->memo_number,
            'pod_number' => $delivery?->pod_number,
            'delivery_date' => $delivery?->delivery_date?->format('d-m-Y'),
            'lr_delivered_at' => $lr?->delivered_at?->format('d-m-Y'),
            'lr_status' => $lr?->status?->value,
        ];
    }

    /**
     * @return array{can_create_adjustment: bool, can_view_adjustment: bool, can_manage_delivery: bool}
     */
    private function adjustmentDeliveryPermissions(User $actor, Company $company, Branch $branch): array
    {
        return [
            'can_create_adjustment' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_ADJUSTMENT_CREATE, $company, $branch),
            'can_view_adjustment' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_ADJUSTMENT_VIEW, $company, $branch),
            'can_manage_delivery' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_DELIVERY_MANAGE, $company, $branch),
        ];
    }

    /**
     * @return array{can_view: bool, can_create: bool, can_edit: bool, can_cancel: bool, can_view_freight: bool}
     */
    private function lorryHirePermissions(User $actor, Company $company, Branch $branch, bool $maySeeFreight): array
    {
        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LORRY_HIRE_VIEW, $company, $branch),
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LORRY_HIRE_CREATE, $company, $branch),
            'can_edit' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LORRY_HIRE_EDIT, $company, $branch),
            'can_cancel' => $this->canCancelLorryHire($actor, $company, $branch),
            'can_view_freight' => $maySeeFreight,
        ];
    }

    /**
     * @param  array{
     *     freight_total: numeric-string,
     *     tds_amount: numeric-string,
     *     net_payable: numeric-string,
     *     outstanding_amount: numeric-string
     * }  $summary
     * @return array{
     *     id: string,
     *     hire_number: string,
     *     status: string,
     *     hire_date: string|null,
     *     lr_number: string|null,
     *     from_station_name: string|null,
     *     to_station_name: string|null,
     *     vehicle_registration: string|null,
     *     transporter_name: string|null,
     *     declaration_type: string,
     *     remarks: string|null,
     *     freight_total: string|null,
     *     tds_percent: string,
     *     tds_amount: string|null,
     *     net_payable: string|null,
     *     outstanding_amount: string|null,
     *     goods_lines: array<int, array{
     *         id: string,
     *         goods_description: string|null,
     *         rate_type: string,
     *         rate: string|null,
     *         freight_amount: string|null,
     *         loading_weight: string|null,
     *         is_fixed_amount: bool
     *     }>,
     *     tds_snapshot: array{
     *         applicable_rate: string,
     *         rule_code: string|null,
     *         declaration_status: string,
     *         explanation: string
     *     }|null,
     *     created_at: string|null,
     *     cancelled_at: string|null,
     *     cancellation_remark: string|null,
     *     settle_outstanding: bool|null
     * }
     */
    private function detailPayload(LorryHire $hire, bool $maySeeFreight, array $summary): array
    {
        $lr = $hire->lorryReceipt;

        return [
            'id' => $hire->id,
            'hire_number' => $hire->hire_number,
            'status' => $hire->status->value,
            'hire_date' => $hire->hire_date?->format('d-m-Y'),
            'lr_number' => $lr?->lr_number,
            'from_station_name' => $lr?->fromStation?->name,
            'to_station_name' => $lr?->toStation?->name,
            'vehicle_registration' => $hire->vehicle?->registration_number,
            'transporter_name' => $hire->transporter?->name,
            'declaration_type' => $hire->declaration_type,
            'remarks' => $hire->remarks,
            'cancelled_at' => $hire->cancelled_at?->format('d-m-Y H:i'),
            'cancellation_remark' => $hire->cancellation_remark,
            'settle_outstanding' => $hire->settle_outstanding,
            'freight_total' => $this->freightPresentation->presentAmount($summary['freight_total'], $maySeeFreight),
            'tds_percent' => $hire->tds_percent,
            'tds_amount' => $this->freightPresentation->presentAmount($summary['tds_amount'], $maySeeFreight),
            'net_payable' => $this->freightPresentation->presentAmount($summary['net_payable'], $maySeeFreight),
            'outstanding_amount' => $this->freightPresentation->presentAmount($summary['outstanding_amount'], $maySeeFreight),
            'goods_lines' => $hire->goodsLines->map(fn ($line): array => [
                'id' => $line->id,
                'goods_description' => $line->lrGoodsRow?->goods_description !== null
                    ? (string) $line->lrGoodsRow->goods_description
                    : null,
                'rate_type' => $line->rate_type->value,
                'rate' => $this->freightPresentation->presentAmount($line->rate, $maySeeFreight),
                'freight_amount' => $this->freightPresentation->presentAmount($line->freight_amount, $maySeeFreight),
                'loading_weight' => $line->loading_weight,
                'is_fixed_amount' => $line->is_fixed_amount,
            ])->values()->all(),
            'tds_snapshot' => $hire->tdsSnapshot ? [
                'applicable_rate' => number_format((float) $hire->tdsSnapshot->applicable_rate, 3, '.', ''),
                'rule_code' => $hire->tdsSnapshot->rule_code,
                'declaration_status' => (string) $hire->tdsSnapshot->declaration_status,
                'explanation' => (string) $hire->tdsSnapshot->explanation,
            ] : null,
            'created_at' => $hire->created_at?->format('d-m-Y H:i'),
        ];
    }

    private function canCancelLorryHire(User $actor, Company $company, Branch $branch): bool
    {
        if ($actor->id === $company->owner_user_id) {
            return true;
        }

        return $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::BROKER_LORRY_HIRE_CANCEL,
            $company,
            $branch,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     hire_number: string,
     *     status: string,
     *     lr_number: string|null,
     *     vehicle_registration: string|null,
     *     transporter_name: string|null,
     *     from_station_name: string|null,
     *     to_station_name: string|null,
     *     freight_total: string|null,
     *     outstanding_amount: string|null,
     *     created_at: string|null,
     *     urls: array{show: string}
     * }
     */
    private function listPayload(LorryHire $hire, bool $maySeeFreight): array
    {
        return [
            'id' => $hire->id,
            'hire_number' => $hire->hire_number,
            'status' => $hire->status->value,
            'lr_number' => $hire->lorryReceipt?->lr_number,
            'vehicle_registration' => $hire->vehicle?->registration_number,
            'transporter_name' => $hire->transporter?->name,
            'from_station_name' => $hire->lorryReceipt?->fromStation?->name,
            'to_station_name' => $hire->lorryReceipt?->toStation?->name,
            'freight_total' => $this->freightPresentation->presentAmount($hire->freight_total, $maySeeFreight),
            'outstanding_amount' => $this->freightPresentation->presentAmount($hire->outstanding_amount, $maySeeFreight),
            'created_at' => $hire->created_at?->format('d-m-Y'),
            'urls' => [
                'show' => route('branch.broker.lorry-hires.show', [$hire], absolute: false),
            ],
        ];
    }
}
