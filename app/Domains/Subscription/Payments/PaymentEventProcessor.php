<?php

namespace App\Domains\Subscription\Payments;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Payments\Gateway\PaymentGateway;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\CompanySubscription;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentIntent;
use App\Models\PaymentReconciliation;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Processes verified synthetic gateway events. Production webhook HTTP endpoint stays disabled.
 */
final class PaymentEventProcessor
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionLifecycleService $lifecycle = new SubscriptionLifecycleService,
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly OutboxWriter $outbox = new OutboxWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processVerifiedEvent(
        string $rawBody,
        string $signature,
        array $payload,
        string $secret,
    ): PaymentGatewayEvent {
        if (! $this->gateway->verifyWebhookSignature($rawBody, $signature, $secret)) {
            return $this->recordRejected($payload, 'invalid', 'Invalid webhook signature.');
        }

        $eventId = (string) ($payload['event_id'] ?? '');
        if ($eventId === '') {
            throw new SubscriptionDomainException('gateway event_id is required.');
        }

        return $this->tx->run(function () use ($payload, $eventId): PaymentGatewayEvent {
            $existing = PaymentGatewayEvent::query()
                ->where('gateway_event_id', $eventId)
                ->where('signature_status', 'verified')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->processing_status = 'duplicate';
                $existing->save();

                return $existing;
            }

            $orderId = (string) ($payload['order_id'] ?? '');
            $paymentId = (string) ($payload['payment_id'] ?? '');
            $status = (string) ($payload['status'] ?? '');
            $amount = (string) ($payload['amount'] ?? '');
            $currency = (string) ($payload['currency'] ?? 'INR');

            /** @var PaymentIntent|null $intent */
            $intent = PaymentIntent::query()
                ->where('gateway_order_id', $orderId)
                ->lockForUpdate()
                ->first();

            $event = PaymentGatewayEvent::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $intent?->company_id,
                'payment_intent_id' => $intent?->id,
                'gateway' => $this->gateway->name(),
                'gateway_event_id' => $eventId,
                'event_type' => (string) ($payload['event_type'] ?? 'payment'),
                'processing_status' => 'received',
                'signature_status' => 'verified',
                'payload' => $payload,
                'received_at' => now(),
            ]);

            if ($intent === null) {
                return $this->quarantine($event, 'Unknown gateway order.');
            }

            if (DecimalMath::compare($amount, (string) $intent->amount, 2) !== 0 || strtoupper($currency) !== strtoupper($intent->currency)) {
                $this->reconcile($intent, $event, 'mismatch', [
                    'expected_amount' => (string) $intent->amount,
                    'actual_amount' => $amount,
                    'expected_currency' => $intent->currency,
                    'actual_currency' => $currency,
                ]);

                return $this->quarantine($event, 'Amount/currency mismatch.');
            }

            if ($intent->status === 'paid' && $intent->gateway_payment_id === $paymentId) {
                $event->processing_status = 'duplicate';
                $event->processed_at = now();
                $event->save();

                return $event;
            }

            if ($status !== 'captured' && $status !== 'paid') {
                $intent->status = 'failed';
                $intent->verification_status = 'verified';
                $intent->save();
                $event->processing_status = 'processed';
                $event->processed_at = now();
                $event->save();

                return $event;
            }

            $previous = TenantContextHolder::get();
            TenantContextHolder::set(new TenantContext(
                companyId: $intent->company_id,
                branchId: null,
                ownerId: null,
                isInternal: true,
                isOwnerScope: false,
            ));

            try {
                $intent->gateway_payment_id = $paymentId;
                $intent->status = 'paid';
                $intent->verification_status = 'verified';
                $intent->save();

                if ($intent->company_subscription_id !== null) {
                    $subscription = CompanySubscription::query()->whereKey($intent->company_subscription_id)->first();
                    if ($subscription !== null && in_array($subscription->status, ['draft', 'pending_payment'], true)) {
                        $paidThrough = CarbonImmutable::parse(
                            $subscription->common_renewal_date?->toDateString() ?? now()->addYear()->toDateString()
                        );
                        $this->lifecycle->activateAfterVerifiedPayment(
                            $subscription,
                            $paidThrough,
                            remark: 'Activate from verified payment event '.$eventId,
                        );
                    }
                }

                $this->outbox->enqueue('subscription.payment.verified', [
                    'payment_intent_id' => $intent->id,
                    'gateway_event_id' => $eventId,
                    'company_id' => $intent->company_id,
                ]);

                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: 'payment_intent',
                    subjectId: $intent->id,
                    remark: 'Verified payment event processed',
                    before: null,
                    after: $intent->fresh()?->toArray(),
                );

                $this->reconcile($intent, $event, 'matched', ['payment_id' => $paymentId]);

                $event->processing_status = 'processed';
                $event->processed_at = now();
                $event->save();

                return $event->fresh() ?? $event;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::set($previous);
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordRejected(array $payload, string $signatureStatus, string $reason): PaymentGatewayEvent
    {
        $claimedId = (string) ($payload['event_id'] ?? '');

        return PaymentGatewayEvent::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => null,
            'payment_intent_id' => null,
            'gateway' => $this->gateway->name(),
            // Do not consume the real gateway_event_id namespace for invalid signatures.
            'gateway_event_id' => 'invalid:'.($claimedId !== '' ? $claimedId : 'unknown').':'.Str::uuid(),
            'event_type' => (string) ($payload['event_type'] ?? 'payment'),
            'processing_status' => 'rejected',
            'signature_status' => $signatureStatus,
            'payload' => $payload,
            'rejection_reason' => $reason,
            'received_at' => now(),
            'processed_at' => now(),
        ]);
    }

    private function quarantine(PaymentGatewayEvent $event, string $reason): PaymentGatewayEvent
    {
        $event->processing_status = 'quarantined';
        $event->rejection_reason = $reason;
        $event->processed_at = now();
        $event->save();

        PaymentReconciliation::query()->create([
            'id' => (string) Str::uuid(),
            'payment_intent_id' => $event->payment_intent_id,
            'gateway_event_id' => $event->id,
            'outcome' => 'unmatched',
            'details' => ['reason' => $reason],
            'created_at' => now(),
        ]);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function reconcile(PaymentIntent $intent, PaymentGatewayEvent $event, string $outcome, array $details): void
    {
        PaymentReconciliation::query()->create([
            'id' => (string) Str::uuid(),
            'payment_intent_id' => $intent->id,
            'gateway_event_id' => $event->id,
            'outcome' => $outcome,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}
