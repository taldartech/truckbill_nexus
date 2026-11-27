<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Rbac\InternalAuthorization;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceDocumentService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceService;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PlatformBillingProfile;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal Admin/CRM Subscription Invoice Register, Approval Workflow, View, Download & Credit Notes.
 */
class SubscriptionInvoiceController extends Controller
{
    public function __construct(
        private readonly SubscriptionInvoiceService $invoices = new SubscriptionInvoiceService,
        private readonly SubscriptionInvoiceDocumentService $documents = new SubscriptionInvoiceDocumentService,
        private readonly PlatformBillingProfileService $billingProfiles = new PlatformBillingProfileService,
        private readonly InternalAuthorization $internalAuth = new InternalAuthorization,
    ) {}

    public function index(Request $request): Response
    {
        $this->internalAuth->authorizeAnyOrAbort($request, [
            'internal.subscription_billing.view',
            'internal.subscription_billing.manage',
        ]);

        $customerCompanyId = trim((string) $request->query('customer_company', ''));
        $billingProfileId = trim((string) $request->query('billing_profile_id', ''));
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', 'all'));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        $query = SubscriptionInvoice::query()
            ->with([
                'company:id,name',
                'paymentIntent:id,status,verification_status,gateway_payment_id,updated_at',
                'billingProfile:id,legal_name,gstin',
            ])
            ->orderByDesc('payment_at')
            ->orderByDesc('created_at');

        if ($customerCompanyId !== '') {
            $query->where('company_id', $customerCompanyId);
        }

        if ($billingProfileId !== '') {
            $query->where('billing_profile_id', $billingProfileId);
        }

        if ($status !== '' && $status !== 'all') {
            if ($status === 'credit_note') {
                $query->where('document_type', 'credit_note');
            } elseif ($status === 'pending_approval') {
                $query->where('document_type', 'tax_invoice')
                    ->where('status', SubscriptionInvoice::STATUS_PENDING_APPROVAL);
            } elseif ($status === 'approved') {
                $query->whereIn('status', [
                    SubscriptionInvoice::STATUS_APPROVED,
                    SubscriptionInvoice::STATUS_ISSUED,
                    SubscriptionInvoice::STATUS_EMAILED,
                ]);
            } else {
                $query->where('status', $status);
            }
        }

        if ($dateFrom !== '') {
            $query->where(function ($q) use ($dateFrom): void {
                $q->whereDate('invoice_date', '>=', $dateFrom)
                    ->orWhere(function ($sub) use ($dateFrom): void {
                        $sub->whereNull('invoice_date')->whereDate('created_at', '>=', $dateFrom);
                    });
            });
        }

        if ($dateTo !== '') {
            $query->where(function ($q) use ($dateTo): void {
                $q->whereDate('invoice_date', '<=', $dateTo)
                    ->orWhere(function ($sub) use ($dateTo): void {
                        $sub->whereNull('invoice_date')->whereDate('created_at', '<=', $dateTo);
                    });
            });
        }

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($like): void {
                $q->whereRaw('LOWER(COALESCE(invoice_number, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(approved_number, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(payment_reference, \'\')) LIKE ?', [$like])
                    ->orWhereHas('company', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]))
                    ->orWhereHas('billingProfile', fn ($b) => $b->whereRaw('LOWER(legal_name) LIKE ?', [$like]));
            });
        }

        $rows = $query->limit(200)->get();
        $companies = Company::query()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        $profiles = $this->billingProfiles->listAll('all');
        $canManage = $this->internalAuth->allows($request->user(), 'internal.subscription_billing.manage');
        $todayIst = CarbonImmutable::now('Asia/Kolkata')->startOfDay();

        return Inertia::render('admin/subscription-invoices', [
            'filters' => [
                'customer_company' => $customerCompanyId,
                'billing_profile_id' => $billingProfileId,
                'q' => $search,
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'companyOptions' => $companies->map(fn (Company $c): array => [
                'id' => $c->id,
                'name' => $c->name,
            ])->values()->all(),
            'billingProfileOptions' => $profiles->map(fn (PlatformBillingProfile $p): array => [
                'id' => $p->id,
                'name' => $p->legal_name.($p->gstin ? ' ('.$p->gstin.')' : '').(! $p->is_active ? ' [Inactive]' : ''),
            ])->values()->all(),
            'canManage' => $canManage,
            'invoices' => $rows->map(function (SubscriptionInvoice $invoice) use ($canManage, $todayIst): array {
                $isApproved = $invoice->isApprovedForCustomer();
                $paymentAt = $this->invoices->paymentTimestamp($invoice);
                $deadline = $this->invoices->statutoryDeadlineFromPayment($paymentAt);
                $supplierName = is_array($invoice->supplier_snapshot)
                    ? ($invoice->supplier_snapshot['legal_name'] ?? null)
                    : null;
                $lineItems = is_array($invoice->line_items) ? $invoice->line_items : [];
                $firstDesc = isset($lineItems[0]['description']) ? (string) $lineItems[0]['description'] : null;

                return [
                    'id' => $invoice->id,
                    'document_type' => $invoice->document_type,
                    'company_id' => $invoice->company_id,
                    'company_name' => $invoice->company?->name,
                    'purchase_details' => $firstDesc ?: ucwords(str_replace('_', ' ', (string) ($invoice->purchase_type ?? 'subscription'))),
                    'purchase_type' => $invoice->purchase_type,
                    'payment_reference' => $invoice->payment_reference ?? $invoice->payment_intent_id,
                    'payment_date' => $paymentAt->toDateString(),
                    'invoice_number' => $isApproved
                        ? ($invoice->invoice_number ?? $invoice->approved_number)
                        : null,
                    'billing_profile_id' => $invoice->billing_profile_id,
                    'billing_profile_name' => $supplierName ?? $invoice->billingProfile?->legal_name,
                    'status' => $invoice->status,
                    'is_approved' => $isApproved,
                    'financial_year' => $invoice->financial_year,
                    'taxable_amount' => (string) ($invoice->taxable_amount ?: $invoice->amount),
                    'total_tax_amount' => (string) ($invoice->total_tax_amount ?? '0.00'),
                    'amount' => (string) $invoice->amount,
                    'currency' => $invoice->currency,
                    'invoice_date' => $isApproved
                        ? ($invoice->invoice_date?->toDateString() ?? ($invoice->issued_at ?? $invoice->approved_at)?->toDateString())
                        : null,
                    'statutory_deadline' => $deadline->toDateString(),
                    'is_overdue' => ! $isApproved && $todayIst->greaterThan($deadline),
                    'view_url' => route('admin.subscription-invoices.show', $invoice),
                    'download_url' => $isApproved ? route('admin.subscription-invoices.download', $invoice) : null,
                    'approve_url' => ! $isApproved && $canManage ? route('admin.subscription-invoices.show', $invoice) : null,
                    'credit_note_url' => $invoice->document_type === 'tax_invoice' && $isApproved && $canManage
                        ? route('admin.subscription-invoices.credit-note', $invoice)
                        : null,
                ];
            })->values()->all(),
            'links' => [
                'index' => route('admin.subscription-invoices'),
                'billing_profiles' => route('admin.billing-profiles.index'),
            ],
        ]);
    }

    public function show(Request $request, SubscriptionInvoice $invoice): Response
    {
        $this->internalAuth->authorizeAnyOrAbort($request, [
            'internal.subscription_billing.view',
            'internal.subscription_billing.manage',
        ]);

        $invoice->load(['company', 'paymentIntent', 'billingProfile', 'approver']);
        $isApproved = $invoice->isApprovedForCustomer();
        $canManage = $this->internalAuth->allows($request->user(), 'internal.subscription_billing.manage');

        $paymentAt = $this->invoices->paymentTimestamp($invoice);
        $paymentDate = CarbonImmutable::parse($paymentAt->setTimezone('Asia/Kolkata')->toDateString(), 'Asia/Kolkata')->startOfDay();
        $todayIst = CarbonImmutable::now('Asia/Kolkata')->startOfDay();
        $deadline = $this->invoices->statutoryDeadlineFromPayment($paymentDate);
        $maxDate = $todayIst->lessThan($deadline) ? $todayIst : $deadline;
        $defaultDate = $maxDate->lessThan($paymentDate) ? $paymentDate : $maxDate;

        $activeProfiles = ! $isApproved
            ? $this->billingProfiles->listActive()->map(function (PlatformBillingProfile $profile) use ($invoice): array {
                $compat = $this->invoices->checkBillingProfileCompatibility($invoice, $profile);

                return [
                    'id' => $profile->id,
                    'legal_name' => $profile->legal_name,
                    'pan' => $profile->pan,
                    'gstin' => $profile->gstin,
                    'gst_rate_percent' => (string) $profile->gst_rate_percent,
                    'registered_address' => $profile->registered_address,
                    'city' => $profile->city,
                    'state_name' => $profile->state_name,
                    'state_code' => $profile->state_code,
                    'pin_code' => $profile->pin_code,
                    'contact_number' => $profile->contact_number,
                    'email' => $profile->email,
                    'bank_name' => $profile->bank_name,
                    'bank_account_name' => $profile->bank_account_name,
                    'bank_account_number' => $profile->bank_account_number,
                    'bank_ifsc' => $profile->bank_ifsc,
                    'website' => $profile->website,
                    'notes_terms' => $profile->notes_terms,
                    'compatible' => $compat['compatible'],
                    'compatibility_message' => $compat['message'],
                ];
            })->values()->all()
            : [];

        return Inertia::render('admin/subscription-invoice-show', [
            'document' => $this->documents->presentation($invoice),
            'canManage' => $canManage,
            'activeProfiles' => $activeProfiles,
            'approvalDefaults' => [
                'invoice_date' => $defaultDate->toDateString(),
                'min_date' => $paymentDate->toDateString(),
                'max_date' => $maxDate->toDateString(),
                'payment_date' => $paymentDate->toDateString(),
                'statutory_deadline' => $deadline->toDateString(),
                'window_exceeded' => $todayIst->greaterThan($deadline),
            ],
            'links' => [
                'index' => route('admin.subscription-invoices'),
                'approve' => ! $isApproved && $canManage ? route('admin.subscription-invoices.approve', $invoice) : null,
                'download' => $isApproved ? route('admin.subscription-invoices.download', $invoice) : null,
                'credit_note' => $invoice->document_type === 'tax_invoice' && $isApproved && $canManage
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

        if (! $invoice->isApprovedForCustomer()) {
            abort(403, 'Subscription invoice must be approved before download.');
        }

        $html = $this->documents->renderHtml($invoice);
        $number = (string) ($invoice->invoice_number ?? $invoice->approved_number ?? $invoice->id);
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
            'billing_profile_id' => ['required', 'uuid'],
            'invoice_number' => ['required', 'string', 'max:64'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $approved = $this->invoices->approveInvoice(
                $actor,
                $invoice,
                $validated['billing_profile_id'],
                $validated['invoice_number'],
                $validated['invoice_date'],
            );
            $this->documents->dispatchEmail($approved);
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['approval' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('admin.subscription-invoices.show', $approved)
            ->with('success', 'Subscription invoice '.$approved->invoice_number.' approved and made available to Customer.');
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
