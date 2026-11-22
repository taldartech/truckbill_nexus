<?php

namespace App\Domains\Subscription\Billing;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Mail\SubscriptionInvoiceMail;
use App\Models\Company;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Non-production subscription invoice HTML document + email dispatch.
 * No PDF package dependency (PO-P1-DEPS). Not a legally operative tax invoice (issuer/GST Pending).
 * Email failures must not reverse payment or number approval.
 */
final class SubscriptionInvoiceDocumentService
{
    public function __construct(
        private readonly SubscriptionInvoiceService $invoices = new SubscriptionInvoiceService,
    ) {}

    /**
     * @return array{
     *     sample_banner: string,
     *     company: array{id: string, name: string},
     *     invoice: array<string, mixed>,
     *     line_items: list<array<string, mixed>>,
     *     payment_reference: string|null,
     *     totals: array{amount: string, currency: string}
     * }
     */
    public function presentation(SubscriptionInvoice $invoice): array
    {
        $company = Company::query()->whereKey($invoice->company_id)->firstOrFail();
        $lineItems = is_array($invoice->line_items) ? $invoice->line_items : [];
        if ($lineItems === []) {
            $lineItems = [[
                'description' => 'Subscription charges (itemized breakdown pending issuer validation)',
                'amount' => (string) $invoice->amount,
                'currency' => $invoice->currency,
            ]];
        }

        return [
            'sample_banner' => 'SAMPLE / NON-PRODUCTION — Not a legally operative tax invoice. Issuer and GST details remain Pending validation (TB-TAX).',
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
            ],
            'invoice' => [
                'id' => $invoice->id,
                'status' => $invoice->status,
                'proposed_number' => $invoice->proposed_number,
                'approved_number' => $invoice->approved_number,
                'financial_year' => $invoice->financial_year,
                'amount' => (string) $invoice->amount,
                'currency' => $invoice->currency,
                'email_status' => $invoice->email_status,
                'approved_at' => $invoice->approved_at?->toIso8601String(),
                'emailed_at' => $invoice->emailed_at?->toIso8601String(),
                'created_at' => $invoice->created_at?->toIso8601String(),
            ],
            'line_items' => array_values($lineItems),
            'payment_reference' => $invoice->payment_intent_id,
            'totals' => [
                'amount' => (string) $invoice->amount,
                'currency' => $invoice->currency,
            ],
        ];
    }

    public function renderHtml(SubscriptionInvoice $invoice): string
    {
        $data = $this->presentation($invoice);
        $lines = '';
        foreach ($data['line_items'] as $item) {
            $desc = e((string) ($item['description'] ?? $item['name'] ?? 'Line item'));
            $amt = e((string) ($item['amount'] ?? ''));
            $cur = e((string) ($item['currency'] ?? $data['totals']['currency']));
            $lines .= "<tr><td style=\"padding:8px;border-bottom:1px solid #ddd;\">{$desc}</td>"
                ."<td style=\"padding:8px;border-bottom:1px solid #ddd;text-align:right;\">{$cur} {$amt}</td></tr>";
        }

        $number = e((string) ($data['invoice']['approved_number'] ?? $data['invoice']['proposed_number'] ?? 'DRAFT'));
        $status = e((string) $data['invoice']['status']);
        $company = e($data['company']['name']);
        $fy = e((string) $data['invoice']['financial_year']);
        $payRef = e((string) ($data['payment_reference'] ?? '—'));
        $total = e($data['totals']['currency'].' '.$data['totals']['amount']);
        $banner = e($data['sample_banner']);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Subscription invoice {$number}</title>
  <style>
    body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 2rem; color: #111; }
    .banner { background: #fff3cd; border: 1px solid #ffc107; padding: 0.75rem 1rem; margin-bottom: 1.5rem; font-size: 0.9rem; }
    h1 { font-size: 1.5rem; margin: 0 0 0.5rem; }
    table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
    .meta td { padding: 0.25rem 0.5rem 0.25rem 0; }
    @media print { .no-print { display: none; } }
  </style>
</head>
<body>
  <div class="banner">{$banner}</div>
  <h1>TruckBill subscription invoice</h1>
  <p class="no-print"><button onclick="window.print()">Print / Save as PDF</button></p>
  <table class="meta">
    <tr><td>Company</td><td><strong>{$company}</strong></td></tr>
    <tr><td>Invoice number</td><td><strong>{$number}</strong></td></tr>
    <tr><td>Status</td><td>{$status}</td></tr>
    <tr><td>Financial year</td><td>{$fy}</td></tr>
    <tr><td>Payment reference</td><td>{$payRef}</td></tr>
  </table>
  <table>
    <thead><tr><th style="text-align:left;padding:8px;border-bottom:2px solid #111;">Description</th>
    <th style="text-align:right;padding:8px;border-bottom:2px solid #111;">Amount</th></tr></thead>
    <tbody>{$lines}</tbody>
    <tfoot><tr><td style="padding:12px 8px;font-weight:bold;">Total</td>
    <td style="padding:12px 8px;text-align:right;font-weight:bold;">{$total}</td></tr></tfoot>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Dispatch sample invoice email to company owner. Does not reverse approval on failure.
     */
    public function dispatchEmail(SubscriptionInvoice $invoice, ?User $recipient = null): SubscriptionInvoice
    {
        if (! in_array($invoice->status, ['approved', 'issued', 'emailed'], true)) {
            throw new SubscriptionDomainException('Invoice must be approved before email dispatch.');
        }

        $company = Company::query()->whereKey($invoice->company_id)->firstOrFail();
        $owner = $recipient ?? User::query()->whereKey($company->owner_user_id)->first();
        if ($owner === null || ! filled($owner->email)) {
            return $this->invoices->recordEmailAttempt($invoice, false, 'Owner email unavailable');
        }

        try {
            $html = $this->renderHtml($invoice);
            Mail::to($owner->email)->send(new SubscriptionInvoiceMail(
                companyName: $company->name,
                invoiceNumber: (string) ($invoice->approved_number ?? $invoice->proposed_number ?? $invoice->id),
                htmlBody: $html,
            ));

            if ($invoice->status === 'approved') {
                $this->invoices->markIssued($invoice);
                $invoice = $invoice->fresh() ?? $invoice;
            }

            return $this->invoices->recordEmailAttempt($invoice, true);
        } catch (\Throwable $e) {
            return $this->invoices->recordEmailAttempt($invoice, false, $e->getMessage());
        }
    }
}
