<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Delivery\BrokerDeliveryService;
use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\LorryHire\LorryHireCancellationService;
use App\Domains\Broker\LorryHire\LorryHireRevisionService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Broker\Presentation\LorryHireRegisterPresenter;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\Support\FreightRateTypeCatalog;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsLorryHireForm;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\LorryHireRevision;
use App\Models\LorryReceipt;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Broker Lorry Hire Inertia UI — Phase 6 Batch A foundation.
 */
class LorryHireController extends Controller
{
    use AuthorizesBranchPanel;
    use PresentsLorryHireForm;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly LorryHireService $lorryHires,
        private readonly LorryHireCancellationService $cancellations,
        private readonly LorryHireRevisionService $revisions,
        private readonly LorryHireCalculationService $calculations,
        private readonly BrokerAdjustmentService $adjustments,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly LorryHireRegisterPresenter $registerRows,
        private readonly BrokerDeliveryService $deliveries,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LORRY_HIRE_VIEW);

        $timezone = $this->companyTimezone($company);
        $defaultEnd = CarbonImmutable::now($timezone)->toDateString();
        $defaultStart = CarbonImmutable::now($timezone)->subDays(15)->toDateString();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'lr_number' => ['nullable', 'string', 'max:60'],
            'customer_party_id' => ['nullable', 'uuid'],
            'transporter_party_id' => ['nullable', 'uuid'],
            'vehicle_id' => ['nullable', 'uuid'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(LorryHireStatus::class)],
            'pending_memo' => ['nullable', 'boolean'],
            'payable_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $startDate = $request->filled('start_date')
            ? (string) $request->input('start_date')
            : $defaultStart;
        $endDate = $request->filled('end_date')
            ? (string) $request->input('end_date')
            : $defaultEnd;

        $page = $this->lorryHires->search(
            company: $company,
            branch: $branch,
            status: $validated['status'] ?? null,
            perPage: min(100, max(1, (int) ($validated['per_page'] ?? 15))),
            pendingMemo: $request->boolean('pending_memo'),
            search: $validated['search'] ?? null,
            lrNumber: $validated['lr_number'] ?? null,
            customerPartyId: $validated['customer_party_id'] ?? null,
            transporterPartyId: $validated['transporter_party_id'] ?? null,
            vehicleId: $validated['vehicle_id'] ?? null,
            startDate: $startDate,
            endDate: $endDate,
            payableOnly: $request->boolean('payable_only'),
        );

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        $canCreateVoucher = $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::BROKER_VOUCHER_CREATE,
            $company,
            $branch,
        );
        $canBulkVoucher = $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::BROKER_VOUCHER_BULK,
            $company,
            $branch,
        );
        $canAssignMemo = $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::BROKER_DELIVERY_MANAGE,
            $company,
            $branch,
        );
        $canEditHires = $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::BROKER_LORRY_HIRE_EDIT,
            $company,
            $branch,
        );

        return Inertia::render('broker/lorry-hires/index', [
            'filters' => [
                'search' => $request->string('search')->toString(),
                'lr_number' => $request->string('lr_number')->toString(),
                'customer_party_id' => $request->string('customer_party_id')->toString(),
                'transporter_party_id' => $request->string('transporter_party_id')->toString(),
                'vehicle_id' => $request->string('vehicle_id')->toString(),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $request->string('status')->toString(),
                'pending_memo' => $request->boolean('pending_memo'),
                'payable_only' => $request->boolean('payable_only'),
            ],
            'filterOptions' => $this->lorryHires->registerFilterOptions($company, $branch),
            'permissions' => array_merge(
                $this->lorryHirePermissions($actor, $company, $branch, $maySeeFreight),
                [
                    'can_create_voucher' => $canCreateVoucher,
                    'can_bulk_voucher' => $canBulkVoucher,
                    'can_assign_memo' => $canAssignMemo,
                ],
            ),
            'statusOptions' => collect(LorryHireStatus::cases())->map(fn (LorryHireStatus $status): array => [
                'value' => $status->value,
                'label' => match ($status) {
                    LorryHireStatus::Pending => 'Pending',
                    LorryHireStatus::Active => 'Active',
                    LorryHireStatus::Cancelled => 'Cancelled',
                },
            ])->values()->all(),
            'lorryHires' => [
                'data' => collect($page->items())->map(
                    fn (LorryHire $hire): array => $this->registerRows->row(
                        $hire,
                        $maySeeFreight,
                        $canCreateVoucher,
                        $canAssignMemo,
                        $canEditHires,
                    ),
                )->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.broker.lorry-hires.index', absolute: false),
                ...$this->voucherPaymentUrls($canCreateVoucher),
                'memo_context' => $canAssignMemo ? route('branch.broker.deliveries.memo-context', absolute: false) : null,
                'memo_store' => $canAssignMemo ? route('branch.broker.deliveries.memo', absolute: false) : null,
            ],
        ]);
    }

    /**
     * @return array{payment_context: string|null, voucher_store: string|null}
     */
    private function voucherPaymentUrls(bool $canCreateVoucher): array
    {
        return [
            'payment_context' => $canCreateVoucher ? route('branch.broker.vouchers.payment-context', absolute: false) : null,
            'voucher_store' => $canCreateVoucher ? route('branch.broker.vouchers.store', absolute: false) : null,
        ];
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

        $payee = null;
        $payeeBlockedReason = null;
        try {
            $payee = $this->lorryHires->inheritedPayee($lorryReceipt, $company, $branch);
        } catch (ValidationException $e) {
            $payeeBlockedReason = $e->errors()['payee'][0] ?? 'The Bilty Payee cannot be used for a Lorry Hire.';
        }

        $canCreateVoucher = $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::BROKER_VOUCHER_CREATE,
            $company,
            $branch,
        );
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('broker/lorry-hires/create', [
            ...$this->lorryHireFormProps($lorryReceipt),
            'customer_freight' => $this->customerFreightContext($lorryReceipt, $maySeeFreight),
            'payee' => $payee !== null ? ['id' => $payee->id, 'name' => $payee->name] : null,
            'payeeBlockedReason' => $payeeBlockedReason,
            'tdsPreview' => $payee !== null ? $this->lorryHires->tdsPreviewForPayee($payee) : null,
            'defaults' => [
                'hire_date' => $this->lorryHires->defaultHireDate($company),
                'rate_type' => FreightRateTypeCatalog::default()->value,
            ],
            'permissions' => [
                'can_create_voucher' => $canCreateVoucher,
                'can_view_customer_freight' => $maySeeFreight,
            ],
            'urls' => [
                'store' => route('branch.broker.lorry-hires.store-from-lr', [$lorryReceipt], absolute: false),
                'lr_show' => route('branch.lorry-receipts.show', [$lorryReceipt], absolute: false),
                'lr_edit' => route('branch.lorry-receipts.edit', [$lorryReceipt], absolute: false),
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

        // Repeat submission for a Bilty that already has an active hire → show the existing hire.
        $redirectToVoucher = $request->boolean('redirect_to_voucher')
            && $this->authorizer->allowsCustomer(
                $actor,
                PermissionCodes::BROKER_VOUCHER_CREATE,
                $company,
                $branch,
            );

        $existing = $this->lorryHires->lorryHireForLorryReceipt($lorryReceipt);
        if ($existing !== null) {
            return $this->redirectAfterHireCreate($existing, $redirectToVoucher, existing: true);
        }

        // Payee and loading weight are inherited from the Bilty; any client value is not accepted.
        $validated = $request->validate([
            ...$this->lorryHireFormRules(),
            'tds_override_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tds_override_remark' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $hire = $this->lorryHires->createFromLorryReceipt($lorryReceipt, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerConflictException $e) {
            // Concurrent duplicate submit: the competing request created the hire first.
            $existing = $this->lorryHires->lorryHireForLorryReceipt($lorryReceipt);
            if ($existing !== null) {
                return $this->redirectAfterHireCreate($existing, $redirectToVoucher, existing: true);
            }

            throw ValidationException::withMessages(['goods_lines' => $e->getMessage()]);
        } catch (BrokerDomainException $e) {
            throw ValidationException::withMessages(['goods_lines' => $e->getMessage()]);
        }

        return $this->redirectAfterHireCreate($hire, $redirectToVoucher, existing: false);
    }

    private function redirectAfterHireCreate(
        LorryHire $hire,
        bool $redirectToVoucher,
        bool $existing,
    ): RedirectResponse {
        if ($redirectToVoucher) {
            return redirect()
                ->route('branch.broker.lorry-hires.show', [$hire, 'pay' => 1])
                ->with(
                    'success',
                    $existing
                        ? 'Lorry Hire already exists. Continue with voucher payment.'
                        : 'Lorry Hire created. Continue with voucher payment.',
                );
        }

        return redirect()
            ->route('branch.broker.lorry-hires.show', [$hire])
            ->with(
                'success',
                $existing
                    ? 'Lorry Hire already exists for this Bilty.'
                    : 'Broker Lorry Hire created.',
            );
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
        $canCreateVoucher = $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_CREATE, $company, $branch);
        $canEdit = $this->revisions->canEdit($actor, $company, $branch, $lorryHire);
        $deliveryPanel = $this->deliveryPanelPayload($lorryHire);

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
            'fixed_adjustments' => $this->adjustments->snapshotFixedAdjustments($lorryHire),
            'delivery' => $deliveryPanel,
            'revisions' => $this->revisionHistoryPayload($lorryHire, $maySeeFreight),
            'permissions' => array_merge(
                $this->lorryHirePermissions($actor, $company, $branch, $maySeeFreight),
                $this->adjustmentDeliveryPermissions($actor, $company, $branch),
                [
                    'can_edit' => $canEdit,
                    'can_pay' => $canCreateVoucher
                        && $lorryHire->status !== LorryHireStatus::Cancelled
                        && bccomp($summary['remaining_payable'], '0', 2) === 1,
                ],
            ),
            'urls' => [
                'index' => route('branch.broker.lorry-hires.index', absolute: false),
                'lr_show' => route('branch.lorry-receipts.show', [$lorryHire->lorry_receipt_id], absolute: false),
                'adjustment_store' => route('branch.broker.lorry-hires.adjustments.store', [$lorryHire], absolute: false),
                'deliveries' => route('branch.broker.deliveries.index', absolute: false),
                ...$this->voucherPaymentUrls($canCreateVoucher),
                'cancel' => $canCancel
                    ? route('branch.broker.lorry-hires.cancel', [$lorryHire], absolute: false)
                    : null,
                'edit' => $canEdit
                    ? route('branch.broker.lorry-hires.edit', [$lorryHire], absolute: false)
                    : null,
                'print' => route('branch.broker.lorry-hires.print', [$lorryHire], absolute: false),
                'memo_print' => filled($deliveryPanel['memo_number'] ?? null)
                    ? route('branch.broker.lorry-hires.memo-print', [$lorryHire], absolute: false)
                    : null,
                'memo_update' => route('branch.broker.lorry-hires.memo-update', [$lorryHire], absolute: false),
            ],
        ]);
    }

    /**
     * @return list<array{
     *     id: string,
     *     created_at: string|null,
     *     created_by: string|null,
     *     remark: string,
     *     freight_before: string|null,
     *     freight_after: string|null,
     *     tds_before: string|null,
     *     tds_after: string|null,
     *     net_payable_before: string|null,
     *     net_payable_after: string|null
     * }>
     */
    private function revisionHistoryPayload(LorryHire $hire, bool $maySeeFreight): array
    {
        return array_values($hire->revisions()
            ->with('createdBy')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LorryHireRevision $revision): array => [
                'id' => $revision->id,
                'created_at' => $revision->created_at?->format('d-m-Y H:i'),
                'created_by' => $revision->createdBy?->name,
                'remark' => $revision->remark,
                'freight_before' => $this->freightPresentation->presentAmount($revision->before['freight_total'] ?? null, $maySeeFreight),
                'freight_after' => $this->freightPresentation->presentAmount($revision->after['freight_total'] ?? null, $maySeeFreight),
                'tds_before' => $this->freightPresentation->presentAmount($revision->before['tds_amount'] ?? null, $maySeeFreight),
                'tds_after' => $this->freightPresentation->presentAmount($revision->after['tds_amount'] ?? null, $maySeeFreight),
                'net_payable_before' => $this->freightPresentation->presentAmount($revision->before['net_payable'] ?? null, $maySeeFreight),
                'net_payable_after' => $this->freightPresentation->presentAmount($revision->after['net_payable'] ?? null, $maySeeFreight),
            ])
            ->all());
    }

    public function updateMemoNumber(
        Request $request,
        LorryHire $lorryHire,
        Company $company,
        Branch $branch,
    ): RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertLorryHire($lorryHire, $company, $branch);

        $validated = $request->validate([
            'memo_number' => ['required', 'string', 'max:64'],
            'remark' => ['required', 'string', 'max:2000'],
        ], [
            'remark.required' => 'Enter an audit remark for this Memo / POD correction.',
        ]);

        try {
            $this->deliveries->correctMemoNumber($lorryHire, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerConflictException $e) {
            throw ValidationException::withMessages(['memo_number' => $e->getMessage()]);
        } catch (BrokerDomainException $e) {
            throw ValidationException::withMessages(['memo_number' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.broker.lorry-hires.show', [$lorryHire])
            ->with('success', 'Memo / POD number updated.');
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
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Broker Lorry Hire operation.');
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
            'can_create_adjustment' => false,
            'can_view_adjustment' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_ADJUSTMENT_VIEW, $company, $branch),
            'can_manage_delivery' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_DELIVERY_MANAGE, $company, $branch),
            'can_correct_memo' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LORRY_HIRE_EDIT, $company, $branch)
                || $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_DELIVERY_MANAGE, $company, $branch),
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
     *     outstanding_amount: numeric-string,
     *     balance_due: numeric-string,
     *     negative_adjusted_amount: numeric-string,
     *     reserved_voucher_amount: numeric-string,
     *     remaining_payable: numeric-string
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
     *         calculated_freight_amount: string|null,
     *         custom_amount: string|null,
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
            'lr_date' => $lr?->lr_date?->format('d-m-Y'),
            'from_station_name' => $lr !== null ? $this->loadingStationLabel($lr) : null,
            'to_station_name' => $lr !== null ? $this->unloadingStationLabel($lr) : null,
            'loading_station' => $lr !== null ? $this->loadingStationLabel($lr) : null,
            'unloading_station' => $lr !== null ? $this->unloadingStationLabel($lr) : null,
            'billing_customer_name' => $lr?->billingCustomer?->name,
            'consignor_name' => $lr?->consignor?->name,
            'consignee_name' => $lr?->consignee?->name,
            'vehicle_registration' => $hire->vehicle?->registration_number,
            'transporter_name' => $hire->transporter?->name,
            'payee_type' => $lr?->intended_payee_type,
            'payee_type_label' => match ($lr?->intended_payee_type) {
                'owner' => 'Owner',
                'agency' => 'Agency',
                default => null,
            },
            'remarks' => $hire->remarks,
            'cancelled_at' => $hire->cancelled_at?->format('d-m-Y H:i'),
            'cancellation_remark' => $hire->cancellation_remark,
            'settle_outstanding' => $hire->settle_outstanding,
            'freight_total' => $this->freightPresentation->presentAmount($summary['freight_total'], $maySeeFreight),
            'tds_percent' => $hire->tds_percent,
            'tds_amount' => $this->freightPresentation->presentAmount($summary['tds_amount'], $maySeeFreight),
            'net_payable' => $this->freightPresentation->presentAmount($summary['net_payable'], $maySeeFreight),
            'outstanding_amount' => $this->freightPresentation->presentAmount($summary['outstanding_amount'], $maySeeFreight),
            'balance_due' => $this->freightPresentation->presentAmount($summary['balance_due'], $maySeeFreight),
            'negative_adjusted_amount' => $this->freightPresentation->presentAmount($summary['negative_adjusted_amount'], $maySeeFreight),
            'remaining_payable' => $this->freightPresentation->presentAmount($summary['remaining_payable'], $maySeeFreight),
            'reserved_voucher_amount' => $this->freightPresentation->presentAmount($summary['reserved_voucher_amount'], $maySeeFreight),
            'paid_voucher_amount' => $this->freightPresentation->presentAmount($summary['paid_voucher_amount'], $maySeeFreight),
            'in_progress_voucher_amount' => $this->freightPresentation->presentAmount($summary['in_progress_voucher_amount'], $maySeeFreight),
            'customer_freight_total' => $this->freightPresentation->presentAmount($lr?->freight_total, $maySeeFreight),
            'gross_freight_margin' => $this->grossFreightMargin(
                $maySeeFreight && $lr !== null ? (string) $lr->freight_total : null,
                $maySeeFreight ? (string) $summary['freight_total'] : null,
            ),
            'goods_lines' => $hire->goodsLines->map(function ($line) use ($maySeeFreight): array {
                $row = $line->lrGoodsRow;
                $description = $row?->goods_description;
                $typeName = $row?->goodsType?->name;

                return [
                    'id' => $line->id,
                    'goods_type_name' => $typeName,
                    'goods_description' => filled($description) ? (string) $description : null,
                    'goods_label' => filled($description)
                        ? (string) $description
                        : ($typeName ?? 'Goods'),
                    'rate_type' => $line->rate_type->value,
                    'rate_type_label' => $this->freightRateTypeLabel($line->rate_type),
                    'rate' => $this->freightPresentation->presentAmount($line->rate, $maySeeFreight),
                    'freight_amount' => $this->freightPresentation->presentAmount($line->freight_amount, $maySeeFreight),
                    'loading_weight' => $line->loading_weight,
                    'is_fixed_amount' => $line->is_fixed_amount,
                ];
            })->values()->all(),
            'custom_amounts' => $hire->customAmounts->map(fn ($row) => [
                'id' => $row->id,
                'description' => $row->description,
                'amount' => $this->freightPresentation->presentAmount($row->amount, $maySeeFreight),
            ])->values()->all(),
            'custom_amounts_total' => $this->freightPresentation->presentAmount(
                $this->calculations->customAmountsTotal($hire->customAmounts),
                $maySeeFreight,
            ),
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

    private function grossFreightMargin(?string $customerFreight, ?string $transporterFreight): ?string
    {
        if ($customerFreight === null || $customerFreight === '' || ! is_numeric($customerFreight)) {
            return null;
        }
        if ($transporterFreight === null || $transporterFreight === '' || ! is_numeric($transporterFreight)) {
            return null;
        }

        return number_format((float) bcsub($customerFreight, $transporterFreight, 2), 2, '.', '');
    }

    private function freightRateTypeLabel(FreightRateType $type): string
    {
        return FreightRateTypeCatalog::label($type);
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = $company->timezone ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Kolkata';
    }

    public function printMemo(
        Request $request,
        LorryHire $lorryHire,
        Company $company,
        Branch $branch,
    ): Response {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LORRY_HIRE_VIEW);
        $this->assertLorryHire($lorryHire, $company, $branch);

        $hire = $this->lorryHires->findForBranch($company, $branch, $lorryHire->id);
        $lr = $hire->lorryReceipt;
        $delivery = $lr?->delivery;

        if ($delivery === null || $delivery->memo_number === null) {
            abort(404, 'Memo is not assigned for this Bilty.');
        }

        return Inertia::render('broker/deliveries/memo-print', [
            'company' => [
                'name' => $company->name,
                'gstin' => $company->gstin,
            ],
            'branch' => [
                'name' => $branch->name,
            ],
            'memo' => [
                'memo_number' => $delivery->memo_number,
                'memo_date' => $delivery->delivery_date?->format('d-m-Y'),
                'pod_number' => $delivery->pod_number,
                'lr_number' => $lr?->lr_number,
                'lr_date' => $lr?->lr_date?->format('d-m-Y'),
                'customer_name' => $lr?->billingCustomer?->name,
                'vehicle_registration' => $hire->vehicle?->registration_number,
                'from_station' => $lr !== null ? $this->loadingStationLabel($lr) : null,
                'to_station' => $lr !== null ? $this->unloadingStationLabel($lr) : null,
                'hire_number' => $hire->hire_number,
            ],
        ]);
    }

    public function print(
        Request $request,
        LorryHire $lorryHire,
        Company $company,
        Branch $branch,
    ): Response {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LORRY_HIRE_VIEW);
        $this->assertLorryHire($lorryHire, $company, $branch);

        $hire = $this->lorryHires->findForBranch($company, $branch, $lorryHire->id);
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $summary = $this->calculations->summarize($hire);

        return Inertia::render('broker/lorry-hires/print', [
            'company' => [
                'name' => $company->name,
                'gstin' => $company->gstin,
            ],
            'branch' => [
                'name' => $branch->name,
            ],
            'lorryHire' => $this->detailPayload($hire, $maySeeFreight, $summary),
            'sampleBanner' => 'Operational good hire statement — not a tax voucher.',
        ]);
    }
}
