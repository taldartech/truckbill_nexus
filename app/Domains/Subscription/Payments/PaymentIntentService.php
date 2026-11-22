<?php

namespace App\Domains\Subscription\Payments;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\Gateway\PaymentGateway;
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
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    public function create(
        Company $company,
        string $purpose,
        string $amount,
        string $idempotencyKey,
        string $currency = 'INR',
        ?CompanySubscription $subscription = null,
        ?SubscriptionChangeProposal $proposal = null,
    ): PaymentIntent {
        if (! (bool) config('subscription.payments.live_enabled', false) && $this->gateway->name() !== 'fake') {
            throw new SubscriptionDomainException('Live payment gateways are not authorized (PO-P3-PAY / G-PAY).');
        }

        if (DecimalMath::compare($amount, '0', 2) <= 0) {
            throw new SubscriptionDomainException('Payment amount must be positive.');
        }

        return $this->tx->run(function () use ($company, $purpose, $amount, $idempotencyKey, $currency, $subscription, $proposal): PaymentIntent {
            $existing = PaymentIntent::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
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
                $intent = PaymentIntent::query()->create([
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
                    'meta' => ['simulated' => $this->gateway->name() === 'fake'],
                ]);

                $order = $this->gateway->createOrder($intent);
                $intent->gateway_order_id = $order['order_id'];
                $intent->status = 'order_created';
                $meta = $intent->meta ?? [];
                $meta['order'] = $order;
                $intent->meta = $meta;
                $intent->save();

                $this->audit->write(
                    action: AuditAction::Created,
                    subjectType: 'payment_intent',
                    subjectId: $intent->id,
                    remark: 'Create payment intent',
                    before: null,
                    after: $intent->toArray(),
                );

                return $intent->fresh() ?? $intent;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::set($previous);
                }
            }
        });
    }
}
