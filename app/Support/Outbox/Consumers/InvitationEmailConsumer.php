<?php

namespace App\Support\Outbox\Consumers;

use App\Mail\InvitationMail;
use App\Models\Company;
use App\Models\Invitation;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class InvitationEmailConsumer implements OutboxConsumer
{
    public function handle(OutboxMessage $message): void
    {
        $payload = $message->payload;
        $invitationId = $payload['invitation_id'] ?? null;
        $email = $payload['email'] ?? null;
        $acceptUrl = $payload['accept_url'] ?? null;

        if (! is_string($invitationId) || ! is_string($email) || ! is_string($acceptUrl)) {
            throw new RuntimeException('Invitation email payload is incomplete.');
        }

        if (str_contains($acceptUrl, '[redacted]')) {
            throw new RuntimeException('Invitation accept URL was redacted and cannot be delivered.');
        }

        $invitation = Invitation::query()->find($invitationId);
        if ($invitation === null) {
            throw new RuntimeException('Invitation not found for email delivery.');
        }

        $company = Company::query()->find($invitation->company_id);
        $companyName = $company instanceof Company ? $company->name : 'TruckBill';

        Mail::to($email)->send(new InvitationMail($acceptUrl, $companyName));
    }
}
