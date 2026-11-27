<?php

namespace App\Domains\Subscription\Billing;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Mail\SubscriptionInvoiceMail;
use App\Models\Company;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\Files\PrivateFileService;
use App\Support\Files\StoredFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Renders immutable Subscription Tax Invoices and Credit Notes from stored approval snapshots.
 * Never exposes unapproved documents or reads mutable Billing Profile records.
 */
final class SubscriptionInvoiceDocumentService
{
    public function __construct(
        private readonly SubscriptionInvoiceService $invoices = new SubscriptionInvoiceService,
        private readonly PlatformBillingProfileService $billingProfiles = new PlatformBillingProfileService,
        private readonly PrivateFileService $files = new PrivateFileService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function presentation(SubscriptionInvoice $invoice): array
    {
        $company = Company::query()->whereKey($invoice->company_id)->firstOrFail();

        $supplier = is_array($invoice->supplier_snapshot) ? $invoice->supplier_snapshot : null;
        $customer = is_array($invoice->customer_snapshot)
            ? $invoice->customer_snapshot
            : $this->billingProfiles->customerSnapshot($company);

        $lineItems = is_array($invoice->line_items) ? $invoice->line_items : [];
        if ($lineItems === []) {
            $lineItems = [[
                'description' => 'TruckBill Subscription Charges',
                'quantity' => 1,
                'amount' => (string) ($invoice->taxable_amount ?: $invoice->amount),
                'currency' => $invoice->currency,
            ]];
        }

        $isApproved = $invoice->isApprovedForCustomer();
        $number = $isApproved
            ? (string) ($invoice->invoice_number ?? $invoice->approved_number ?? $invoice->id)
            : null;
        $hasSupplierProfile = $supplier !== null && filled($supplier['legal_name'] ?? null);

        $logoDataUri = $this->resolveLogoDataUri($supplier);
        $pricing = is_array($invoice->pricing_snapshot) ? $invoice->pricing_snapshot : [];
        $approverName = (string) ($pricing['approval_actor_name'] ?? (
            $invoice->approved_by !== null
                ? User::query()->whereKey($invoice->approved_by)->value('name')
                : ''
        ));

        return [
            'configured' => $hasSupplierProfile,
            'is_approved' => $isApproved,
            'document_title' => $invoice->document_type === 'credit_note'
                ? 'Tax Credit Note'
                : ($hasSupplierProfile && filled($supplier['gstin'] ?? null) ? 'Tax Invoice' : 'Subscription Invoice'),
            'banner' => ! $isApproved
                ? 'Invoice Pending Approval — review details, select a Billing Profile, and assign an Invoice Number to issue.'
                : (! $hasSupplierProfile ? 'Supplier billing snapshot unavailable.' : null),
            'supplier' => $supplier,
            'supplier_logo_data_uri' => $logoDataUri,
            'customer' => $customer,
            'company' => [
                'id' => $company->id,
                'name' => $customer['legal_name'] ?? $company->name,
            ],
            'invoice' => [
                'id' => $invoice->id,
                'document_type' => $invoice->document_type,
                'invoice_number' => $number,
                'original_invoice_id' => $invoice->original_invoice_id,
                'billing_profile_id' => $invoice->billing_profile_id,
                'status' => $invoice->status,
                'proposed_number' => $invoice->proposed_number,
                'approved_number' => $invoice->approved_number,
                'financial_year' => $invoice->financial_year,
                'invoice_date' => $invoice->invoice_date?->toDateString()
                    ?? ($pricing['invoice_date'] ?? null)
                    ?? ($isApproved ? ($invoice->issued_at ?? $invoice->approved_at)?->toDateString() : null),
                'purchase_type' => $invoice->purchase_type,
                'sac_code' => $invoice->sac_code ?? config('subscription.billing.default_sac_code', '998314'),
                'service_period_start' => $invoice->service_period_start?->toDateString(),
                'service_period_end' => $invoice->service_period_end?->toDateString(),
                'place_of_supply_state' => $invoice->place_of_supply_state,
                'place_of_supply_code' => $invoice->place_of_supply_code,
                'is_inter_state' => (bool) $invoice->is_inter_state,
                'credit_note_reason' => $invoice->credit_note_reason,
                'issued_at' => $isApproved
                    ? ($invoice->invoice_date?->toDateString() ?? ($invoice->issued_at ?? $invoice->approved_at)?->toDateString())
                    : null,
                'approved_at' => $invoice->approved_at?->toIso8601String(),
                'approved_by_name' => $approverName !== '' ? $approverName : null,
                'payment_date' => $this->invoices->paymentTimestamp($invoice)->toDateString(),
                'statutory_deadline' => $this->invoices->statutoryDeadlineFromPayment($this->invoices->paymentTimestamp($invoice))->toDateString(),
                'email_status' => $invoice->email_status,
                'emailed_at' => $invoice->emailed_at?->toIso8601String(),
                'created_at' => $invoice->created_at?->toIso8601String(),
            ],
            'line_items' => array_values($lineItems),
            'pricing_snapshot' => $invoice->pricing_snapshot,
            'payment_reference' => $invoice->payment_reference ?? $invoice->payment_intent_id,
            'totals' => [
                'subtotal_amount' => (string) ($invoice->subtotal_amount ?: $invoice->amount),
                'discount_amount' => (string) ($invoice->discount_amount ?? '0.00'),
                'taxable_amount' => (string) ($invoice->taxable_amount ?: $invoice->amount),
                'gst_rate_percent' => (string) ($invoice->gst_rate_percent ?? '0.00'),
                'cgst_amount' => (string) ($invoice->cgst_amount ?? '0.00'),
                'sgst_amount' => (string) ($invoice->sgst_amount ?? '0.00'),
                'igst_amount' => (string) ($invoice->igst_amount ?? '0.00'),
                'total_tax_amount' => (string) ($invoice->total_tax_amount ?? '0.00'),
                'amount' => (string) $invoice->amount,
                'currency' => $invoice->currency,
            ],
        ];
    }

    public function renderHtml(SubscriptionInvoice $invoice): string
    {
        if (! $invoice->isApprovedForCustomer()) {
            throw new SubscriptionDomainException('Unapproved invoices cannot be rendered or downloaded.');
        }

        $data = $this->presentation($invoice);
        $inv = $data['invoice'];
        $sup = $data['supplier'];
        $cus = $data['customer'];
        $totals = $data['totals'];
        $cur = e((string) $totals['currency']);

        $lines = '';
        foreach ($data['line_items'] as $idx => $item) {
            $sr = $idx + 1;
            $desc = e((string) ($item['description'] ?? $item['name'] ?? 'Subscription Item'));
            $qty = e((string) ($item['quantity'] ?? 1));
            $sac = e((string) ($item['sac_code'] ?? $inv['sac_code'] ?? '998314'));
            $amt = e((string) ($item['amount'] ?? '0.00'));
            $lines .= "<tr><td>{$sr}</td><td>{$desc}</td><td>{$sac}</td><td class=\"r\">{$qty}</td><td class=\"r\">{$cur} {$amt}</td></tr>";
        }

        $logoHtml = $data['supplier_logo_data_uri']
            ? '<img src="'.e((string) $data['supplier_logo_data_uri']).'" alt="Supplier Logo" style="max-height:56px;max-width:180px;object-fit:contain;margin-bottom:8px;">'
            : '';

        $supplierBlock = $sup !== null
            ? '<strong>'.e((string) ($sup['legal_name'] ?? '')).'</strong>'
              .'<br>'.nl2br(e((string) ($sup['registered_address'] ?? '')))
              .'<br>'.e(trim(($sup['city'] ?? '').', '.($sup['state_name'] ?? '').' - '.($sup['pin_code'] ?? '')))
              .(filled($sup['gstin'] ?? null) ? '<br>GSTIN: <strong>'.e((string) $sup['gstin']).'</strong>' : '')
              .(filled($sup['pan'] ?? null) ? ' · PAN: '.e((string) $sup['pan']) : '')
              .'<br>Contact: '.e((string) ($sup['contact_number'] ?? '')).' · '.e((string) ($sup['email'] ?? ''))
              .(filled($sup['website'] ?? null) ? '<br>Website: '.e((string) $sup['website']) : '')
            : '<em>Supplier billing snapshot unavailable</em>';

        $customerAddress = implode(', ', array_filter([
            $cus['registered_address'] ?? null,
            $cus['city'] ?? null,
            $cus['state'] ?? null,
            $cus['pin_code'] ?? null,
        ]));

        $customerBlock = '<strong>'.e((string) ($cus['legal_name'] ?? '')).'</strong>'
            .($customerAddress !== '' ? '<br>'.e($customerAddress) : '')
            .(filled($cus['gstin'] ?? null) ? '<br>GSTIN: <strong>'.e((string) $cus['gstin']).'</strong>' : '<br>GSTIN: Unregistered')
            .(filled($cus['pan'] ?? null) ? ' · PAN: '.e((string) $cus['pan']) : '')
            .(filled($cus['contact_number'] ?? null) || filled($cus['email'] ?? null)
                ? '<br>Contact: '.e(trim(($cus['contact_number'] ?? '').' '.($cus['email'] ?? '')))
                : '');

        $taxRows = '';
        if ((float) $totals['igst_amount'] > 0) {
            $taxRows .= "<tr><td colspan=\"4\" class=\"r\">IGST ({$totals['gst_rate_percent']}%)</td><td class=\"r\">{$cur} {$totals['igst_amount']}</td></tr>";
        } elseif ((float) $totals['cgst_amount'] > 0 || (float) $totals['sgst_amount'] > 0) {
            $halfRate = number_format(((float) $totals['gst_rate_percent']) / 2, 2);
            $taxRows .= "<tr><td colspan=\"4\" class=\"r\">CGST ({$halfRate}%)</td><td class=\"r\">{$cur} {$totals['cgst_amount']}</td></tr>";
            $taxRows .= "<tr><td colspan=\"4\" class=\"r\">SGST ({$halfRate}%)</td><td class=\"r\">{$cur} {$totals['sgst_amount']}</td></tr>";
        }

        $bankHtml = '';
        if ($sup !== null && (filled($sup['bank_name'] ?? null) || filled($sup['bank_account_number'] ?? null))) {
            $bankHtml = '<div class="box" style="margin-top:16px;"><strong>Bank Details</strong><br>'
                .'Bank: '.e((string) ($sup['bank_name'] ?? '—'))
                .' · Account Holder: '.e((string) ($sup['bank_account_name'] ?? '—'))
                .' · Account No: '.e((string) ($sup['bank_account_number'] ?? '—'))
                .' · IFSC: '.e((string) ($sup['bank_ifsc'] ?? '—'))
                .'</div>';
        }

        $notesHtml = '';
        if ($sup !== null && filled($sup['notes_terms'] ?? null)) {
            $notesHtml = '<div class="box" style="margin-top:12px;"><strong>Notes / Terms</strong><br>'
                .nl2br(e((string) $sup['notes_terms']))
                .'</div>';
        }

        $title = e((string) $data['document_title']);
        $number = e((string) $inv['invoice_number']);
        $issuedDate = e((string) ($inv['invoice_date'] ?? $inv['issued_at'] ?? '—'));
        $period = ($inv['service_period_start'] && $inv['service_period_end'])
            ? e((string) $inv['service_period_start'].' to '.(string) $inv['service_period_end'])
            : e((string) ($inv['financial_year'] ?? '—'));
        $pos = e(trim(($inv['place_of_supply_state'] ?? '—').($inv['place_of_supply_code'] ? ' ('.$inv['place_of_supply_code'].')' : '')));
        $payRef = e((string) ($data['payment_reference'] ?? '—'));
        $reasonBlock = filled($inv['credit_note_reason'] ?? null)
            ? '<p><strong>Credit Note Reason:</strong> '.e((string) $inv['credit_note_reason']).'</p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$title} {$number}</title>
  <style>
    body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 2rem; color: #111; line-height: 1.45; font-size: 13px; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
    .box { border: 1px solid #d4d4d8; border-radius: 6px; padding: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th, td { padding: 8px; border-bottom: 1px solid #e4e4e7; text-align: left; }
    th { background: #f4f4f5; font-weight: 600; }
    .r { text-align: right; }
    tfoot td { font-weight: 600; }
    .grand td { font-size: 15px; font-weight: 700; border-top: 2px solid #111; }
    @media print { .no-print { display: none; } body { margin: 0.75rem; } }
  </style>
</head>
<body>
  <div class="header">
    <div>
      {$logoHtml}
      <h1 style="margin:0;font-size:20px;">{$title}</h1>
      <p style="margin:4px 0 0;color:#52525b;">Invoice No: <strong>{$number}</strong> · Date: {$issuedDate}</p>
    </div>
    <div style="text-align:right;">
      <p class="no-print" style="margin:0 0 8px;"><button onclick="window.print()">Print / Save as PDF</button></p>
      <p style="margin:0;">Financial Year: <strong>{$inv['financial_year']}</strong></p>
      <p style="margin:2px 0 0;">Service Period: {$period}</p>
      <p style="margin:2px 0 0;">Place of Supply: {$pos}</p>
      <p style="margin:2px 0 0;">Payment Ref: {$payRef}</p>
    </div>
  </div>
  {$reasonBlock}
  <div class="grid">
    <div class="box"><div style="font-size:11px;text-transform:uppercase;color:#71717a;margin-bottom:4px;">Supplier</div>{$supplierBlock}</div>
    <div class="box"><div style="font-size:11px;text-transform:uppercase;color:#71717a;margin-bottom:4px;">Billed To (Customer Company)</div>{$customerBlock}</div>
  </div>
  <table>
    <thead>
      <tr><th>#</th><th>Description</th><th>SAC</th><th class="r">Qty</th><th class="r">Amount</th></tr>
    </thead>
    <tbody>{$lines}</tbody>
    <tfoot>
      <tr><td colspan="4" class="r">Subtotal</td><td class="r">{$cur} {$totals['subtotal_amount']}</td></tr>
      <tr><td colspan="4" class="r">Discounts</td><td class="r">- {$cur} {$totals['discount_amount']}</td></tr>
      <tr><td colspan="4" class="r">Taxable Value</td><td class="r">{$cur} {$totals['taxable_amount']}</td></tr>
      {$taxRows}
      <tr class="grand"><td colspan="4" class="r">Grand Total</td><td class="r">{$cur} {$totals['amount']}</td></tr>
    </tfoot>
  </table>
  {$bankHtml}
  {$notesHtml}
</body>
</html>
HTML;
    }

    public function dispatchEmail(SubscriptionInvoice $invoice, ?User $recipient = null): SubscriptionInvoice
    {
        if (! $invoice->isApprovedForCustomer()) {
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
                invoiceNumber: (string) ($invoice->invoice_number ?? $invoice->approved_number ?? $invoice->id),
                htmlBody: $html,
            ));

            return $this->invoices->recordEmailAttempt($invoice, true);
        } catch (\Throwable $e) {
            return $this->invoices->recordEmailAttempt($invoice, false, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>|null  $supplier
     */
    private function resolveLogoDataUri(?array $supplier): ?string
    {
        if ($supplier === null) {
            return null;
        }

        $snapDisk = $supplier['logo_snapshot_disk'] ?? null;
        $snapPath = $supplier['logo_snapshot_path'] ?? null;
        if (is_string($snapDisk) && is_string($snapPath) && $snapPath !== '' && Storage::disk($snapDisk)->exists($snapPath)) {
            $raw = Storage::disk($snapDisk)->get($snapPath);
            if ($raw !== null) {
                $mime = (string) ($supplier['logo_snapshot_mime'] ?? 'image/png');

                return 'data:'.$mime.';base64,'.base64_encode($raw);
            }
        }

        if (filled($supplier['logo_stored_file_id'] ?? null)) {
            $file = StoredFile::query()->whereKey((string) $supplier['logo_stored_file_id'])->first();
            if ($file !== null) {
                $bytes = $this->files->readPlatformFileBytes($file);
                if ($bytes !== null) {
                    return 'data:'.$bytes['mime'].';base64,'.base64_encode($bytes['contents']);
                }
            }
        }

        if (filled($supplier['logo_path'] ?? null)) {
            $logoPath = (string) $supplier['logo_path'];
            if (Storage::disk('local')->exists($logoPath)) {
                $mime = Storage::disk('local')->mimeType($logoPath) ?: 'image/png';
                $raw = Storage::disk('local')->get($logoPath);
                if ($raw !== null) {
                    return 'data:'.$mime.';base64,'.base64_encode($raw);
                }
            }
        }

        return null;
    }
}
