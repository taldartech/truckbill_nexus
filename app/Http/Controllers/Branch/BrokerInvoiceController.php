<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\BrokerInvoice;
use App\Models\BrokerInvoicePayment;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BrokerInvoiceController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly BrokerInvoiceService $invoices,
        private readonly FreightAmountPresentation $freightPresentation,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_INVOICE_VIEW);

        return redirect()->route('branch.invoices.index', array_merge(
            $request->query(),
            ['source' => 'broker'],
        ));
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_INVOICE_CREATE);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $billingCustomerId = $request->string('billing_customer_party_id')->toString() ?: null;

        $eligible = $this->invoices->listEligibleLorryReceipts($company, $branch, $billingCustomerId);

        $customers = app(PartyOptionQuery::class)
            ->forRoles($company, $branch, PartyRoleType::Customer, 300);

        return Inertia::render('broker/invoices/create', [
            'filters' => ['billing_customer_party_id' => $billingCustomerId],
            'billingCustomers' => $customers,
            'eligibleWithMemo' => $eligible['with_memo']->map(fn (LorryReceipt $lr) => $this->eligiblePayload($lr, $maySeeFreight))->values()->all(),
            'eligibleWithoutMemo' => $eligible['without_memo']->map(fn (LorryReceipt $lr) => $this->eligiblePayload($lr, $maySeeFreight))->values()->all(),
            'permissions' => $this->invoicePermissions($actor, $company, $branch, $maySeeFreight),
            'urls' => [
                'index' => route('branch.broker.invoices.index', absolute: false),
                'store' => route('branch.broker.invoices.store', absolute: false),
                'preview' => route('branch.broker.invoices.preview', absolute: false),
                'filter' => route('branch.broker.invoices.create', absolute: false),
                'parties_options' => route('branch.parties.options', absolute: false),
            ],
        ]);
    }

    public function preview(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_INVOICE_CREATE);

        $validated = $request->validate([
            'lorry_receipt_ids' => ['required', 'array', 'min:1'],
            'lorry_receipt_ids.*' => ['uuid'],
        ]);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $preview = $this->invoices->previewAmounts($company, $branch, $validated['lorry_receipt_ids']);

        return response()->json([
            'freight_total' => $this->freightPresentation->presentAmount($preview['freight_total'], $maySeeFreight),
            'charges_total' => $this->freightPresentation->presentAmount($preview['charges_total'], $maySeeFreight),
            'shortage_damage_total' => $this->freightPresentation->presentAmount($preview['shortage_damage_total'], $maySeeFreight),
            'taxable_total' => $this->freightPresentation->presentAmount($preview['taxable_total'], $maySeeFreight),
            'gst_amount' => $this->freightPresentation->presentAmount($preview['gst_amount'], $maySeeFreight),
            'cgst_amount' => $this->freightPresentation->presentAmount($preview['cgst_amount'], $maySeeFreight),
            'sgst_amount' => $this->freightPresentation->presentAmount($preview['sgst_amount'], $maySeeFreight),
            'igst_amount' => $this->freightPresentation->presentAmount($preview['igst_amount'], $maySeeFreight),
            'cgst_rate' => $preview['cgst_rate'] ?? null,
            'sgst_rate' => $preview['sgst_rate'] ?? null,
            'igst_rate' => $preview['igst_rate'] ?? null,
            'supply_type' => $preview['supply_type'] ?? null,
            'tax_liability' => $preview['tax_liability'] ?? null,
            'invoice_total' => $this->freightPresentation->presentAmount($preview['invoice_total'], $maySeeFreight),
            'lines' => collect($preview['lines'])->map(fn (array $line): array => [
                'lorry_receipt_id' => $line['lorry_receipt_id'],
                'lr_number' => $line['lr_number'],
                'freight_amount' => $this->freightPresentation->presentAmount($line['freight_amount'], $maySeeFreight),
                'charges_amount' => $this->freightPresentation->presentAmount($line['charges_amount'], $maySeeFreight),
                'shortage_damage_amount' => $this->freightPresentation->presentAmount($line['shortage_damage_amount'], $maySeeFreight),
                'line_total' => $this->freightPresentation->presentAmount($line['line_total'], $maySeeFreight),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'billing_customer_party_id' => ['required', 'uuid'],
            'lorry_receipt_ids' => ['required', 'array', 'min:1'],
            'lorry_receipt_ids.*' => ['uuid'],
            'invoice_date' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $invoice = $this->invoices->createInvoice($company, $branch, $actor, $validated);
        } catch (BrokerAuthorizationException|ValidationException $e) {
            throw $e;
        } catch (BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['lorry_receipt_ids' => $e->getMessage()]);
        }

        return redirect()->route('branch.broker.invoices.show', [$invoice]);
    }

    public function show(Request $request, BrokerInvoice $brokerInvoice, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertInvoiceTenant($brokerInvoice, $company, $branch);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_INVOICE_VIEW);

        $brokerInvoice->load(['lines.lorryReceipt', 'lines.lorryHire', 'payments', 'billingCustomer']);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return Inertia::render('broker/invoices/show', [
            'invoice' => $this->detailPayload($brokerInvoice, $maySeeFreight),
            'permissions' => $this->invoicePermissions($actor, $company, $branch, $maySeeFreight, $brokerInvoice),
            'bankCashAccounts' => BankCashAccount::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'account_kind'])
                ->map(fn (BankCashAccount $row): array => [
                    'id' => $row->id,
                    'label' => $row->name.' ('.$row->account_kind.')',
                ])
                ->values()
                ->all(),
            'urls' => [
                'index' => route('branch.broker.invoices.index', absolute: false),
                'cancel' => route('branch.broker.invoices.cancel', [$brokerInvoice], absolute: false),
                'payment' => route('branch.broker.invoices.payments.store', [$brokerInvoice], absolute: false),
                'print' => route('branch.broker.invoices.print', [$brokerInvoice], absolute: false),
            ],
        ]);
    }

    public function print(Request $request, BrokerInvoice $brokerInvoice, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertInvoiceTenant($brokerInvoice, $company, $branch);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_INVOICE_PRINT);

        $brokerInvoice->load(['lines.lorryReceipt', 'payments', 'billingCustomer']);
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        $snapshotGstin = data_get($brokerInvoice->gst_rate_snapshot, 'supplier_gstin');

        return Inertia::render('broker/invoices/print', [
            'invoice' => $this->detailPayload($brokerInvoice, $maySeeFreight),
            'company' => [
                'name' => $company->name,
                // Prefer issued snapshot GSTIN so later Branch GST changes never rewrite prints.
                'gstin' => filled($snapshotGstin) ? (string) $snapshotGstin : $company->gstin,
            ],
            'sampleBanner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice.',
        ]);
    }

    public function cancel(Request $request, BrokerInvoice $brokerInvoice, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertInvoiceTenant($brokerInvoice, $company, $branch);

        $validated = $request->validate([
            'cancellation_remark' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->invoices->cancel($brokerInvoice, $actor, $validated['cancellation_remark']);
        } catch (BrokerAuthorizationException|ValidationException $e) {
            throw $e;
        } catch (BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['cancellation_remark' => $e->getMessage()]);
        }

        return redirect()->route('branch.broker.invoices.show', [$brokerInvoice]);
    }

    public function storePayment(Request $request, BrokerInvoice $brokerInvoice, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertInvoiceTenant($brokerInvoice, $company, $branch);

        $validated = $request->validate([
            'amount_received' => ['required', 'numeric', 'min:0'],
            'tds_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_mode' => ['nullable', 'string', 'max:32'],
            'payment_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:128'],
            'remark' => ['nullable', 'string', 'max:500'],
            'bank_cash_account_id' => ['nullable', 'uuid'],
        ]);

        try {
            $this->invoices->recordPayment($brokerInvoice, $actor, $validated);
        } catch (BrokerAuthorizationException|ValidationException $e) {
            throw $e;
        } catch (BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['amount_received' => $e->getMessage()]);
        }

        return redirect()->route('branch.broker.invoices.show', [$brokerInvoice]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eligiblePayload(LorryReceipt $lr, bool $maySeeFreight): array
    {
        return [
            'id' => $lr->id,
            'lr_number' => $lr->lr_number,
            'lr_date' => $lr->lr_date?->format('d-m-Y'),
            'billing_customer_party_id' => $lr->billing_customer_party_id,
            'billing_customer_name' => $lr->billingCustomer?->name,
            'freight_total' => $this->freightPresentation->presentAmount($lr->freight_total, $maySeeFreight),
            'memo_number' => $lr->delivery?->memo_number,
            'pod_number' => $lr->delivery?->pod_number,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(BrokerInvoice $invoice, bool $maySeeFreight): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'payment_status' => $invoice->payment_status->value,
            'invoice_date' => $invoice->invoice_date?->format('d-m-Y'),
            'billing_customer_name' => $invoice->billingCustomer?->name,
            'taxable_total' => $this->freightPresentation->presentAmount($invoice->taxable_total, $maySeeFreight),
            'gst_amount' => $this->freightPresentation->presentAmount($invoice->gst_amount, $maySeeFreight),
            'cgst_amount' => $this->freightPresentation->presentAmount(
                (string) (data_get($invoice->gst_rate_snapshot, 'cgst_amount') ?? '0.00'),
                $maySeeFreight,
            ),
            'sgst_amount' => $this->freightPresentation->presentAmount(
                (string) (data_get($invoice->gst_rate_snapshot, 'sgst_amount') ?? '0.00'),
                $maySeeFreight,
            ),
            'igst_amount' => $this->freightPresentation->presentAmount(
                (string) (data_get($invoice->gst_rate_snapshot, 'igst_amount') ?? '0.00'),
                $maySeeFreight,
            ),
            'cgst_rate' => data_get($invoice->gst_rate_snapshot, 'cgst_rate'),
            'sgst_rate' => data_get($invoice->gst_rate_snapshot, 'sgst_rate'),
            'igst_rate' => data_get($invoice->gst_rate_snapshot, 'igst_rate'),
            'supply_type' => data_get($invoice->gst_rate_snapshot, 'supply_type'),
            'tax_liability' => data_get($invoice->gst_rate_snapshot, 'tax_liability'),
            'invoice_total' => $this->freightPresentation->presentAmount($invoice->invoice_total, $maySeeFreight),
            'amount_received' => $this->freightPresentation->presentAmount($invoice->amount_received, $maySeeFreight),
            'customer_tds_amount' => $this->freightPresentation->presentAmount($invoice->customer_tds_amount, $maySeeFreight),
            'balance_due' => $this->freightPresentation->presentAmount($invoice->balance_due, $maySeeFreight),
            'cancellation_remark' => $invoice->cancellation_remark,
            'lines' => $invoice->lines->map(fn ($line): array => [
                'id' => $line->id,
                'lr_number' => $line->lorryReceipt->lr_number ?? $line->description,
                'memo_number' => $line->memo_number,
                'pod_number' => $line->pod_number,
                'freight_amount' => $this->freightPresentation->presentAmount($line->freight_amount, $maySeeFreight),
                'charges_amount' => $this->freightPresentation->presentAmount($line->charges_amount, $maySeeFreight),
                'shortage_damage_amount' => $this->freightPresentation->presentAmount($line->shortage_damage_amount, $maySeeFreight),
                'line_total' => $this->freightPresentation->presentAmount($line->line_total, $maySeeFreight),
            ])->values()->all(),
            'payments' => $invoice->payments->map(fn (BrokerInvoicePayment $payment): array => [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date?->format('d-m-Y'),
                'payment_mode' => $payment->payment_mode,
                'amount_received' => $this->freightPresentation->presentAmount($payment->amount_received, $maySeeFreight),
                'tds_amount' => $this->freightPresentation->presentAmount($payment->tds_amount, $maySeeFreight),
                'deduction_amount' => $this->freightPresentation->presentAmount($payment->deduction_amount, $maySeeFreight),
                'reference_number' => $payment->reference_number,
                'remark' => $payment->remark,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function invoicePermissions(
        User $actor,
        Company $company,
        Branch $branch,
        bool $maySeeFreight,
        ?BrokerInvoice $invoice = null,
    ): array {
        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_VIEW, $company, $branch),
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_CREATE, $company, $branch),
            'can_cancel' => $invoice !== null
                && $invoice->status->value !== 'cancelled'
                && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_CANCEL, $company, $branch),
            'can_record_payment' => $invoice !== null
                && $invoice->status->value !== 'cancelled'
                && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_CREATE, $company, $branch),
            'can_print' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_PRINT, $company, $branch),
            'can_view_freight' => $maySeeFreight,
        ];
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Broker invoice operation.');
        }
    }

    private function assertInvoiceTenant(BrokerInvoice $invoice, Company $company, Branch $branch): void
    {
        if ($invoice->company_id !== $company->id || $invoice->branch_id !== $branch->id) {
            abort(404);
        }
    }
}
