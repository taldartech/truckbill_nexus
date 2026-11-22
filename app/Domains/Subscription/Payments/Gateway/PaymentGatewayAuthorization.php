<?php

namespace App\Domains\Subscription\Payments\Gateway;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use Illuminate\Contracts\Foundation\Application;

/**
 * PO-P3-PAY-TEST: Razorpay Test Mode via official SDK.
 * PO-P3-PAY-PROD remains Pending — production Razorpay is always blocked.
 *
 * Selection uses APP_ENV + Key ID prefix (rzp_test_ / rzp_live_), not extra env flags.
 */
final class PaymentGatewayAuthorization
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function keyId(): string
    {
        return trim((string) config('subscription.payments.razorpay.key_id', ''));
    }

    public function keySecret(): string
    {
        return trim((string) config('subscription.payments.razorpay.key_secret', ''));
    }

    public function webhookSecret(): string
    {
        return trim((string) config('subscription.payments.razorpay.webhook_secret', ''));
    }

    public function hasCompleteCredentials(): bool
    {
        return $this->keyId() !== ''
            && $this->keySecret() !== ''
            && $this->webhookSecret() !== '';
    }

    public function isLiveKeyId(): bool
    {
        return str_starts_with($this->keyId(), 'rzp_live_');
    }

    public function isTestKeyId(): bool
    {
        return str_starts_with($this->keyId(), 'rzp_test_');
    }

    /**
     * True when non-production APP_ENV has complete rzp_test_* credentials.
     */
    public function isRazorpayTestModeAvailable(): bool
    {
        if ($this->app->isProduction()) {
            return false;
        }

        if (! $this->hasCompleteCredentials()) {
            return false;
        }

        if ($this->isLiveKeyId()) {
            return false;
        }

        return $this->isTestKeyId();
    }

    public function isWebhookEndpointEnabled(): bool
    {
        return $this->isRazorpayTestModeAvailable();
    }

    public function selectedGateway(): string
    {
        if ($this->app->environment('testing')) {
            return 'fake';
        }

        return $this->isRazorpayTestModeAvailable() ? 'razorpay_test' : 'fake';
    }

    public function assertGatewayAllowed(string $gatewayName): void
    {
        if ($gatewayName === 'fake') {
            return;
        }

        if ($gatewayName === 'razorpay_live') {
            throw new SubscriptionDomainException(
                'Production Razorpay (PO-P3-PAY-PROD) is not authorized.'
            );
        }

        if ($gatewayName !== 'razorpay_test') {
            throw new SubscriptionDomainException('Unsupported payment gateway: '.$gatewayName);
        }

        if ($this->app->isProduction()) {
            throw new SubscriptionDomainException(
                'Razorpay payments are disabled in the production application environment until PO-P3-PAY-PROD.'
            );
        }

        if (! $this->hasCompleteCredentials()) {
            throw new SubscriptionDomainException(
                'Razorpay Test Mode credentials are incomplete (RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET, RAZORPAY_WEBHOOK_SECRET).'
            );
        }

        if ($this->isLiveKeyId()) {
            throw new SubscriptionDomainException('Live Razorpay key id is not permitted (PO-P3-PAY-PROD Pending).');
        }

        if (! $this->isTestKeyId()) {
            throw new SubscriptionDomainException('Razorpay Key ID must start with rzp_test_ for Test Mode.');
        }
    }

    public function resolveBindingClass(): string
    {
        return match ($this->selectedGateway()) {
            'fake' => FakePaymentGateway::class,
            'razorpay_test' => RazorpayTestPaymentGateway::class,
            default => FakePaymentGateway::class,
        };
    }
}
