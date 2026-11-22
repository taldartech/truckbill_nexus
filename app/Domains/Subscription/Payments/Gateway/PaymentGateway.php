<?php

namespace App\Domains\Subscription\Payments\Gateway;

use App\Models\PaymentIntent;

/**
 * Gateway adapter boundary. Official Razorpay PHP SDK for Test Mode (PO-P3-PAY-TEST).
 * Live Razorpay requires PO-P3-PAY-PROD.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{order_id: string, amount: string, currency: string, meta: array<string, mixed>}
     */
    public function createOrder(PaymentIntent $intent, array $attributes = []): array;

    /**
     * Verify checkout/payment signature material (not webhook).
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCheckoutSignature(array $payload, string $signature): bool;

    /**
     * Verify webhook signature over the raw body.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature, string $secret): bool;
}
