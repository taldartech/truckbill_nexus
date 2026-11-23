<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceChargeType;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceParticularsMode;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceAuthorizationException;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceConflictException;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceDomainException;
use App\Domains\OwnFleet\Invoice\TripInvoicePaymentService;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentMode;
use App\Models\TripInvoice;
use App\Models\TripInvoicePayment;
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
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_VIEW);

        $page = $this->invoices->search(
            company: $company,
            branch: $branch,
            paymentStatus: $request->string('payment_status')->toString() ?: null,
            perPage: min(100, max(1, (int) $request->input('per_page', 15))),
        );

        return Inertia::render('branch/trip-invoices/index', [
            'filters' => [
                'payment_status' => $request->string('payment_status')->toString(),
            ],
            'permissions' => $this->invoicePermissions($actor, $company, $branch),
            'invoices' => [
                'data' => $page->getCollection()->map(fn (TripInvoice $invoice): array => $this->presentInvoiceRow($invoice)),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_CREATE);

        return Inertia::render('branch/trip-invoices/create', [
            'eligibleReceipts' => $this->invoices->listInvoiceEligibleLorryReceipts($company, $branch)
                ->map(fn ($lr) => [
                    'id' => $lr->id,
                    'lr_number' => $lr->lr_number,
                    'freight_total' => (string) $lr->freight_total,
                    'billing_customer_name' => $lr->billingCustomer?->name,
                    'billing_customer_party_id' => $lr->billing_customer_party_id,
                    'lr_date' => $lr->lr_date?->format('d-m-Y'),
                ]),
            'chargeTypeOptions' => $this->chargeOptions(),
            'particularsOptions' => $this->particularsOptions(),
            'permissions' => $this->invoicePermissions($actor, $company, $branch),
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function consolidate(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_CREATE);

        $billingCustomerId = $request->string('billing_customer_party_id')->toString() ?: null;

        return Inertia::render('branch/trip-invoices/consolidate', [
            'eligibleReceipts' => $this->invoices->listInvoiceEligibleLorryReceipts($company, $branch, $billingCustomerId)
                ->map(fn ($lr) => [
                    'id' => $lr->id,
                    'lr_number' => $lr->lr_number,
                    'freight_total' => (string) $lr->freight_total,
                    'billing_customer_name' => $lr->billingCustomer?->name,
                    'billing_customer_party_id' => $lr->billing_customer_party_id,
                    'lr_date' => $lr->lr_date?->format('d-m-Y'),
                ]),
            'chargeTypeOptions' => $this->chargeOptions(),
            'particularsOptions' => $this->particularsOptions(),
            'filters' => ['billing_customer_party_id' => $billingCustomerId],
            'permissions' => $this->invoicePermissions($actor, $company, $branch),
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

    public function show(Request $request, Company $company, Branch $branch, TripInvoice $tripInvoice): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_VIEW);

        $tripInvoice->load(['lines.lorryReceipt', 'charges', 'payments.paymentMode', 'billingCustomer']);

        return Inertia::render('branch/trip-invoices/show', [
            'invoice' => $this->presentInvoiceDetail($tripInvoice),
            'permissions' => array_merge(
                $this->invoicePermissions($actor, $company, $branch),
                $this->paymentPermissions($actor, $company, $branch),
            ),
            'paymentModes' => PaymentMode::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function print(Request $request, Company $company, Branch $branch, TripInvoice $tripInvoice): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::INVOICE_PRINT);

        $tripInvoice->load(['lines.lorryReceipt', 'charges', 'billingCustomer', 'company']);

        return Inertia::render('branch/trip-invoices/print', [
            'invoice' => $this->presentInvoiceDetail($tripInvoice),
            'company' => [
                'name' => $company->name,
                'gstin' => $company->gstin,
            ],
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function cancel(Request $request, Company $company, Branch $branch, TripInvoice $tripInvoice): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);

        $validated = $request->validate([
            'cancellation_remark' => ['required', 'string', 'max:2000'],
        ]);

        $this->invoices->cancel($tripInvoice, $actor, $validated['cancellation_remark']);

        return back()->with('flash.success', 'Invoice cancelled.');
    }

    public function destroy(Request $request, Company $company, Branch $branch, TripInvoice $tripInvoice): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertTripInvoiceTenant($tripInvoice, $company, $branch);

        $this->invoices->softDelete($tripInvoice, $actor);

        return redirect()->route('branch.trip-invoices.index')
            ->with('flash.success', 'Invoice deleted.');
    }

    public function storePayment(Request $request, Company $company, Branch $branch, TripInvoice $tripInvoice): RedirectResponse
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

        $rows = TripInvoicePayment::query()
            ->where('company_id', $company->id)
            ->whereHas('invoice', fn ($q) => $q->where('branch_id', $branch->id))
            ->with(['invoice', 'paymentMode'])
            ->orderByDesc('payment_date')
            ->limit(200)
            ->get()
            ->map(fn (TripInvoicePayment $payment): array => [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date?->format('d-m-Y'),
                'invoice_number' => $payment->invoice?->invoice_number,
                'invoice_id' => $payment->trip_invoice_id,
                'amount_received' => (string) $payment->amount_received,
                'tds_amount' => (string) $payment->tds_amount,
                'settlement_amount' => (string) $payment->settlement_amount,
                'payment_mode' => $payment->paymentMode?->name,
            ]);

        return Inertia::render('branch/payments/index', [
            'payments' => $rows,
            'permissions' => $this->paymentPermissions($actor, $company, $branch),
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
    private function invoicePermissions($actor, Company $company, Branch $branch): array
    {
        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_VIEW, $company, $branch),
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CREATE, $company, $branch),
            'can_cancel' => $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CANCEL, $company, $branch),
            'can_delete' => $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_DELETE, $company, $branch),
            'can_print' => $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_PRINT, $company, $branch),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function paymentPermissions($actor, Company $company, Branch $branch): array
    {
        return [
            'can_view_payments' => $this->authorizer->allowsCustomer($actor, PermissionCodes::PAYMENT_VIEW, $company, $branch),
            'can_record_payment' => $this->authorizer->allowsCustomer($actor, PermissionCodes::PAYMENT_CREATE, $company, $branch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoiceRow(TripInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->format('d-m-Y'),
            'billing_customer_name' => $invoice->billingCustomer?->name,
            'invoice_type' => $invoice->invoice_type->value,
            'status' => $invoice->status->value,
            'payment_status' => $invoice->payment_status->value,
            'invoice_total' => (string) $invoice->invoice_total,
            'balance_due' => (string) $invoice->balance_due,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoiceDetail(TripInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->format('d-m-Y'),
            'invoice_type' => $invoice->invoice_type->value,
            'particulars_mode' => $invoice->particulars_mode->value,
            'status' => $invoice->status->value,
            'payment_status' => $invoice->payment_status->value,
            'workflow_state' => $invoice->workflow_state,
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
                'base_freight' => (string) $line->base_freight,
            ]),
            'charges' => $invoice->charges->map(fn ($charge) => [
                'charge_type' => $charge->charge_type->value,
                'description' => $charge->description,
                'amount' => (string) $charge->amount,
            ]),
            'payments' => $invoice->payments->map(fn (TripInvoicePayment $payment) => [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date?->format('d-m-Y'),
                'amount_received' => (string) $payment->amount_received,
                'tds_amount' => (string) $payment->tds_amount,
                'shortage_damage' => (string) $payment->shortage_damage,
                'discount' => (string) $payment->discount,
                'discount_reason' => $payment->discount_reason,
                'settlement_amount' => (string) $payment->settlement_amount,
                'reference' => $payment->reference,
                'payment_mode' => $payment->paymentMode?->name,
            ]),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
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
        ])->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function particularsOptions(): array
    {
        return collect(TripInvoiceParticularsMode::cases())->map(fn (TripInvoiceParticularsMode $mode): array => [
            'value' => $mode->value,
            'label' => match ($mode) {
                TripInvoiceParticularsMode::SeparateLines => 'Separate LR lines',
                TripInvoiceParticularsMode::CombinedFreight => 'Combined freight + annexure',
            },
        ])->all();
    }

    private function assertPermission($actor, Company $company, Branch $branch, string $code): void
    {
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403);
        }
    }
}
