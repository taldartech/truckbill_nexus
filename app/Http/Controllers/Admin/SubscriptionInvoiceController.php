<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Rbac\InternalAuthorization;
use App\Domains\Subscription\Billing\SubscriptionInvoiceDocumentService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceService;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal Admin/CRM Subscription Invoice register, view, download, approval, and credit notes.
 */
class SubscriptionInvoiceController extends Controller
{
    public function __construct(
        private readonly SubscriptionInvoiceService $invoices = new SubscriptionInvoiceService,
        private readonly SubscriptionInvoiceDocumentService $documents = new SubscriptionInvoiceDocumentService,
        private readonly InternalAuthorization $internalAuth = new InternalAuthorization,
    ) {}

    public function index(Request $request): Response
    {
        $this->internalAuth->authorizeAnyOrAbort($request, [
            'internal.subscription_billing.view',
            'internal.subscription_billing.manage',
        ]);

        $companyId = trim((string) $request->query('company_id', ''));
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', 'all'));

        $query = SubscriptionInvoice::query()
            ->with(['company:id,name', 'paymentIntent:id,status,verification_status,gateway_payment_id'])
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at');

        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        if ($status !== '' && $status !== 'all') {
            if ($status === 'credit_note') {
                $query->where('document_type', 'credit_note');
            } else {
                $query->where('status', $status);
            }
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('invoice_number', 'like', $like)
                    ->orWhere('approved_number', 'like', $like)
                    ->orWhere('proposed_number', 'like', $like)
                    ->orWhere('payment_reference', 'like', $like)
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like));
            });
        }

        $rows = $query->limit(200)->get();
        $companies = Company::query()
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('admin/subscription-invoices', [
            'filters' => [
                'company_id' => $companyId,
                'q' => $search,
                'status' => $status,
            ],
            'companyOptions' => $companies->map(fn (Company $c): array => [
                'id' => $c->id,
                'name' => $c->name,
            ])->values()->all(),
            'canManage' => $this->internalAuth->allows($request->user(), 'internal.subscription_billing.manage'),
            'invoices' => $rows->map(fn (SubscriptionInvoice $invoice): array => [
                'id' => $invoice->id,
                'document_type' => $invoice->document_type,
                'company_id' => $invoice->company_id,
                'company_name' => $invoice->company?->name,
                'invoice_number' => $invoice->invoice_number ?? $invoice->approved_number ?? $invoice->proposed_number,
                'proposed_number' => $invoice->proposed_number,
                'status' => $invoice->status,
                'payment_status' => $invoice->paymentIntent !== null ? $invoice->paymentIntent->status : 'recorded',
                'purchase_type' => $invoice->purchase_type,
                'financial_year' => $invoice->financial_year,
                'taxable_amount' => (string) ($invoice->taxable_amount ?: $invoice->amount),
                'total_tax_amount' => (string) ($invoice->total_tax_amount ?? '0.00'),
                'amount' => (string) $invoice->amount,
                'currency' => $invoice->currency,
                'issued_at' => ($invoice->issued_at ?? $invoice->approved_at ?? $invoice->created_at)?->toDateString(),
                'payment_reference' => $invoice->payment_reference ?? $invoice->payment_intent_id,
                'view_url' => route('admin.subscription-invoices.show', $invoice),
                'download_url' => route('admin.subscription-invoices.download', $invoice),
                'approve_url' => $invoice->status === 'awaiting_number_approval'
                    ? route('admin.subscription-invoices.approve', $invoice)
                    : null,
                'credit_note_url' => $invoice->document_type === 'tax_invoice' && in_array($invoice->status, ['issued', 'emailed', 'approved'], true)
                    ? route('admin.subscription-invoices.credit-note', $invoice)
                    : null,
            ])->values()->all(),
            'links' => [
                'index' => route('admin.subscription-invoices'),
                'billing_profile' => route('admin.billing-profile'),
            ],
        ]);
    }

    public function show(Request $request, SubscriptionInvoice $invoice): Response
    {
        $this->internalAuth->authorizeAnyOrAbort($request, [
            'internal.subscription_billing.view',
            'internal.subscription_billing.manage',
        ]);

        return Inertia::render('admin/subscription-invoice-show', [
            'document' => $this->documents->presentation($invoice),
            'canManage' => $this->internalAuth->allows($request->user(), 'internal.subscription_billing.manage'),
            'links' => [
                'index' => route('admin.subscription-invoices'),
                'download' => route('admin.subscription-invoices.download', $invoice),
                'credit_note' => $invoice->document_type === 'tax_invoice' && in_array($invoice->status, ['issued', 'emailed', 'approved'], true)
                    ? route('admin.subscription-invoices.credit-note', $invoice)
                    : null,
            ],
        ]);
    }

    public function download(Request $request, SubscriptionInvoice $invoice): HttpResponse
    {
        $this->internalAuth->authorizeAnyOrAbort($request, [
            'internal.subscription_billing.view',
            'internal.subscription_billing.manage',
        ]);

        $html = $this->documents->renderHtml($invoice);
        $number = (string) ($invoice->invoice_number ?? $invoice->approved_number ?? $invoice->proposed_number ?? $invoice->id);
        $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $number) ?: $invoice->id;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="subscription-'.$safeFilename.'.html"',
        ]);
    }

    public function approve(Request $request, SubscriptionInvoice $invoice): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, 'internal.subscription_billing.manage');

        $validated = $request->validate([
            'approved_number' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $approved = $this->invoices->approveNumber(
                $actor,
                $invoice,
                $validated['approved_number'] ?? null,
            );
            $this->documents->dispatchEmail($approved);
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['approved_number' => $e->getMessage()]);
        }

        return back()->with('success', 'Invoice number approved (immutable).');
    }

    public function issueCreditNote(Request $request, SubscriptionInvoice $invoice): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, 'internal.subscription_billing.manage');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        try {
            $cn = $this->invoices->issueCreditNote(
                $actor,
                $invoice,
                $validated['reason'],
                isset($validated['amount']) ? (string) $validated['amount'] : null,
            );
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['credit_note' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.subscription-invoices.show', $cn)
            ->with('success', 'Credit Note '.$cn->invoice_number.' issued without altering original invoice.');
    }
}
