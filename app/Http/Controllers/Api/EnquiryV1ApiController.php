<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Webhooks\EnquiryWebhookController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stateless website enquiry intake (V1) — same contract as /webhooks/enquiries.
 */
final class EnquiryV1ApiController
{
    public function __construct(
        private readonly EnquiryWebhookController $webhook = new EnquiryWebhookController,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->webhook->__invoke($request);
    }
}
