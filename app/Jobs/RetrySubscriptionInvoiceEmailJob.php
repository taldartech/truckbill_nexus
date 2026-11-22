<?php

namespace App\Jobs;

use App\Domains\Subscription\Billing\SubscriptionInvoiceDocumentService;
use App\Models\SubscriptionInvoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Retry failed subscription invoice email. Never reverses payment or number approval.
 */
class RetrySubscriptionInvoiceEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $invoiceId,
    ) {}

    public function handle(SubscriptionInvoiceDocumentService $documents): void
    {
        $invoice = SubscriptionInvoice::query()->whereKey($this->invoiceId)->first();
        if ($invoice === null) {
            return;
        }

        if ($invoice->email_status === 'sent') {
            return;
        }

        if (! in_array($invoice->status, ['approved', 'issued', 'emailed'], true)) {
            return;
        }

        $documents->dispatchEmail($invoice);
    }
}
