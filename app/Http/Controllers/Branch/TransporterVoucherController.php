<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\Voucher\BulkVoucherService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\TransporterVoucher;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransporterVoucherController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly TransporterVoucherService $vouchers,
        private readonly BulkVoucherService $bulkVouchers,
        private readonly LorryHireCalculationService $hireCalculations,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_VOUCHER_VIEW);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        $page = $this->vouchers->search(
            company: $company,
            branch: $branch,
            status: $request->string('status')->toString() ?: null,
            paymentMode: $request->string('payment_mode')->toString() ?: null,
            perPage: min(100, max(1, (int) $request->input('per_page', 25))),
        );

        return Inertia::render('broker/vouchers/index', [
            'filters' => [
                'status' => $request->string('status')->toString(),
                'payment_mode' => $request->string('payment_mode')->toString(),
            ],
            'permissions' => $this->voucherPermissions($actor, $company, $branch, $maySeeFreight),
            'statusOptions' => $this->statusOptions(),
            'paymentModeOptions' => $this->paymentModeOptions(),
            'vouchers' => [
                'data' => collect($page->items())->map(fn (TransporterVoucher $row): array => $this->listPayload($row, $maySeeFreight))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.broker.vouchers.index', absolute: false),
                'create' => route('branch.broker.vouchers.create', absolute: false),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_VOUCHER_CREATE);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        $selectedHireId = $request->string('lorry_hire_id')->toString() ?: null;

        $hires = LorryHire::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with([
                'lorryReceipt.billingCustomer',
                'lorryReceipt.consignor',
                'lorryReceipt.consignee',
                'vehicle',
                'transporter',
            ])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        if ($selectedHireId !== null && ! $hires->contains(fn (LorryHire $hire): bool => $hire->id === $selectedHireId)) {
            $selectedHire = LorryHire::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->whereNull('deleted_at')
                ->whereKey($selectedHireId)
                ->with([
                    'lorryReceipt.billingCustomer',
                    'lorryReceipt.consignor',
                    'lorryReceipt.consignee',
                    'vehicle',
                    'transporter',
                ])
                ->first();
            if ($selectedHire !== null) {
                $hires = $hires->prepend($selectedHire)->unique('id')->values();
            } else {
                $selectedHireId = null;
            }
        }

        return Inertia::render('broker/vouchers/create', [
            'selectedLorryHireId' => $selectedHireId,
            'eligibleLorryHires' => $hires->map(function (LorryHire $hire) use ($maySeeFreight): array {
                $allocatable = $this->vouchers->allocatableAmount($hire);
                $summary = $this->hireCalculations->summarize($hire);

                return [
                    'id' => $hire->id,
                    'hire_number' => $hire->hire_number,
                    'lr_number' => $hire->lorryReceipt?->lr_number,
                    'transporter_name' => $hire->transporter?->name,
                    'vehicle_registration' => $hire->vehicle?->registration_number,
                    'billing_customer_name' => $hire->lorryReceipt?->billingCustomer?->name,
                    'consignor_name' => $hire->lorryReceipt?->consignor?->name,
                    'consignee_name' => $hire->lorryReceipt?->consignee?->name,
                    'outstanding_amount' => $this->freightPresentation->presentAmount($summary['outstanding_amount'], $maySeeFreight),
                    'allocatable_amount' => $this->freightPresentation->presentAmount($allocatable, $maySeeFreight),
                    'net_payable' => $this->freightPresentation->presentAmount($summary['net_payable'], $maySeeFreight),
                    'freight_total' => $this->freightPresentation->presentAmount($summary['freight_total'], $maySeeFreight),
                    'tds_amount' => $this->freightPresentation->presentAmount($summary['tds_amount'], $maySeeFreight),
                ];
            })->values()->all(),
            'paymentTypeOptions' => $this->paymentTypeOptions(),
            'paymentModeOptions' => $this->paymentModeOptions(),
            'bankCashAccounts' => BankCashAccount::query()
                ->where('company_id', $company->id)
                ->where(function ($query) use ($branch): void {
                    $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
                })
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (BankCashAccount $account): array => ['id' => $account->id, 'name' => $account->name])
                ->values()
                ->all(),
            'permissions' => $this->voucherPermissions($actor, $company, $branch, $maySeeFreight),
            'urls' => [
                'store' => route('branch.broker.vouchers.store', absolute: false),
                'index' => route('branch.broker.vouchers.index', absolute: false),
                'bulk_preview' => route('branch.broker.vouchers.bulk.preview', absolute: false),
                'bulk_store' => route('branch.broker.vouchers.bulk.store', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $validated = $request->validate([
            'lorry_hire_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_type' => ['required', Rule::enum(VoucherPaymentType::class)],
            'payment_mode' => ['required', Rule::enum(VoucherPaymentMode::class)],
            'bank_cash_account_id' => ['nullable', 'uuid'],
            'cash_reference' => ['nullable', 'string', 'max:128'],
            'pump_reference' => ['nullable', 'string', 'max:128'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        $hire = LorryHire::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereKey($validated['lorry_hire_id'])
            ->whereNull('deleted_at')
            ->firstOrFail();

        try {
            $voucher = $this->vouchers->createForLorryHire($hire, $actor, $validated);
        } catch (BrokerAuthorizationException|BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.broker.vouchers.show', [$voucher])
            ->with('success', 'Transporter voucher created.');
    }

    public function show(Request $request, TransporterVoucher $voucher, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_VOUCHER_VIEW);
        $this->assertVoucher($voucher, $company, $branch);

        $voucher = $this->vouchers->findForBranch($company, $branch, $voucher->id);
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('broker/vouchers/show', [
            'voucher' => $this->detailPayload($voucher, $maySeeFreight),
            'permissions' => $this->voucherPermissions($actor, $company, $branch, $maySeeFreight),
            'statusOptions' => $this->statusOptions(),
            'nextStatus' => $this->nextStatus($voucher->status),
            'urls' => [
                'index' => route('branch.broker.vouchers.index', absolute: false),
                'lorry_hire_show' => route('branch.broker.lorry-hires.show', [$voucher->lorry_hire_id], absolute: false),
                'status' => route('branch.broker.vouchers.status', [$voucher], absolute: false),
            ],
        ]);
    }

    public function updateStatus(Request $request, TransporterVoucher $voucher, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVoucher($voucher, $company, $branch);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(VoucherStatus::class)],
        ]);

        try {
            $this->vouchers->transitionStatus(
                $voucher,
                $actor,
                VoucherStatus::from($validated['status']),
            );
        } catch (BrokerAuthorizationException|BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.broker.vouchers.show', [$voucher])
            ->with('success', 'Voucher status updated.');
    }

    public function bulkPreview(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.lorry_hire_id' => ['required', 'uuid'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payment_type' => ['required', Rule::enum(VoucherPaymentType::class)],
            'payment_mode' => ['required', Rule::enum(VoucherPaymentMode::class)],
        ]);

        try {
            return response()->json($this->bulkVouchers->preview(
                $company,
                $branch,
                $actor,
                $validated['lines'],
                $validated,
            ));
        } catch (BrokerAuthorizationException|BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }
    }

    public function bulkStore(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.lorry_hire_id' => ['required', 'uuid'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payment_type' => ['required', Rule::enum(VoucherPaymentType::class)],
            'payment_mode' => ['required', Rule::enum(VoucherPaymentMode::class)],
            'bank_cash_account_id' => ['nullable', 'uuid'],
            'cash_reference' => ['nullable', 'string', 'max:128'],
            'pump_reference' => ['nullable', 'string', 'max:128'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $created = $this->bulkVouchers->createBulk(
                $company,
                $branch,
                $actor,
                $validated['lines'],
                $validated,
            );
        } catch (BrokerAuthorizationException|BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $first = $created[0] ?? null;

        return redirect()
            ->route('branch.broker.vouchers.show', [$first])
            ->with('success', count($created).' transporter vouchers created.');
    }

    private function assertVoucher(TransporterVoucher $voucher, Company $company, Branch $branch): void
    {
        if ($voucher->company_id !== $company->id || $voucher->branch_id !== $branch->id || $voucher->trashed()) {
            abort(404);
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        $this->assertBrokerModule($company);

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Broker voucher operation.');
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
     *     can_view: bool,
     *     can_create: bool,
     *     can_edit: bool,
     *     can_status: bool,
     *     can_bulk: bool,
     *     can_view_freight: bool
     * }
     */
    private function voucherPermissions(User $actor, Company $company, Branch $branch, bool $maySeeFreight): array
    {
        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_VIEW, $company, $branch),
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_CREATE, $company, $branch),
            'can_edit' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_EDIT, $company, $branch),
            'can_status' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_STATUS, $company, $branch),
            'can_bulk' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_BULK, $company, $branch),
            'can_view_freight' => $maySeeFreight,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_values(collect(VoucherStatus::cases())->map(fn (VoucherStatus $status): array => [
            'value' => $status->value,
            'label' => match ($status) {
                VoucherStatus::Pending => 'Pending',
                VoucherStatus::InProgress => 'In Progress',
                VoucherStatus::Paid => 'Paid',
            },
        ])->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function paymentModeOptions(): array
    {
        return array_values(collect(VoucherPaymentMode::cases())->map(fn (VoucherPaymentMode $mode): array => [
            'value' => $mode->value,
            'label' => match ($mode) {
                VoucherPaymentMode::Bank => 'Bank',
                VoucherPaymentMode::Cash => 'Cash',
                VoucherPaymentMode::PaidToPump => 'Paid to Pump',
                VoucherPaymentMode::AdjustInAdvance => 'Adjust in Advance',
            },
        ])->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function paymentTypeOptions(): array
    {
        return array_values(collect(VoucherPaymentType::cases())->map(fn (VoucherPaymentType $type): array => [
            'value' => $type->value,
            'label' => match ($type) {
                VoucherPaymentType::Advance => 'Advance',
                VoucherPaymentType::Final => 'Final',
                VoucherPaymentType::Partial => 'Partial',
            },
        ])->all());
    }

    private function nextStatus(VoucherStatus $current): ?string
    {
        return match ($current) {
            VoucherStatus::Pending => VoucherStatus::InProgress->value,
            VoucherStatus::InProgress => VoucherStatus::Paid->value,
            VoucherStatus::Paid => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(TransporterVoucher $voucher, bool $maySeeFreight): array
    {
        $hire = $voucher->lorryHire;

        return [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            'status' => $voucher->status->value,
            'payment_mode' => $voucher->payment_mode?->value,
            'payment_type' => $voucher->payment_type->value,
            'amount' => $this->freightPresentation->presentAmount($voucher->amount, $maySeeFreight),
            'hire_number' => $hire?->hire_number,
            'lr_number' => $hire?->lorryReceipt?->lr_number,
            'transporter_name' => $hire?->transporter?->name,
            'vehicle_registration' => $hire?->vehicle?->registration_number,
            'created_at' => $voucher->created_at?->format('d-m-Y H:i'),
            'urls' => [
                'show' => route('branch.broker.vouchers.show', [$voucher], absolute: false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(TransporterVoucher $voucher, bool $maySeeFreight): array
    {
        $hire = $voucher->lorryHire;
        $summary = $hire !== null ? $this->hireCalculations->summarize($hire) : null;
        $payeeName = $voucher->transporter?->name;
        if ($payeeName === null && $hire !== null) {
            $payeeName = $hire->transporter?->name;
        }

        return [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            'status' => $voucher->status->value,
            'payment_mode' => $voucher->payment_mode?->value,
            'payment_type' => $voucher->payment_type->value,
            'amount' => $this->freightPresentation->presentAmount($voucher->amount, $maySeeFreight),
            'voucher_date' => $voucher->voucher_date?->format('d-m-Y'),
            'bank_cash_account_name' => $voucher->bankCashAccount?->name,
            'cash_reference' => $voucher->cash_reference,
            'pump_reference' => $voucher->pump_reference,
            'remark' => $voucher->remark,
            'payee_name' => $payeeName,
            'created_at' => $voucher->created_at?->format('d-m-Y H:i'),
            'lorry_hire' => $hire !== null && $summary !== null ? [
                'id' => $hire->id,
                'hire_number' => $hire->hire_number,
                'lr_number' => $hire->lorryReceipt?->lr_number,
                'transporter_name' => $hire->transporter?->name,
                'vehicle_registration' => $hire->vehicle?->registration_number,
                'freight_total' => $this->freightPresentation->presentAmount($summary['freight_total'], $maySeeFreight),
                'tds_amount' => $this->freightPresentation->presentAmount($summary['tds_amount'], $maySeeFreight),
                'tds_percent' => $hire->tds_percent
                    ?? ($hire->tdsSnapshot !== null
                        ? number_format((float) $hire->tdsSnapshot->applicable_rate, 3, '.', '')
                        : null),
                'net_payable' => $this->freightPresentation->presentAmount($summary['net_payable'], $maySeeFreight),
                'outstanding_amount' => $this->freightPresentation->presentAmount($summary['outstanding_amount'], $maySeeFreight),
                'paid_amount' => $this->freightPresentation->presentAmount($hire->paid_amount, $maySeeFreight),
                'adjustments' => $hire->relationLoaded('adjustments')
                    ? $hire->adjustments->map(fn ($row): array => [
                        'type' => $row->type->value,
                        'amount' => $this->freightPresentation->presentAmount($row->amount, $maySeeFreight),
                        'reason' => $row->reason,
                    ])->values()->all()
                    : [],
                'voucher_history' => $hire->relationLoaded('vouchers')
                    ? $hire->vouchers->map(fn (TransporterVoucher $row): array => [
                        'id' => $row->id,
                        'voucher_number' => $row->voucher_number,
                        'payment_type' => $row->payment_type->value,
                        'amount' => $this->freightPresentation->presentAmount($row->amount, $maySeeFreight),
                        'status' => $row->status->value,
                        'is_current' => $row->id === $voucher->id,
                    ])->values()->all()
                    : [],
            ] : null,
        ];
    }
}
