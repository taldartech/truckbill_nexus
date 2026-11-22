<?php

namespace App\Domains\Subscription\Payments\Gateway;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use Illuminate\Contracts\Foundation\Application;

/**
 * PO-P3-PAY-TEST approved Test Mode; PO-P3-PAY-PROD remains Pending.
 * Fails closed on inconsistent environment / credentials.
 */
final class PaymentGatewayAuthorization
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function selectedGateway(): string
    {
        return (string) config('subscription.payments.gateway', 'fake');
    }

    public function razorpayMode(): string
    {
        return strtolower((string) config('subscription.payments.razorpay.mode', 'test'));
    }

    public function isTestModeConfigured(): bool
    {
        return (bool) config('subscription.payments.razorpay.test_enabled', false);
    }

    public function isWebhookEndpointEnabled(): bool
    {
        if ($this->app->isProduction()) {
            return false;
        }

        return (bool) config('subscription.payments.webhook_endpoint_enabled', false)
            && $this->selectedGateway() === 'razorpay_test'
            && $this->isTestModeConfigured();
    }

    public function assertGatewayAllowed(string $gatewayName): void
    {
        if ($gatewayName === 'fake') {
            return;
        }

        if ($gatewayName === 'razorpay_live' || $this->razorpayMode() === 'live') {
            throw new SubscriptionDomainException(
                'Production Razorpay (PO-P3-PAY-PROD) is not authorized.'
            );
        }

        if ($gatewayName !== 'razorpay_test') {
            throw new SubscriptionDomainException('Unsupported payment gateway: '.$gatewayName);
        }

        if ($this->app->isProduction()) {
            throw new SubscriptionDomainException(
                'Razorpay Test Mode must not run in the production application environment.'
            );
        }

        if (! $this->isTestModeConfigured()) {
            throw new SubscriptionDomainException(
                'Razorpay Test Mode is disabled. Set RAZORPAY_TEST_ENABLED=true after G-PAY credentials are configured.'
            );
        }

        if ($this->razorpayMode() !== 'test') {
            throw new SubscriptionDomainException('RAZORPAY_MODE must be "test" for PO-P3-PAY-TEST.');
        }

        $keyId = trim((string) config('subscription.payments.razorpay.key_id', ''));
        $keySecret = trim((string) config('subscription.payments.razorpay.key_secret', ''));
        $webhookSecret = trim((string) config('subscription.payments.razorpay.webhook_secret', ''));

        if ($keyId === '' || $keySecret === '' || $webhookSecret === '') {
            throw new SubscriptionDomainException(
                'Razorpay Test Mode credentials are incomplete (key id, key secret, webhook secret required).'
            );
        }

        // Live key ids commonly start with rzp_live_; reject them in Test Mode.
        if (str_starts_with($keyId, 'rzp_live_')) {
            throw new SubscriptionDomainException('Live Razorpay key id is not permitted in Test Mode.');
        }
    }

    public function resolveBindingClass(): string
    {
        $gateway = $this->selectedGateway();

        return match ($gateway) {
            'fake' => FakePaymentGateway::class,
            'razorpay_test' => RazorpayTestPaymentGateway::class,
            'razorpay_live' => throw new SubscriptionDomainException(
                'Production Razorpay binding is unavailable (PO-P3-PAY-PROD Pending).'
            ),
            default => throw new SubscriptionDomainException('Unknown SUBSCRIPTION_PAYMENT_GATEWAY: '.$gateway),
        };
    }
}
