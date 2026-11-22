<?php

namespace App\Domains\Subscription\Payments\Gateway;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\Support\RazorpayMoney;
use App\Models\PaymentIntent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Razorpay Test Mode adapter via Laravel HTTP client (PO-P3-PAY-TEST).
 * No SDK. Production live mode is never available here.
 */
final class RazorpayTestPaymentGateway implements PaymentGateway
{
    private readonly PaymentGatewayAuthorization $auth;

    public function __construct(?PaymentGatewayAuthorization $auth = null)
    {
        $this->auth = $auth ?? app(PaymentGatewayAuthorization::class);
    }

    public function name(): string
    {
        return 'razorpay_test';
    }

    public function publicKeyId(): string
    {
        $this->auth->assertGatewayAllowed($this->name());

        return (string) config('subscription.payments.razorpay.key_id');
    }

    public function webhookSecret(): string
    {
        $this->auth->assertGatewayAllowed($this->name());

        return (string) config('subscription.payments.razorpay.webhook_secret');
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
            $response = $this->client()
                ->post('/orders', $payload)
                ->throw()
                ->json();
        } catch (ConnectionException $e) {
            Log::warning('razorpay_test.order_timeout', ['payment_intent_id' => $intent->id]);
            throw new SubscriptionDomainException('Razorpay Test Mode order request timed out.', 0, $e);
        } catch (RequestException $e) {
            Log::warning('razorpay_test.order_failed', [
                'payment_intent_id' => $intent->id,
                'status' => $e->response->status(),
            ]);
            throw new SubscriptionDomainException('Razorpay Test Mode order creation failed.', 0, $e);
        } catch (Throwable $e) {
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
            return $this->client()
                ->get('/payments/'.$paymentId)
                ->throw()
                ->json();
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

        $expected = hash_hmac(
            'sha256',
            $orderId.'|'.$paymentId,
            (string) config('subscription.payments.razorpay.key_secret'),
        );

        return hash_equals($expected, $signature);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature, string $secret): bool
    {
        if ($rawBody === '' || $signature === '' || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @return PendingRequest
     */
    private function client()
    {
        $base = rtrim((string) config('subscription.payments.razorpay.base_url', 'https://api.razorpay.com/v1'), '/');

        return Http::baseUrl($base)
            ->withBasicAuth(
                (string) config('subscription.payments.razorpay.key_id'),
                (string) config('subscription.payments.razorpay.key_secret'),
            )
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout(10);
    }
}
