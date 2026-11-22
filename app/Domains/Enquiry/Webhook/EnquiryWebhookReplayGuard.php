<?php

namespace App\Domains\Enquiry\Webhook;

use App\Domains\Enquiry\Exceptions\EnquiryWebhookReplayException;
use App\Models\Company;
use App\Models\EnquiryWebhookNonce;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Freshness + nonce replay protection. Legitimate idempotent retries reuse the same nonce
 * after idempotency short-circuit; first-time nonce consumption happens only for new work.
 */
final class EnquiryWebhookReplayGuard
{
    public function assertFreshTimestamp(Request $request): Carbon
    {
        $raw = trim((string) $request->header('X-TruckBill-Timestamp', ''));
        if ($raw === '' || ! ctype_digit($raw)) {
            throw new EnquiryWebhookReplayException('X-TruckBill-Timestamp header is required (unix seconds).');
        }

        $timestamp = Carbon::createFromTimestampUTC((int) $raw);
        $skew = (int) config('enquiry_webhook.timestamp_skew_seconds', 300);
        $now = Carbon::now('UTC');

        if (abs($now->diffInSeconds($timestamp, false)) > $skew) {
            throw new EnquiryWebhookReplayException('Request timestamp is outside the accepted freshness window.');
        }

        return $timestamp;
    }

    public function consumeNonce(Company $company, Request $request): void
    {
        $nonce = trim((string) $request->header('X-TruckBill-Nonce', ''));
        if ($nonce === '') {
            throw new EnquiryWebhookReplayException('X-TruckBill-Nonce header is required.');
        }

        if (strlen($nonce) > 128) {
            throw new EnquiryWebhookReplayException('Nonce is too long.');
        }

        $ttl = (int) config('enquiry_webhook.nonce_ttl_seconds', 600);

        // Nested transaction / savepoint so a unique violation does not abort the outer PG transaction
        // (IdempotencyGuard still needs to mark the record failed cleanly).
        try {
            DB::transaction(function () use ($company, $nonce, $ttl): void {
                EnquiryWebhookNonce::query()->create([
                    'company_id' => $company->id,
                    'nonce' => $nonce,
                    'expires_at' => now()->addSeconds($ttl),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new EnquiryWebhookReplayException('Replay detected: nonce already used.', previous: $e);
        }
    }
}
