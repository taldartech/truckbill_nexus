<?php

namespace App\Domains\Subscription\Payments;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\Gateway\PaymentGateway;
use App\Domains\Subscription\Payments\Gateway\PaymentGatewayAuthorization;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\PaymentIntent;
use App\Models\SubscriptionChangeProposal;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;

final class PaymentIntentService
{
    private readonly PaymentGatewayAuthorization $authorization;

    public function __construct(
        private readonly PaymentGateway $gateway,
        ?PaymentGatewayAuthorization $authorization = null,
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {
        $this->authorization = $authorization ?? app(PaymentGatewayAuthorization::class);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function create(
        Company $company,
        string $purpose,
        string $amount,
        string $idempotencyKey,
        string $currency = 'INR',
        ?CompanySubscription $subscription = null,
        ?SubscriptionChangeProposal $proposal = null,
        array $meta = [],
    ): PaymentIntent {
        $this->authorization->assertGatewayAllowed($this->gateway->name());

        if (DecimalMath::compare($amount, '0', 2) <= 0) {
            throw new SubscriptionDomainException('Payment amount must be positive.');
        }

        // Always scope by gateway so a prior fake order cannot be opened in Razorpay Checkout.
        $idempotencyKey = $this->gatewayScopedIdempotencyKey($idempotencyKey);
        $existing = $this->findReusableIntent($idempotencyKey);

        if ($existing !== null && $existing->gateway_order_id !== null) {
            return $existing;
        }

        // Persist intent without holding a DB lock across outbound HTTP.
        $intent = $existing ?? $this->tx->run(function () use ($company, $purpose, $amount, &$idempotencyKey, $currency, $subscription, $proposal, $meta): PaymentIntent {
            // Re-resolve under lock in case a concurrent request created the row.
            $race = $this->findReusableIntent($idempotencyKey, lock: true);
            if ($race !== null) {
                return $race;
            }

            $previous = TenantContextHolder::get();
            TenantContextHolder::set(new TenantContext(
                companyId: $company->id,
                branchId: null,
                ownerId: (string) $company->owner_user_id,
                isInternal: false,
                isOwnerScope: true,
            ));

            try {
                $created = PaymentIntent::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'purpose' => $purpose,
                    'currency' => $currency,
                    'amount' => $amount,
                    'status' => 'created',
                    'verification_status' => 'unverified',
                    'idempotency_key' => $idempotencyKey,
                    'company_subscription_id' => $subscription?->id,
                    'change_proposal_id' => $proposal?->id,
                    'gateway' => $this->gateway->name(),
                    'meta' => array_merge([
                        'simulated' => $this->gateway->name() === 'fake',
                        'mode' => $this->gateway->name() === 'razorpay_test' ? 'test' : 'fake',
                    ], $meta),
                ]);

                $this->audit->write(
                    action: AuditAction::Created,
                    subjectType: 'payment_intent',
                    subjectId: $created->id,
                    remark: 'Create payment intent',
                    before: null,
                    after: $created->toArray(),
                );

                return $created;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::set($previous);
                }
            }
        });

        if ($intent->gateway_order_id !== null) {
            return $intent;
        }

        $order = $this->gateway->createOrder($intent);

        return $this->tx->run(function () use ($intent, $order): PaymentIntent {
            /** @var PaymentIntent $locked */
            $locked = PaymentIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if ($locked->gateway_order_id !== null) {
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
                $locked->gateway_order_id = $order['order_id'];
                $locked->status = 'order_created';
                $intentMeta = $locked->meta ?? [];
                $intentMeta['order'] = $order;
                $locked->meta = $intentMeta;
                $locked->save();

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

    /**
     * Prevent cross-gateway reuse (e.g. fake order_id opened in Razorpay Checkout).
     */
    private function gatewayScopedIdempotencyKey(string $idempotencyKey): string
    {
        $suffix = '|gw:'.$this->gateway->name();
        if (str_contains($idempotencyKey, '|gw:')) {
            return $idempotencyKey;
        }

        return $idempotencyKey.$suffix;
    }

    /**
     * Locate an intent that may still be used for checkout. Failed/cancelled intents
     * are superseded with a deterministic retry key so refresh cannot revive them.
     */
    private function findReusableIntent(string &$idempotencyKey, bool $lock = false): ?PaymentIntent
    {
        $query = PaymentIntent::query()->where('idempotency_key', $idempotencyKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var PaymentIntent|null $existing */
        $existing = $query->first();
        if ($existing === null) {
            return null;
        }

        if ($existing->gateway !== $this->gateway->name()) {
            // Should not happen when keys are gateway-scoped; never reuse mismatched rows.
            $base = (string) preg_replace('/\|gw:[^|]+/', '', $idempotencyKey);
            $idempotencyKey = $this->gatewayScopedIdempotencyKey($base).'|supersede:'.$existing->id;

            return $this->findReusableIntent($idempotencyKey, $lock);
        }

        if (in_array($existing->status, ['failed', 'cancelled'], true)) {
            $idempotencyKey = $idempotencyKey.'|after:'.$existing->id;

            return $this->findReusableIntent($idempotencyKey, $lock);
        }

        return $existing;
    }
}
