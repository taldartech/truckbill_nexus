<?php

namespace App\Domains\Subscription\Payments\Gateway;

use App\Models\PaymentIntent;
use Illuminate\Support\Str;

/**
 * Deterministic non-production gateway for tests and local simulation.
 * Selected automatically in the testing environment or when Test Mode credentials are absent.
 * Never represents genuine production settlement.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /**
     * Fixed secret for CI / local fake webhooks — not configurable via env.
     */
    public const WEBHOOK_SECRET = 'test-webhook-secret-not-for-production';

    public function name(): string
    {
        return 'fake';
    }

    public function createOrder(PaymentIntent $intent, array $attributes = []): array
    {
        $orderId = 'order_fake_'.Str::lower(Str::random(12));

        return [
            'order_id' => $orderId,
            'amount' => (string) $intent->amount,
            'currency' => $intent->currency,
            'meta' => [
                'simulated' => true,
                'payment_intent_id' => $intent->id,
            ],
        ];
    }

    public function verifyCheckoutSignature(array $payload, string $signature): bool
    {
        $orderId = (string) ($payload['razorpay_order_id'] ?? $payload['order_id'] ?? '');
        $paymentId = (string) ($payload['razorpay_payment_id'] ?? $payload['payment_id'] ?? '');
        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, $this->secret());

        return hash_equals($expected, $signature);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    public function secret(): string
    {
        return self::WEBHOOK_SECRET;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{raw: string, signature: string}
     */
    public function signWebhook(array $event): array
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);

        return [
            'raw' => $raw,
            'signature' => hash_hmac('sha256', $raw, $this->secret()),
        ];
    }
}
