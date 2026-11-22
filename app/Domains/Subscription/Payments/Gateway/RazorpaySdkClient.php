<?php

namespace App\Domains\Subscription\Payments\Gateway;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use Razorpay\Api\Api;
use Razorpay\Api\Entity;
use Razorpay\Api\Order;
use Razorpay\Api\Payment;
use Razorpay\Api\Utility;

/**
 * Thin adapter over razorpay/razorpay official SDK.
 *
 * Instantiates SDK resource classes after Api credentials are bound
 * (Order/Payment/Utility read static key material from Api::__construct).
 */
final class RazorpaySdkClient implements RazorpayClient
{
    public function __construct(
        private readonly Api $api,
    ) {}

    public function createOrder(array $attributes): array
    {
        $this->assertCredentialsBound();

        return $this->entityToArray((new Order)->create($attributes));
    }

    public function fetchPayment(string $paymentId): array
    {
        $this->assertCredentialsBound();

        return $this->entityToArray((new Payment)->fetch($paymentId));
    }

    public function verifyPaymentSignature(array $attributes): void
    {
        $this->assertCredentialsBound();
        (new Utility)->verifyPaymentSignature($attributes);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature, string $webhookSecret): void
    {
        $this->assertCredentialsBound();
        (new Utility)->verifyWebhookSignature($rawBody, $signature, $webhookSecret);
    }

    private function assertCredentialsBound(): void
    {
        $key = (string) $this->api::getKey();
        if ($key === '') {
            throw new SubscriptionDomainException('Razorpay SDK credentials are not bound.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function entityToArray(mixed $entity): array
    {
        if ($entity instanceof Entity) {
            return $entity->toArray();
        }

        if (is_array($entity)) {
            return $entity;
        }

        return [];
    }
}
