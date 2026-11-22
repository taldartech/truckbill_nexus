<?php

namespace App\Domains\Subscription\Payments\Gateway;

/**
 * Official Razorpay PHP SDK boundary — mockable in CI without network I/O.
 */
interface RazorpayClient
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createOrder(array $attributes): array;

    /**
     * @return array<string, mixed>
     */
    public function fetchPayment(string $paymentId): array;

    /**
     * @param  array{razorpay_order_id: string, razorpay_payment_id: string, razorpay_signature: string}  $attributes
     */
    public function verifyPaymentSignature(array $attributes): void;

    public function verifyWebhookSignature(string $rawBody, string $signature, string $webhookSecret): void;
}
