<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\Presentation\TransporterVoucherRegisterPresenter;
use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Broker\Voucher\VoucherPaymentService;
use App\Domains\Broker\Voucher\VoucherPayoutExportService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
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
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransporterVoucherController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly TransporterVoucherService $vouchers,
        private readonly VoucherPaymentService $voucherPayments,
        private readonly LorryHireCalculationService $hireCalculations,
        private readonly TransporterBookBalanceService $bookBalances,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly VoucherPayoutExportService $payoutExport,
        private readonly TransporterVoucherRegisterPresenter $registerRows,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_VOUCHER_VIEW);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'lr_number' => ['nullable', 'string', 'max:60'],
            'hire_number' => ['nullable', 'string', 'max:60'],
            'truck_no' => ['nullable', 'string', 'max:40'],
            'transporter_party_id' => ['nullable', 'uuid'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(VoucherStatus::class)],
            'payment_mode' => ['nullable', Rule::enum(VoucherPaymentMode::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->vouchers->search(
            company: $company,
            branch: $branch,
            status: $validated['status'] ?? null,
            paymentMode: $validated['payment_mode'] ?? null,
            transporterPartyId: $validated['transporter_party_id'] ?? null,
            perPage: min(100, max(1, (int) ($validated['per_page'] ?? 25))),
            search: $validated['search'] ?? null,
            lrNumber: $validated['lr_number'] ?? null,
            hireNumber: $validated['hire_number'] ?? null,
            vehicleRegistration: $validated['truck_no'] ?? null,
            startDate: $validated['start_date'] ?? null,
            endDate: $validated['end_date'] ?? null,
        );

        return Inertia::render('broker/vouchers/index', [
            'filters' => [
                'search' => $request->string('search')->toString(),
                'lr_number' => $request->string('lr_number')->toString(),
                'hire_number' => $request->string('hire_number')->toString(),
                'truck_no' => $request->string('truck_no')->toString(),
                'transporter_party_id' => $request->string('transporter_party_id')->toString(),
                'start_date' => $request->string('start_date')->toString(),
                'end_date' => $request->string('end_date')->toString(),
                'status' => $request->string('status')->toString(),
                'payment_mode' => $request->string('payment_mode')->toString(),
            ],
            'filterOptions' => [
                'transporters' => $this->vouchers->registerTransporterOptions($company, $branch),
            ],
            'permissions' => $this->voucherPermissions($actor, $company, $branch, $maySeeFreight),
            'statusOptions' => $this->statusOptions(),
            'paymentModeOptions' => $this->paymentModeOptions(),
            'vouchers' => [
                'data' => collect($page->items())->map(
                    fn (TransporterVoucher $row): array => $this->registerRows->row($row, $maySeeFreight),
                )->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.broker.vouchers.index', absolute: false),
                'lorry_hires' => route('branch.broker.lorry-hires.index', absolute: false),
                'export_payout' => route('branch.broker.vouchers.export-payout', absolute: false),
            ],
        ]);
    }

    public function exportPayout(Request $request, Company $company, Branch $branch): StreamedResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(VoucherStatus::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        try {
            return $this->payoutExport->streamCsv($company, $branch, $actor, $validated);
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        }
    }

    /**
     * Voucher Payment modal facts for one or more Lorry Hires of the same Payee and Truck.
     */
    public function paymentContext(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_VOUCHER_CREATE);

        $validated = $request->validate([
            'hire_ids' => ['required', 'array', 'min:1', 'max:100'],
            'hire_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        try {
            $hires = $this->voucherPayments->payableHires($company, $branch, $validated['hire_ids']);
        } catch (BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['hire_ids' => $e->getMessage()]);
        }

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return response()->json($this->paymentContextPayload($actor, $company, $branch, $hires, $maySeeFreight));
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_VOUCHER_CREATE);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.lorry_hire_id' => ['required', 'uuid', 'distinct'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'payment_type' => ['required', Rule::enum(VoucherPaymentType::class)],
            'payment_mode' => ['required', Rule::enum(VoucherPaymentMode::class)],
            'voucher_date' => ['required', 'date'],
            'bank_cash_account_id' => ['nullable', 'uuid'],
            'pump_reference' => ['nullable', 'string', 'max:128', Rule::requiredIf(fn (): bool => $request->input('payment_mode') === VoucherPaymentMode::PaidToPump->value)],
            'remark' => ['nullable', 'string', 'max:2000'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ], [
            'lines.*.amount.required' => 'Enter the payment amount.',
            'lines.*.amount.min' => 'Payment amount must be greater than zero.',
            'pump_reference.required' => 'Enter the Pump Name for Paid to Pump.',
        ]);

        try {
            $created = $this->voucherPayments->record($company, $branch, $actor, $validated['lines'], [
                'payment_type' => $validated['payment_type'],
                'payment_mode' => $validated['payment_mode'],
                'voucher_date' => $validated['voucher_date'],
                'bank_cash_account_id' => $validated['bank_cash_account_id'] ?? null,
                'pump_reference' => $validated['pump_reference'] ?? null,
                'remark' => $validated['remark'] ?? null,
                'confirm_duplicate' => $request->boolean('confirm_duplicate'),
            ]);
        } catch (BrokerAuthorizationException|BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $count = count($created);

        return back()->with('success', $count === 1 ? 'Voucher payment recorded.' : $count.' voucher payments recorded.');
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
                'print' => route('branch.broker.vouchers.print', [$voucher], absolute: false),
            ],
        ]);
    }

    public function print(
        Request $request,
        TransporterVoucher $voucher,
        Company $company,
        Branch $branch,
    ): Response {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_VOUCHER_VIEW);
        $this->assertVoucher($voucher, $company, $branch);

        $voucher = $this->vouchers->findForBranch($company, $branch, $voucher->id);
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('broker/vouchers/print', [
            'company' => [
                'name' => $company->name,
                'gstin' => $company->gstin,
            ],
            'branch' => [
                'name' => $branch->name,
            ],
            'voucher' => $this->detailPayload($voucher, $maySeeFreight),
            'sampleBanner' => 'Voucher payment receipt — retain for transporter records.',
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

    private function assertVoucher(TransporterVoucher $voucher, Company $company, Branch $branch): void
    {
        if ($voucher->company_id !== $company->id || $voucher->branch_id !== $branch->id || $voucher->trashed()) {
            abort(404);
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Broker voucher operation.');
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
        return $this->enumOptions(VoucherPaymentMode::cases());
    }

    /**
     * @param  list<VoucherPaymentMode>|list<VoucherPaymentType>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(fn (VoucherPaymentMode|VoucherPaymentType $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], $cases);
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
     * @param  Collection<string, LorryHire>  $hires
     * @return array<string, mixed>
     */
    private function paymentContextPayload(
        User $actor,
        Company $company,
        Branch $branch,
        Collection $hires,
        bool $maySeeFreight,
    ): array {
        $first = $hires->first();
        $transporter = $first?->transporter;
        $amount = fn (mixed $value): ?string => $this->freightPresentation->presentAmount($value, $maySeeFreight);

        return [
            'payee' => [
                'name' => $transporter?->name,
                'mobile' => $transporter?->contact_number,
                'short_name' => $transporter?->short_name,
                'beneficiary_id' => $transporter?->bank_beneficiary_id,
                'tds_percent' => $first?->tds_percent,
            ],
            'vehicle_registration' => $first?->vehicle?->registration_number,
            'book_balance' => $amount($this->bookBalances->availableBalance($company, $branch, (string) $first?->transporter_party_id)),
            'hires' => $hires->values()->map(function (LorryHire $hire) use ($amount): array {
                $summary = $this->hireCalculations->summarize($hire);

                return [
                    'id' => $hire->id,
                    'hire_number' => $hire->hire_number,
                    'lr_number' => $hire->lorryReceipt?->lr_number,
                    'freight_total' => $amount($summary['freight_total']),
                    'paid_amount' => $amount($hire->paid_amount ?? '0'),
                    'reserved_amount' => $amount($summary['reserved_voucher_amount']),
                    'remaining_payable' => $amount($summary['remaining_payable']),
                    'shortage_damage_amount' => $amount($hire->adjustments
                        ->filter(fn ($row): bool => in_array($row->type, [BrokerAdjustmentType::Shortage, BrokerAdjustmentType::Damage], true))
                        ->sum(fn ($row): float => (float) $row->amount)),
                ];
            })->all(),
            'previous_payments' => $hires
                ->flatMap(fn (LorryHire $hire) => $hire->vouchers->map(fn (TransporterVoucher $voucher): array => [
                    'id' => $voucher->id,
                    'voucher_number' => $voucher->voucher_number,
                    'hire_number' => $hire->hire_number,
                    'voucher_date' => $voucher->voucher_date?->format('d-m-Y'),
                    'payment_type' => $voucher->payment_type->label(),
                    'payment_mode' => $voucher->payment_mode?->label(),
                    'amount' => $amount($voucher->amount),
                    'status' => $voucher->status->value,
                ]))
                ->sortByDesc('voucher_date')
                ->values()
                ->all(),
            'payment_type_options' => $this->enumOptions(VoucherPaymentType::cases()),
            'payment_mode_options' => $this->enumOptions(VoucherPaymentMode::cases()),
            'bank_cash_accounts' => BankCashAccount::query()
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
            'today' => $this->vouchers->defaultVoucherDate($company),
            'bank_cash_store_url' => route('branch.bank-cash.store', absolute: false),
            'permissions' => [
                'can_bulk' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_BULK, $company, $branch),
                'can_view_freight' => $maySeeFreight,
                'can_create_bank_cash' => $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer)['can_create'],
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
                'balance_due' => $this->freightPresentation->presentAmount($summary['balance_due'], $maySeeFreight),
                'negative_adjusted_amount' => $this->freightPresentation->presentAmount($summary['negative_adjusted_amount'], $maySeeFreight),
                'remaining_payable' => $this->freightPresentation->presentAmount($summary['remaining_payable'], $maySeeFreight),
                'reserved_voucher_amount' => $this->freightPresentation->presentAmount($summary['reserved_voucher_amount'], $maySeeFreight),
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
