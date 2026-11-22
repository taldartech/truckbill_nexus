<?php

namespace App\Domains\Subscription\Payments;

use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Payments\Gateway\FakePaymentGateway;
use App\Domains\Subscription\Payments\Gateway\PaymentGateway;
use App\Domains\Subscription\Payments\Gateway\RazorpayTestPaymentGateway;
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
use JsonException;

/**
 * Processes verified webhook raw bodies. Payload is always parsed from the signed bytes.
 */
final class PaymentEventProcessor
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionLifecycleService $lifecycle = new SubscriptionLifecycleService,
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly OutboxWriter $outbox = new OutboxWriter,
        private readonly RazorpayWebhookNormalizer $normalizer = new RazorpayWebhookNormalizer,
        private readonly CompanySubscriptionContractService $contracts = new CompanySubscriptionContractService,
    ) {}

    /**
     * Preferred entry: verify signature then parse the same raw body.
     */
    public function processRawWebhook(
        string $rawBody,
        string $signature,
        ?string $deliveryEventId = null,
        ?string $secret = null,
    ): PaymentGatewayEvent {
        $secret ??= $this->resolveWebhookSecret();

        if (! $this->gateway->verifyWebhookSignature($rawBody, $signature, $secret)) {
            return $this->recordRejected([
                'event_id' => $deliveryEventId ?? 'unknown',
                'event_type' => 'webhook',
            ], 'invalid', 'Invalid webhook signature.');
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SubscriptionDomainException('Webhook body is not valid JSON.', 0, $e);
        }

        $payload = $this->normalizeDecoded($decoded, $deliveryEventId);

        return $this->processNormalizedPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload  Ignored for business data — kept for API compatibility with Batch E tests.
     *
     * @deprecated Prefer processRawWebhook(); $payload is not trusted independently of $rawBody.
     */
    public function processVerifiedEvent(
        string $rawBody,
        string $signature,
        array $payload,
        string $secret,
    ): PaymentGatewayEvent {
        return $this->processRawWebhook($rawBody, $signature, null, $secret);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function normalizeDecoded(array $decoded, ?string $deliveryEventId): array
    {
        if (isset($decoded['event']) && isset($decoded['payload'])) {
            return $this->normalizer->normalize($decoded, $deliveryEventId);
        }

        // Fake / domain-shaped events used in CI.
        if ($deliveryEventId !== null && $deliveryEventId !== '') {
            $decoded['event_id'] = $deliveryEventId;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function processNormalizedPayload(array $payload): PaymentGatewayEvent
    {
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

            // Do not downgrade an already-captured payment with a delayed failure event.
            if ($intent->status === 'paid' && $status !== 'captured' && $status !== 'paid') {
                $event->processing_status = 'ignored';
                $event->rejection_reason = 'Delayed non-success event after verified capture.';
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
                $intent->gateway_payment_id = $paymentId !== '' ? $paymentId : $intent->gateway_payment_id;
                $intent->status = 'paid';
                $intent->verification_status = 'verified';
                $intent->save();

                $this->fulfillPaidIntent($intent, $eventId);

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

    public function fulfillPaidIntent(PaymentIntent $intent, string $reference): void
    {
        if ($intent->company_subscription_id !== null) {
            $subscription = CompanySubscription::query()->whereKey($intent->company_subscription_id)->first();
            if ($subscription !== null) {
                if (in_array($subscription->status, ['draft', 'pending_payment'], true)) {
                    $paidThrough = CarbonImmutable::parse(
                        $subscription->common_renewal_date?->toDateString() ?? now()->addYear()->toDateString()
                    );
                    $this->lifecycle->activateAfterVerifiedPayment(
                        $subscription,
                        $paidThrough,
                        remark: 'Activate from verified payment '.$reference,
                    );
                } elseif ($subscription->status === 'active' && $intent->purpose === 'renewal') {
                    $current = $subscription->paid_through_date
                        ? CarbonImmutable::parse($subscription->paid_through_date->toDateString())
                        : CarbonImmutable::parse($subscription->common_renewal_date?->toDateString() ?? now()->toDateString());
                    $months = max(1, (int) ($subscription->tenure === 'monthly' ? 1 : ($subscription->tenure === 'quarterly' ? 3 : ($subscription->tenure === 'half_yearly' ? 6 : 12))));
                    if (in_array($subscription->tenure, ['three_year_full_advance', 'three_year_annual_instalments'], true)) {
                        $months = 12;
                    }
                    $this->lifecycle->extendAfterVerifiedRenewal(
                        $subscription,
                        $current->addMonthsNoOverflow($months),
                        remark: 'Renew from verified payment '.$reference,
                    );
                }
            }
        }

        if (in_array($intent->purpose, ['credit_topup_broker', 'credit_topup_partload'], true)) {
            $ledgerType = $intent->purpose === 'credit_topup_broker' ? 'broker' : 'partload';
            $meta = $intent->meta ?? [];
            $units = (string) ($meta['credit_units'] ?? $intent->amount);
            $company = $intent->company()->firstOrFail();
            $ledger = $this->contracts->ensureCreditLedger($company, $ledgerType);
            $this->contracts->appendCreditEntry(
                $ledger,
                $units,
                'grant',
                'Verified '.$intent->purpose,
                'pay:'.$intent->id,
            );
        }
    }

    private function resolveWebhookSecret(): string
    {
        if ($this->gateway instanceof RazorpayTestPaymentGateway) {
            return $this->gateway->webhookSecret();
        }
        if ($this->gateway instanceof FakePaymentGateway) {
            return $this->gateway->secret();
        }

        throw new SubscriptionDomainException('Webhook secret unavailable for gateway '.$this->gateway->name());
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
