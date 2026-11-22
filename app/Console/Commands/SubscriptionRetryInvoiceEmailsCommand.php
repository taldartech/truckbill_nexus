<?php

namespace App\Console\Commands;

use App\Jobs\RetrySubscriptionInvoiceEmailJob;
use App\Models\SubscriptionInvoice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscription:retry-invoice-emails')]
#[Description('Queue retries for approved invoices with failed email delivery')]
class SubscriptionRetryInvoiceEmailsCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        SubscriptionInvoice::query()
            ->whereIn('status', ['approved', 'issued'])
            ->where('email_status', 'failed')
            ->orderBy('id')
            ->chunkById(50, function ($invoices) use (&$queued): void {
                foreach ($invoices as $invoice) {
                    RetrySubscriptionInvoiceEmailJob::dispatch($invoice->id);
                    $queued++;
                }
            });

        $this->info("Queued invoice email retries: {$queued}");

        return self::SUCCESS;
    }
}
