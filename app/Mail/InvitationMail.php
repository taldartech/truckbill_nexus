<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $acceptUrl,
        public readonly string $companyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You are invited to TruckBill',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>You have been invited to join <strong>'
                .e($this->companyName)
                .'</strong> on TruckBill.</p><p><a href="'
                .e($this->acceptUrl)
                .'">Accept invitation</a></p><p>This link is single-use and time-limited.</p>',
        );
    }
}
