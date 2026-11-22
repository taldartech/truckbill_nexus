<?php

namespace App\Http\Controllers\Webhooks;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\Gateway\PaymentGatewayAuthorization;
use App\Domains\Subscription\Payments\PaymentEventProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Non-production Razorpay Test Mode webhook (PO-P3-PAY-TEST).
 * Disabled in production and when Test Mode / webhook flag is off.
 */
final class RazorpayWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayAuthorization $authorization,
        private readonly PaymentEventProcessor $processor,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->authorization->isWebhookEndpointEnabled()) {
            abort(404);
        }

        $raw = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        $deliveryId = (string) $request->header('X-Razorpay-Event-Id', '');

        try {
            $event = $this->processor->processRawWebhook(
                rawBody: $raw,
                signature: $signature,
                deliveryEventId: $deliveryId !== '' ? $deliveryId : null,
            );
        } catch (SubscriptionDomainException) {
            return response('rejected', 400);
        } catch (Throwable) {
            return response('error', 500);
        }

        $status = $event->processing_status;

        return response($status === 'rejected' ? 'rejected' : 'ok', $status === 'rejected' ? 400 : 200);
    }
}
