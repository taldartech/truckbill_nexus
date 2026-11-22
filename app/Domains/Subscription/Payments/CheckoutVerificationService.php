<?php

namespace App\Domains\Subscription\Payments;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\Gateway\PaymentGateway;
use App\Domains\Subscription\Payments\Gateway\RazorpayTestPaymentGateway;
use App\Models\Company;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;

/**
 * Verifies Owner checkout callbacks independently of webhook delivery.
 */
final class CheckoutVerificationService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentEventProcessor $processor,
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $callback
     */
    public function verifyOwnerCallback(User $owner, Company $company, PaymentIntent $intent, array $callback): PaymentIntent
    {
        if ($intent->company_id !== $company->id) {
            throw new SubscriptionDomainException('Payment intent does not belong to this company.');
        }
        if (! $company->isOwnedBy($owner) && ! $owner->isSuperAdmin()) {
            throw new SubscriptionDomainException('Not authorized to verify this payment.');
        }

        $orderId = (string) ($callback['razorpay_order_id'] ?? $callback['order_id'] ?? '');
        $paymentId = (string) ($callback['razorpay_payment_id'] ?? $callback['payment_id'] ?? '');
        $signature = (string) ($callback['razorpay_signature'] ?? $callback['signature'] ?? '');

        if ($orderId === '' || $paymentId === '' || $signature === '') {
            throw new SubscriptionDomainException('Checkout verification payload is incomplete.');
        }

        if ($intent->gateway_order_id !== $orderId) {
            throw new SubscriptionDomainException('Checkout order id does not match the stored payment intent.');
        }

        $ok = $this->gateway->verifyCheckoutSignature([
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
        ], $signature);

        if (! $ok) {
            throw new SubscriptionDomainException('Invalid checkout signature.');
        }

        if ($this->gateway instanceof RazorpayTestPaymentGateway) {
            $remote = $this->gateway->fetchPayment($paymentId);
            $remoteStatus = (string) ($remote['status'] ?? '');
            $remoteOrder = (string) ($remote['order_id'] ?? '');
            if ($remoteOrder !== $orderId) {
                throw new SubscriptionDomainException('Remote payment order mismatch.');
            }
            if (! in_array($remoteStatus, ['captured', 'authorized'], true)) {
                throw new SubscriptionDomainException('Remote payment is not captured.');
            }
        }

        return $this->tx->run(function () use ($intent, $paymentId, $orderId): PaymentIntent {
            /** @var PaymentIntent $locked */
            $locked = PaymentIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'paid' && $locked->gateway_payment_id === $paymentId) {
                return $locked;
            }

            $previous = TenantContextHolder::get();
            TenantContextHolder::set(new TenantContext(
                companyId: $locked->company_id,
                branchId: null,
                ownerId: null,
                isInternal: true,
                isOwnerScope: false,
            ));

            try {
                $locked->gateway_payment_id = $paymentId;
                $locked->status = 'paid';
                $locked->verification_status = 'verified';
                $locked->save();

                $this->processor->fulfillPaidIntent($locked, 'checkout:'.$paymentId);
                $this->recordCheckoutEvent($locked, $orderId, $paymentId);

                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: 'payment_intent',
                    subjectId: $locked->id,
                    remark: 'Checkout callback verified',
                    before: null,
                    after: $locked->fresh()?->toArray(),
                );

                return $locked->fresh() ?? $locked;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::set($previous);
                }
            }
        });
    }

    private function recordCheckoutEvent(PaymentIntent $intent, string $orderId, string $paymentId): void
    {
        $eventId = 'checkout:'.$paymentId;
        if (PaymentGatewayEvent::query()->where('gateway_event_id', $eventId)->exists()) {
            return;
        }

        PaymentGatewayEvent::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $intent->company_id,
            'payment_intent_id' => $intent->id,
            'gateway' => $this->gateway->name(),
            'gateway_event_id' => $eventId,
            'event_type' => 'checkout.verified',
            'processing_status' => 'processed',
            'signature_status' => 'verified',
            'payload' => [
                'event_id' => $eventId,
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'status' => 'captured',
                'amount' => (string) $intent->amount,
                'currency' => $intent->currency,
            ],
            'received_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
