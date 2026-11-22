<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $companyName,
        public readonly string $invoiceNumber,
        public readonly string $htmlBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[SAMPLE] TruckBill subscription invoice '.$this->invoiceNumber,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Company: <strong>'.e($this->companyName)
                .'</strong></p><p>This is a <strong>sample / non-production</strong> subscription invoice email. '
                .'It is not a legally operative tax invoice.</p>'
                .'<hr>'.$this->htmlBody,
        );
    }
}
