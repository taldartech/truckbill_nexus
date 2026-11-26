<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\Exceptions\PartloadDomainException;
use App\Domains\Partload\Invoice\PartloadInvoiceService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartloadInvoice;
use App\Models\PartloadInvoiceLine;
use App\Models\PartloadInvoicePayment;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PartloadInvoiceController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly PartloadInvoiceService $invoices,
        private readonly FreightAmountPresentation $freightPresentation,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        if ((int) $actor->id !== (int) $company->owner_user_id
            && ! $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_VIEW, $company, $branch)) {
            abort(403, 'Not authorized to view Partload invoices.');
        }

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $paymentStatus = $request->string('payment_status')->toString() ?: null;
        $page = $this->invoices->search($company, $branch, $paymentStatus);

        return Inertia::render('branch/partload/invoices/index', [
            'invoices' => [
                'data' => collect($page->items())->map(fn (PartloadInvoice $inv): array => [
                    'id' => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'invoice_date' => $inv->invoice_date->format('d-m-Y'),
                    'billing_customer_name' => $inv->billingCustomer?->name,
                    'status' => $inv->status,
                    'payment_status' => $inv->payment_status,
                    'invoice_total' => $this->freightPresentation->presentAmount($inv->invoice_total, $maySeeFreight),
                    'balance_due' => $this->freightPresentation->presentAmount($inv->balance_due, $maySeeFreight),
                    'urls' => [
                        'show' => route('branch.partload.invoices.show', [$inv], absolute: false),
                    ],
                ])->values()->all(),
            ],
            'permissions' => [
                'can_create' => (int) $actor->id === (int) $company->owner_user_id
                    || $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CREATE, $company, $branch),
                'can_view_freight' => $maySeeFreight,
            ],
            'urls' => [
                'create' => route('branch.partload.invoices.create', absolute: false),
                'unified' => route('branch.invoices.index', absolute: false),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        if ((int) $actor->id !== (int) $company->owner_user_id
            && ! $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CREATE, $company, $branch)) {
            abort(403, 'Not authorized to create Partload invoices.');
        }

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $selectedIds = array_values(array_filter((array) $request->input('lorry_receipt_ids', []), 'is_string'));

        $eligible = $this->invoices->listEligibleLorryReceipts($company, $branch)->map(function ($lr) use ($maySeeFreight): array {
            $dr = $lr->partloadDeliveryReceipt;
            $customer = ($dr !== null ? $dr->dueFromParty : null) ?? $lr->billingCustomer;

            return [
                'id' => $lr->id,
                'lr_number' => $lr->lr_number,
                'lr_date' => $lr->lr_date?->format('d-m-Y'),
                'from_station_name' => $lr->fromStation?->name,
                'to_station_name' => $lr->toStation?->name,
                'billing_customer_party_id' => ($customer !== null ? $customer->id : null) ?? $lr->billing_customer_party_id,
                'billing_customer_name' => $customer?->name,
                'freight_payment_term' => $lr->freight_payment_term?->value,
                'freight_total' => $this->freightPresentation->presentAmount(
                    $dr !== null ? $dr->final_amount : $lr->freight_total,
                    $maySeeFreight,
                ),
            ];
        })->values()->all();

        return Inertia::render('branch/partload/invoices/create', [
            'eligibleReceipts' => $eligible,
            'preselectedIds' => $selectedIds,
            'permissions' => ['can_view_freight' => $maySeeFreight],
            'urls' => [
                'store' => route('branch.partload.invoices.store', absolute: false),
                'index' => route('branch.partload.invoices.index', absolute: false),
                'unified' => route('branch.invoices.index', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $validated = $request->validate([
            'lorry_receipt_ids' => ['required', 'array', 'min:1'],
            'lorry_receipt_ids.*' => ['uuid'],
            'invoice_date' => ['nullable', 'date'],
        ]);

        try {
            $invoice = $this->invoices->create($company, $branch, $actor, $validated);
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException|PartloadDomainException $e) {
            throw ValidationException::withMessages(['lorry_receipt_ids' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.partload.invoices.show', [$invoice])
            ->with('flash.success', "Partload invoice {$invoice->invoice_number} created.");
    }

    public function show(Request $request, PartloadInvoice $partloadInvoice, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        if ((int) $actor->id !== (int) $company->owner_user_id
            && ! $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_VIEW, $company, $branch)) {
            abort(403, 'Not authorized to view Partload invoices.');
        }

        try {
            $invoice = $this->invoices->findForBranch($company, $branch, $partloadInvoice->id);
        } catch (PartloadDomainException $e) {
            abort(404, $e->getMessage());
        }

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $accounts = BankCashAccount::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->orderBy('name')
            ->get(['id', 'name', 'account_kind'])
            ->map(fn (BankCashAccount $acc): array => [
                'id' => $acc->id,
                'name' => $acc->name,
                'account_type' => $acc->account_kind,
            ])->values()->all();

        return Inertia::render('branch/partload/invoices/show', [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->format('d-m-Y'),
                'status' => $invoice->status,
                'payment_status' => $invoice->payment_status,
                'billing_customer_name' => $invoice->billingCustomer?->name,
                'billing_customer_gstin' => $invoice->billingCustomer?->gstin,
                'taxable_total' => $this->freightPresentation->presentAmount($invoice->taxable_total, $maySeeFreight),
                'gst_amount' => $this->freightPresentation->presentAmount($invoice->gst_amount, $maySeeFreight),
                'invoice_total' => $this->freightPresentation->presentAmount($invoice->invoice_total, $maySeeFreight),
                'amount_received' => $this->freightPresentation->presentAmount($invoice->amount_received, $maySeeFreight),
                'customer_tds_amount' => $this->freightPresentation->presentAmount($invoice->customer_tds_amount, $maySeeFreight),
                'balance_due' => $this->freightPresentation->presentAmount($invoice->balance_due, $maySeeFreight),
                'cancellation_remark' => $invoice->cancellation_remark,
                'lines' => $invoice->lines->map(fn (PartloadInvoiceLine $line): array => [
                    'id' => $line->id,
                    'description' => $line->description,
                    'lr_number' => $line->lorryReceipt?->lr_number,
                    'freight_amount' => $this->freightPresentation->presentAmount($line->freight_amount, $maySeeFreight),
                    'charges_amount' => $this->freightPresentation->presentAmount($line->charges_amount, $maySeeFreight),
                    'line_total' => $this->freightPresentation->presentAmount($line->line_total, $maySeeFreight),
                ])->values()->all(),
                'payments' => $invoice->payments->map(fn (PartloadInvoicePayment $p): array => [
                    'id' => $p->id,
                    'payment_date' => $p->payment_date->format('d-m-Y'),
                    'amount_received' => $this->freightPresentation->presentAmount($p->amount_received, $maySeeFreight),
                    'tds_amount' => $this->freightPresentation->presentAmount($p->tds_amount, $maySeeFreight),
                    'deduction_amount' => $this->freightPresentation->presentAmount($p->deduction_amount, $maySeeFreight),
                    'payment_mode' => $p->payment_mode,
                    'reference' => $p->reference,
                    'remark' => $p->remark,
                ])->values()->all(),
            ],
            'bankCashAccounts' => $accounts,
            'permissions' => [
                'can_view_freight' => $maySeeFreight,
                'can_record_payment' => $invoice->status === 'issued' && (
                    (int) $actor->id === (int) $company->owner_user_id
                    || $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_EDIT, $company, $branch)
                ),
                'can_cancel' => $invoice->status === 'issued'
                    && $invoice->payments->isEmpty()
                    && ((int) $actor->id === (int) $company->owner_user_id
                        || $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CANCEL, $company, $branch)),
            ],
            'urls' => [
                'index' => route('branch.partload.invoices.index', absolute: false),
                'unified' => route('branch.invoices.index', absolute: false),
                'record_payment' => route('branch.partload.invoices.payments.store', [$invoice], absolute: false),
                'cancel' => route('branch.partload.invoices.cancel', [$invoice], absolute: false),
            ],
        ]);
    }

    public function storePayment(Request $request, PartloadInvoice $partloadInvoice, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        if ($partloadInvoice->company_id !== $company->id || $partloadInvoice->branch_id !== $branch->id) {
            abort(404);
        }

        $validated = $request->validate([
            'amount_received' => ['required', 'numeric', 'gt:0'],
            'tds_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_date' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'string'],
            'bank_cash_account_id' => ['required', 'uuid'],
            'reference' => ['nullable', 'string', 'max:120'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->invoices->recordPayment($partloadInvoice, $actor, $validated);
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException|PartloadDomainException $e) {
            throw ValidationException::withMessages(['amount_received' => $e->getMessage()]);
        }

        return back()->with('flash.success', 'Customer receipt recorded.');
    }

    public function cancel(Request $request, PartloadInvoice $partloadInvoice, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        if ($partloadInvoice->company_id !== $company->id || $partloadInvoice->branch_id !== $branch->id) {
            abort(404);
        }

        $validated = $request->validate([
            'cancellation_remark' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->invoices->cancel($partloadInvoice, $actor, $validated['cancellation_remark']);
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException|PartloadDomainException $e) {
            throw ValidationException::withMessages(['cancellation_remark' => $e->getMessage()]);
        }

        return back()->with('flash.success', 'Partload invoice cancelled.');
    }
}
