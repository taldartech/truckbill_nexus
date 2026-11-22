<?php

namespace App\Domains\Subscription\Payments;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\Support\RazorpayMoney;

/**
 * Maps verified Razorpay webhook JSON into the domain event shape.
 *
 * @phpstan-type DomainEvent array{
 *     event_id: string,
 *     event_type: string,
 *     order_id: string,
 *     payment_id: string,
 *     status: string,
 *     amount: string,
 *     currency: string
 * }
 */
final class RazorpayWebhookNormalizer
{
    /**
     * @param  array<string, mixed>  $decoded
     * @return DomainEvent
     */
    public function normalize(array $decoded, ?string $deliveryEventId = null): array
    {
        $eventType = (string) ($decoded['event'] ?? '');
        $payloadEntity = $decoded['payload'] ?? null;
        if (! is_array($payloadEntity)) {
            throw new SubscriptionDomainException('Razorpay webhook payload entity missing.');
        }

        $payment = null;
        $order = null;
        if (isset($payloadEntity['payment']['entity']) && is_array($payloadEntity['payment']['entity'])) {
            $payment = $payloadEntity['payment']['entity'];
        }
        if (isset($payloadEntity['order']['entity']) && is_array($payloadEntity['order']['entity'])) {
            $order = $payloadEntity['order']['entity'];
        }

        $paymentId = (string) ($payment['id'] ?? '');
        $orderId = (string) ($payment['order_id'] ?? $order['id'] ?? '');
        $statusRaw = (string) ($payment['status'] ?? $order['status'] ?? '');
        $currency = strtoupper((string) ($payment['currency'] ?? $order['currency'] ?? 'INR'));
        $amountPaise = (int) ($payment['amount'] ?? $order['amount'] ?? 0);

        $status = match (true) {
            in_array($statusRaw, ['captured', 'paid'], true) => 'captured',
            in_array($eventType, ['payment.captured', 'order.paid'], true) => 'captured',
            in_array($statusRaw, ['failed', 'cancelled'], true) => 'failed',
            $eventType === 'payment.failed' => 'failed',
            default => $statusRaw !== '' ? $statusRaw : 'unknown',
        };

        $eventId = $deliveryEventId !== null && $deliveryEventId !== ''
            ? $deliveryEventId
            : (string) ($decoded['id'] ?? '');

        if ($eventId === '') {
            // Fallback composite — still unique per payment+event type when header missing.
            $eventId = $eventType.':'.($paymentId !== '' ? $paymentId : $orderId);
        }

        if ($orderId === '') {
            throw new SubscriptionDomainException('Razorpay webhook missing order id.');
        }

        return [
            'event_id' => $eventId,
            'event_type' => $eventType !== '' ? $eventType : 'payment',
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'status' => $status,
            'amount' => RazorpayMoney::fromPaise($amountPaise),
            'currency' => $currency,
        ];
    }
}
