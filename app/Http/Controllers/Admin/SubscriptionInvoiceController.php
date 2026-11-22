<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Subscription\Billing\SubscriptionInvoiceService;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin invoice-number approval for non-production subscription invoices.
 * Legally operative production tax issuance remains gated (issuer/GST Pending).
 */
class SubscriptionInvoiceController extends Controller
{
    public function __construct(
        private readonly SubscriptionInvoiceService $invoices = new SubscriptionInvoiceService,
    ) {}

    public function index(Request $request): Response
    {
        $this->assertSuperAdmin($request);

        $awaiting = SubscriptionInvoice::query()
            ->with('company:id,name')
            ->where('status', 'awaiting_number_approval')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('admin/subscription-invoices', [
            'issuer_note' => 'Non-production number approval only. Production legally operative invoices require validated issuer/GST (Pending). ADR-010 accounting postings are not performed.',
            'invoices' => $awaiting->map(fn (SubscriptionInvoice $invoice): array => [
                'id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'company_name' => $invoice->company?->name,
                'status' => $invoice->status,
                'proposed_number' => $invoice->proposed_number,
                'financial_year' => $invoice->financial_year,
                'amount' => (string) $invoice->amount,
                'currency' => $invoice->currency,
                'payment_intent_id' => $invoice->payment_intent_id,
                'created_at' => $invoice->created_at?->toIso8601String(),
                'approve_url' => route('admin.subscription-invoices.approve', $invoice),
            ])->values()->all(),
        ]);
    }

    public function approve(Request $request, SubscriptionInvoice $invoice): RedirectResponse
    {
        $actor = $this->assertSuperAdmin($request);

        $validated = $request->validate([
            'approved_number' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $this->invoices->approveNumber(
                $actor,
                $invoice,
                $validated['approved_number'] ?? null,
            );
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['approved_number' => $e->getMessage()]);
        }

        return back()->with('success', 'Invoice number approved (immutable). Email/issuance may proceed separately.');
    }

    private function assertSuperAdmin(Request $request): User
    {
        /** @var User|null $actor */
        $actor = $request->user();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            abort(403, 'Super Admin required.');
        }

        return $actor;
    }
}
