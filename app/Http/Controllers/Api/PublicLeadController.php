<?php

namespace App\Http\Controllers\Api;

use App\Domains\Crm\LeadService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePublicLeadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Server-to-server public website → CRM Lead capture.
 * Authenticated by X-Api-Key (never exposed to browser JS).
 */
class PublicLeadController extends Controller
{
    public function __construct(
        private readonly LeadService $leads = new LeadService,
    ) {}

    public function store(StorePublicLeadRequest $request): JsonResponse
    {
        $rateKey = 'public-leads:'.sha1(
            (string) $request->ip().'|'.substr((string) $request->header('X-Api-Key', ''), 0, 12)
        );
        $maxAttempts = max(1, (int) config('public_leads.rate_limit_per_minute', 30));

        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again shortly.',
                'retry_after' => RateLimiter::availableIn($rateKey),
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($rateKey));
        }

        RateLimiter::hit($rateKey, 60);

        $data = $request->validated();
        if (empty($data['ip_address'])) {
            $data['ip_address'] = $request->ip();
        }

        $headerKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($headerKey !== '' && empty($data['idempotency_key'])) {
            $data['idempotency_key'] = mb_substr($headerKey, 0, 128);
        }

        try {
            $result = $this->leads->captureFromWebsite($data);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to accept this enquiry right now.',
            ], 500);
        }

        $status = $result['created'] ? 201 : 200;

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your interest in TruckBill. Our team has received your enquiry and will contact you shortly.',
            'receipt' => $result['receipt'],
            'meta' => [
                'created' => $result['created'],
                'replayed' => $result['replayed'],
                'appended' => $result['appended'],
            ],
        ], $status);
    }
}
