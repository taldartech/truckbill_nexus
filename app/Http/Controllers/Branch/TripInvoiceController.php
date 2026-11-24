<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceChargeType;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceParticularsMode;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceStatus;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceAuthorizationException;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceConflictException;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceDomainException;
use App\Domains\OwnFleet\Invoice\TripInvoicePaymentService;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BrokerInvoicePayment;
use App\Models\Company;
use App\Models\PaymentMode;
use App\Models\TripInvoice;
use App\Models\TripInvoiceCharge;
use App\Models\TripInvoicePayment;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TripInvoiceController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly TripInvoiceService $invoices,
        private readonly TripInvoicePaymentService $payments,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_VIEW);

        return redirect()->route('branch.invoices.index', array_merge(
            $request->query(),
            ['source' => 'own_fleet'],
        ));
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_CREATE);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        $preselectedLrId = $request->string('lorry_receipt_id')->toString() ?: null;

        return Inertia::render('branch/trip-invoices/create', [
            'preselectedLrId' => $preselectedLrId,
            'eligibleReceipts' => $this->invoices->listInvoiceEligibleLorryReceipts($company, $branch)
                ->map(fn ($lr) => [
                    'id' => $lr->id,
                    'lr_number' => $lr->lr_number,
                    'freight_total' => $this->freightPresentation->presentAmount($lr->freight_total, $maySeeFreight),
                    'billing_customer_name' => $lr->billingCustomer?->name,
                    'billing_customer_party_id' => $lr->billing_customer_party_id,
                    'lr_date' => $lr->lr_date?->format('d-m-Y'),
                ]),
            'chargeTypeOptions' => $this->chargeOptions(),
            'particularsOptions' => $this->particularsOptions(),
            'permissions' => array_merge(
                $this->invoicePermissions($actor, $company, $branch),
                ['can_view_freight' => $maySeeFreight],
            ),
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function consolidate(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_CREATE);

        $billingCustomerId = $request->string('billing_customer_party_id')->toString() ?: null;

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('branch/trip-invoices/consolidate', [
            'eligibleReceipts' => $this->invoices->listInvoiceEligibleLorryReceipts($company, $branch, $billingCustomerId)
                ->map(fn ($lr) => [
                    'id' => $lr->id,
                    'lr_number' => $lr->lr_number,
                    'freight_total' => $this->freightPresentation->presentAmount($lr->freight_total, $maySeeFreight),
                    'billing_customer_name' => $lr->billingCustomer?->name,
                    'billing_customer_party_id' => $lr->billing_customer_party_id,
                    'lr_date' => $lr->lr_date?->format('d-m-Y'),
                ]),
            'chargeTypeOptions' => $this->chargeOptions(),
            'particularsOptions' => $this->particularsOptions(),
            'filters' => ['billing_customer_party_id' => $billingCustomerId],
            'permissions' => array_merge(
                $this->invoicePermissions($actor, $company, $branch),
                ['can_view_freight' => $maySeeFreight],
            ),
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function storeIndividual(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'lorry_receipt_id' => ['required', 'uuid'],
            'invoice_date' => ['nullable', 'date'],
            'particulars_mode' => ['nullable', 'string'],
            'number_mode' => ['nullable', 'in:auto,manual'],
            'manual_number' => ['nullable', 'string', 'max:64'],
            'charges' => ['nullable', 'array'],
            'charges.*.charge_type' => ['required_with:charges', 'string'],
            'charges.*.description' => ['required_with:charges', 'string', 'max:255'],
            'charges.*.amount' => ['required_with:charges', 'numeric', 'min:0.01'],
        ]);

        try {
            $invoice = $this->invoices->createIndividual($company, $branch, $actor, (string) $validated['lorry_receipt_id'], $validated);
        } catch (TripInvoiceAuthorizationException|TripInvoiceConflictException|TripInvoiceDomainException|ValidationException $e) {
            throw $e;
        }

        return redirect()->route('branch.trip-invoices.show', [$invoice]);
    }

    public function storeConsolidated(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'lorry_receipt_ids' => ['required', 'array', 'min:2'],
            'lorry_receipt_ids.*' => ['uuid'],
            'invoice_date' => ['nullable', 'date'],
            'particulars_mode' => ['nullable', 'string'],
            'number_mode' => ['nullable', 'in:auto,manual'],
            'manual_number' => ['nullable', 'string', 'max:64'],
            'charges' => ['nullable', 'array'],
            'charges.*.charge_type' => ['required_with:charges', 'string'],
            'charges.*.description' => ['required_with:charges', 'string', 'max:255'],
            'charges.*.amount' => ['required_with:charges', 'numeric', 'min:0.01'],
        ]);

        $invoice = $this->invoices->createConsolidated($company, $branch, $actor, $validated['lorry_receipt_ids'], $validated);

        return redirect()->route('branch.trip-invoices.show', [$invoice]);
    }

    public function show(Request $request, TripInvoice $tripInvoice, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_VIEW);

        $tripInvoice->load(['lines.lorryReceipt', 'charges', 'payments.paymentMode', 'billingCustomer']);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('branch/trip-invoices/show', [
            'invoice' => $this->presentInvoiceDetail($tripInvoice, $maySeeFreight),
            'permissions' => array_merge(
                $this->invoicePermissions($actor, $company, $branch, $tripInvoice),
                $this->paymentPermissions($actor, $company, $branch),
                ['can_view_freight' => $maySeeFreight],
            ),
            'paymentModes' => PaymentMode::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function print(Request $request, TripInvoice $tripInvoice, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_PRINT);

        $tripInvoice->load(['lines.lorryReceipt', 'charges', 'billingCustomer', 'company']);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('branch/trip-invoices/print', [
            'invoice' => $this->presentInvoiceDetail($tripInvoice, $maySeeFreight),
            'company' => [
                'name' => $company->name,
                'gstin' => $company->gstin,
            ],
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function cancel(Request $request, TripInvoice $tripInvoice, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);

        $validated = $request->validate([
            'cancellation_remark' => ['required', 'string', 'max:2000'],
        ]);

        $this->invoices->cancel($tripInvoice, $actor, $validated['cancellation_remark']);

        return back()->with('flash.success', 'Invoice cancelled.');
    }

    public function editCharges(Request $request, TripInvoice $tripInvoice, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_EDIT);

        $tripInvoice->load(['charges']);

        if (! $this->invoicePermissions($actor, $company, $branch, $tripInvoice)['can_edit_charges']) {
            abort(403, 'Additional charges cannot be edited.');
        }

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('branch/trip-invoices/edit-charges', [
            'invoice' => [
                'id' => $tripInvoice->id,
                'invoice_number' => $tripInvoice->invoice_number,
                'charges' => $tripInvoice->charges->map(fn (TripInvoiceCharge $charge) => [
                    'charge_type' => $charge->charge_type->value,
                    'description' => $charge->description,
                    'amount' => $this->freightPresentation->presentAmount($charge->amount, $maySeeFreight),
                ]),
            ],
            'chargeTypeOptions' => $this->chargeOptions(),
            'permissions' => array_merge(
                $this->invoicePermissions($actor, $company, $branch, $tripInvoice),
                ['can_view_freight' => $maySeeFreight],
            ),
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function updateCharges(Request $request, TripInvoice $tripInvoice, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);

        $validated = $request->validate([
            'edit_remark' => ['required', 'string', 'max:2000'],
            'charges' => ['nullable', 'array'],
            'charges.*.charge_type' => ['required_with:charges', 'string'],
            'charges.*.description' => ['required_with:charges', 'string', 'max:255'],
            'charges.*.amount' => ['required_with:charges', 'numeric', 'min:0.01'],
        ]);

        $this->invoices->updateCharges($tripInvoice, $actor, $validated);

        return redirect()
            ->route('branch.trip-invoices.show', [$tripInvoice])
            ->with('flash.success', 'Additional charges updated.');
    }

    public function destroy(Request $request, TripInvoice $tripInvoice, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);

        $this->invoices->softDelete($tripInvoice, $actor);

        return redirect()->route('branch.trip-invoices.index')
            ->with('flash.success', 'Invoice deleted.');
    }

    public function storePayment(Request $request, TripInvoice $tripInvoice, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);

        $validated = $request->validate([
            'payment_date' => ['nullable', 'date'],
            'payment_mode_id' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:128'],
            'amount_received' => ['nullable', 'numeric', 'min:0'],
            'tds_amount' => ['nullable', 'numeric', 'min:0'],
            'shortage_damage' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:1000'],
            'fill_net' => ['nullable', 'boolean'],
        ]);

        $this->payments->record($tripInvoice, $actor, $validated);

        return back()->with('flash.success', 'Payment recorded.');
    }

    public function paymentsIndex(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PAYMENT_VIEW);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        $rows = collect();

        if ($this->authorizer->allowsCustomer($actor, PermissionCodes::PAYMENT_VIEW, $company, $branch)) {
            $tripRows = TripInvoicePayment::query()
                ->where('company_id', $company->id)
                ->whereHas('invoice', fn ($q) => $q->where('branch_id', $branch->id))
                ->with(['invoice', 'paymentMode'])
                ->orderByDesc('payment_date')
                ->limit(200)
                ->get()
                ->map(fn (TripInvoicePayment $payment): array => [
                    'id' => 'trip:'.$payment->id,
                    'payment_date' => $payment->payment_date->format('d-m-Y'),
                    'invoice_number' => $payment->invoice?->invoice_number,
                    'invoice_id' => $payment->trip_invoice_id,
                    'source' => 'own_fleet',
                    'source_label' => 'Own Fleet',
                    'amount_received' => $this->freightPresentation->presentAmount($payment->amount_received, $maySeeFreight),
                    'tds_amount' => $this->freightPresentation->presentAmount($payment->tds_amount, $maySeeFreight),
                    'settlement_amount' => $this->freightPresentation->presentAmount($payment->settlement_amount, $maySeeFreight),
                    'payment_mode' => $payment->paymentMode?->name,
                    'invoice_show_url' => $payment->invoice
                        ? route('branch.trip-invoices.show', [$payment->invoice], absolute: false)
                        : null,
                ]);

            $rows = $rows->merge($tripRows);
        }

        if ($this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_VIEW, $company, $branch)) {
            $brokerRows = BrokerInvoicePayment::query()
                ->where('company_id', $company->id)
                ->whereHas('brokerInvoice', fn ($q) => $q->where('branch_id', $branch->id))
                ->with(['brokerInvoice'])
                ->orderByDesc('payment_date')
                ->limit(200)
                ->get()
                ->map(fn (BrokerInvoicePayment $payment): array => [
                    'id' => 'broker:'.$payment->id,
                    'payment_date' => $payment->payment_date?->format('d-m-Y'),
                    'invoice_number' => $payment->brokerInvoice?->invoice_number,
                    'invoice_id' => $payment->broker_invoice_id,
                    'source' => 'broker',
                    'source_label' => 'Broker',
                    'amount_received' => $this->freightPresentation->presentAmount($payment->amount_received, $maySeeFreight),
                    'tds_amount' => $this->freightPresentation->presentAmount($payment->tds_amount, $maySeeFreight),
                    'settlement_amount' => $this->freightPresentation->presentAmount(
                        (string) ((float) $payment->amount_received - (float) $payment->tds_amount - (float) $payment->deduction_amount),
                        $maySeeFreight,
                    ),
                    'payment_mode' => $payment->payment_mode,
                    'invoice_show_url' => $payment->brokerInvoice
                        ? route('branch.broker.invoices.show', [$payment->brokerInvoice], absolute: false)
                        : null,
                ]);

            $rows = $rows->merge($brokerRows);
        }

        $sorted = $rows
            ->sortByDesc('payment_date')
            ->values()
            ->all();

        return Inertia::render('branch/payments/index', [
            'payments' => $sorted,
            'permissions' => array_merge(
                $this->paymentPermissions($actor, $company, $branch),
                ['can_view_freight' => $maySeeFreight],
            ),
        ]);
    }

    private function assertTripInvoiceTenant(TripInvoice $invoice, Company $company, Branch $branch): void
    {
        if ($invoice->company_id !== $company->id || $invoice->branch_id !== $branch->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, bool>
     */
    private function invoicePermissions(User $actor, Company $company, Branch $branch, ?TripInvoice $invoice = null): array
    {
        $canEdit = $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_EDIT, $company, $branch);
        $canEditCharges = false;

        if ($invoice !== null) {
            $hasPayments = $invoice->relationLoaded('payments')
                ? $invoice->payments->isNotEmpty()
                : $invoice->payments()->exists();

            $canEditCharges = $canEdit
                && $invoice->status === TripInvoiceStatus::Pending
                && $invoice->charges_locked_at === null
                && ! $hasPayments;
        }

        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_VIEW, $company, $branch),
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CREATE, $company, $branch),
            'can_edit_charges' => $canEditCharges,
            'can_cancel' => $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CANCEL, $company, $branch),
            'can_delete' => $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_DELETE, $company, $branch),
            'can_print' => $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_PRINT, $company, $branch),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function paymentPermissions(User $actor, Company $company, Branch $branch): array
    {
        return [
            'can_view_payments' => $this->authorizer->allowsCustomer($actor, PermissionCodes::PAYMENT_VIEW, $company, $branch),
            'can_record_payment' => $this->authorizer->allowsCustomer($actor, PermissionCodes::PAYMENT_CREATE, $company, $branch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoiceRow(TripInvoice $invoice, bool $maySeeFreight): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->format('d-m-Y'),
            'billing_customer_name' => $invoice->billingCustomer?->name,
            'invoice_type' => $invoice->invoice_type->value,
            'status' => $invoice->status->value,
            'payment_status' => $invoice->payment_status->value,
            'invoice_total' => $this->freightPresentation->presentAmount($invoice->invoice_total, $maySeeFreight),
            'balance_due' => $this->freightPresentation->presentAmount($invoice->balance_due, $maySeeFreight),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoiceDetail(TripInvoice $invoice, bool $maySeeFreight): array
    {
        $payload = [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->format('d-m-Y'),
            'invoice_type' => $invoice->invoice_type->value,
            'particulars_mode' => $invoice->particulars_mode->value,
            'status' => $invoice->status->value,
            'payment_status' => $invoice->payment_status->value,
            'workflow_state' => $invoice->workflow_state,
            'charges_locked_at' => $invoice->charges_locked_at?->toIso8601String(),
            'billing_customer' => $invoice->billingCustomer ? [
                'id' => $invoice->billingCustomer->id,
                'name' => $invoice->billingCustomer->name,
                'gstin' => $invoice->billingCustomer->gstin,
                'pan' => $invoice->billingCustomer->pan,
            ] : null,
            'base_freight_total' => (string) $invoice->base_freight_total,
            'additional_charges_total' => (string) $invoice->additional_charges_total,
            'taxable_total' => (string) $invoice->taxable_total,
            'gst_amount' => (string) $invoice->gst_amount,
            'invoice_total' => (string) $invoice->invoice_total,
            'amount_received_total' => (string) $invoice->amount_received_total,
            'tds_received_total' => (string) $invoice->tds_received_total,
            'deductions_total' => (string) $invoice->deductions_total,
            'settlement_total' => (string) $invoice->settlement_total,
            'balance_due' => (string) $invoice->balance_due,
            'invoice_tds_rate_percent' => (string) $invoice->invoice_tds_rate_percent,
            'tds_basis_freight' => (string) $invoice->tds_basis_freight,
            'gst_rate_snapshot' => $invoice->gst_rate_snapshot,
            'lines' => $invoice->lines->map(fn ($line) => [
                'id' => $line->id,
                'lr_number' => $line->lr_number,
                'base_freight' => $this->freightPresentation->presentAmount($line->base_freight, $maySeeFreight),
            ])->values()->all(),
            'charges' => $invoice->charges->map(fn (TripInvoiceCharge $charge) => [
                'charge_type' => $charge->charge_type->value,
                'description' => $charge->description,
                'amount' => $this->freightPresentation->presentAmount($charge->amount, $maySeeFreight),
            ])->values()->all(),
            'payments' => $invoice->payments->map(fn (TripInvoicePayment $payment) => [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date->format('d-m-Y'),
                'amount_received' => $this->freightPresentation->presentAmount($payment->amount_received, $maySeeFreight),
                'tds_amount' => $this->freightPresentation->presentAmount($payment->tds_amount, $maySeeFreight),
                'shortage_damage' => $this->freightPresentation->presentAmount($payment->shortage_damage, $maySeeFreight),
                'discount' => $this->freightPresentation->presentAmount($payment->discount, $maySeeFreight),
                'discount_reason' => $payment->discount_reason,
                'settlement_amount' => $this->freightPresentation->presentAmount($payment->settlement_amount, $maySeeFreight),
                'reference' => $payment->reference,
                'payment_mode' => $payment->paymentMode?->name,
            ])->values()->all(),
        ];

        return $this->freightPresentation->redactKeys($payload, [
            'base_freight_total',
            'additional_charges_total',
            'taxable_total',
            'gst_amount',
            'invoice_total',
            'amount_received_total',
            'tds_received_total',
            'deductions_total',
            'settlement_total',
            'balance_due',
            'tds_basis_freight',
        ], $maySeeFreight);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function chargeOptions(): array
    {
        return collect(TripInvoiceChargeType::cases())->map(fn (TripInvoiceChargeType $type): array => [
            'value' => $type->value,
            'label' => match ($type) {
                TripInvoiceChargeType::Detention => 'Detention',
                TripInvoiceChargeType::Demurrage => 'Demurrage',
                TripInvoiceChargeType::Halting => 'Halting',
                TripInvoiceChargeType::LoadingSurcharge => 'Loading surcharge',
                TripInvoiceChargeType::Other => 'Other',
            },
        ])->values()->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function particularsOptions(): array
    {
        return collect(TripInvoiceParticularsMode::cases())->map(fn (TripInvoiceParticularsMode $mode): array => [
            'value' => $mode->value,
            'label' => match ($mode) {
                TripInvoiceParticularsMode::SeparateLines => 'Separate LR lines',
                TripInvoiceParticularsMode::CombinedFreight => 'Combined freight + annexure',
            },
        ])->values()->all();
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if (! $this->moduleEntitlements->isEntitled($company, SubscriptionModule::OwnFleet)) {
            abort(403, 'OwnFleet module is not entitled for this company.');
        }

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403);
        }
    }
}
