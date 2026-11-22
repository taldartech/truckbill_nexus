<?php

namespace App\Domains\Subscription\Payments\Gateway;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\Support\RazorpayMoney;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Errors\Error as RazorpayError;
use Razorpay\Api\Errors\SignatureVerificationError;
use Throwable;

/**
 * Razorpay Test Mode adapter via official PHP SDK (PO-P3-PAY-TEST / PO-P1-DEPS).
 * Production live mode is never available here.
 */
final class RazorpayTestPaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly PaymentGatewayAuthorization $auth,
        private readonly RazorpayClient $client,
    ) {}

    public function name(): string
    {
        return 'razorpay_test';
    }

    public function publicKeyId(): string
    {
        $this->auth->assertGatewayAllowed($this->name());

        return $this->auth->keyId();
    }

    public function webhookSecret(): string
    {
        $this->auth->assertGatewayAllowed($this->name());

        return $this->auth->webhookSecret();
    }

    public function createOrder(PaymentIntent $intent, array $attributes = []): array
    {
        $this->auth->assertGatewayAllowed($this->name());

        $paise = RazorpayMoney::toPaise((string) $intent->amount);
        $currency = strtoupper($intent->currency);
        if ($currency !== 'INR') {
            throw new SubscriptionDomainException('Razorpay Test Mode currently supports INR only.');
        }

        $payload = [
            'amount' => $paise,
            'currency' => $currency,
            'receipt' => substr('pi_'.str_replace('-', '', $intent->id), 0, 40),
            'notes' => [
                'payment_intent_id' => $intent->id,
                'company_id' => $intent->company_id,
                'purpose' => $intent->purpose,
                'mode' => 'test',
            ],
        ];

        try {
            $response = $this->client->createOrder($payload);
        } catch (RazorpayError $e) {
            Log::warning('razorpay_test.order_failed', [
                'payment_intent_id' => $intent->id,
                'code' => $e->getCode(),
            ]);
            throw new SubscriptionDomainException('Razorpay Test Mode order creation failed.', 0, $e);
        } catch (Throwable $e) {
            Log::warning('razorpay_test.order_exception', [
                'payment_intent_id' => $intent->id,
            ]);
            throw new SubscriptionDomainException('Razorpay Test Mode order creation failed.', 0, $e);
        }

        $orderId = (string) ($response['id'] ?? '');
        if ($orderId === '') {
            throw new SubscriptionDomainException('Razorpay Test Mode returned an empty order id.');
        }

        $responseAmount = (int) ($response['amount'] ?? 0);
        if ($responseAmount !== $paise) {
            throw new SubscriptionDomainException('Razorpay order amount mismatch.');
        }

        return [
            'order_id' => $orderId,
            'amount' => (string) $intent->amount,
            'currency' => $currency,
            'meta' => [
                'simulated' => false,
                'mode' => 'test',
                'sdk' => 'razorpay/razorpay',
                'amount_paise' => $paise,
                'payment_intent_id' => $intent->id,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPayment(string $paymentId): array
    {
        $this->auth->assertGatewayAllowed($this->name());

        try {
            return $this->client->fetchPayment($paymentId);
        } catch (Throwable $e) {
            throw new SubscriptionDomainException('Unable to fetch Razorpay Test Mode payment.', 0, $e);
        }
    }

    public function verifyCheckoutSignature(array $payload, string $signature): bool
    {
        $this->auth->assertGatewayAllowed($this->name());

        $orderId = (string) ($payload['razorpay_order_id'] ?? $payload['order_id'] ?? '');
        $paymentId = (string) ($payload['razorpay_payment_id'] ?? $payload['payment_id'] ?? '');
        if ($orderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }

        try {
            $this->client->verifyPaymentSignature([
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature,
            ]);

            return true;
        } catch (SignatureVerificationError) {
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    public function verifyWebhookSignature(string $rawBody, string $signature, string $secret): bool
    {
        if ($rawBody === '' || $signature === '' || $secret === '') {
            return false;
        }

        try {
            $this->client->verifyWebhookSignature($rawBody, $signature, $secret);

            return true;
        } catch (SignatureVerificationError) {
            return false;
        } catch (Throwable) {
            return false;
        }
    }
}
