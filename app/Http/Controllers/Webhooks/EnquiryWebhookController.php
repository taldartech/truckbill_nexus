<?php

namespace App\Http\Controllers\Webhooks;

use App\Domains\Enquiry\Exceptions\EnquiryConflictException;
use App\Domains\Enquiry\Exceptions\EnquiryWebhookAuthenticationException;
use App\Domains\Enquiry\Exceptions\EnquiryWebhookReplayException;
use App\Domains\Enquiry\Webhook\EnquiryWebhookIntakeService;
use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Support\Idempotency\Exceptions\IdempotencyConflictException;
use App\Support\Idempotency\Exceptions\IdempotencyInProgressException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Public website enquiry webhook V1 (PO-P4-WEBHOOK / Batch B).
 * Authenticated by company-scoped bearer token — not session CSRF / not Razorpay HMAC.
 */
final class EnquiryWebhookController extends Controller
{
    public function __construct(
        private readonly EnquiryWebhookIntakeService $intake = new EnquiryWebhookIntakeService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rateKey = 'enquiry-webhook:'.sha1(
            (string) $request->ip().'|'.substr((string) $request->header('Authorization', ''), 0, 24)
        );
        $maxAttempts = (int) config('enquiry_webhook.rate_limit_per_minute', 60);

        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            return response()->json([
                'message' => 'Too many requests.',
                'retry_after' => RateLimiter::availableIn($rateKey),
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($rateKey));
        }

        RateLimiter::hit($rateKey, 60);

        try {
            $result = $this->intake->handle($request);
        } catch (EnquiryWebhookAuthenticationException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        } catch (EnquiryWebhookReplayException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (EnquiryConflictException|IdempotencyConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (IdempotencyInProgressException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Unable to process enquiry webhook.'], 500);
        }

        $status = $result['created'] ? 201 : 200;

        return response()->json([
            'data' => $this->enquiryPayload($result['enquiry']),
            'meta' => [
                'created' => $result['created'],
                'idempotent_replay' => $result['replayed'],
            ],
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function enquiryPayload(Enquiry $enquiry): array
    {
        return [
            'id' => $enquiry->id,
            'enquiry_number' => $enquiry->enquiry_number,
            'source' => $enquiry->source->value,
            'status' => $enquiry->status->value,
            'branch_id' => $enquiry->branch_id,
            'external_reference' => $enquiry->external_reference,
            'pickup_location' => $enquiry->pickup_location,
            'delivery_location' => $enquiry->delivery_location,
            'vehicle_quantity' => $enquiry->vehicle_quantity,
            'goods' => $enquiry->goodsRows->map(fn ($row): array => [
                'position' => $row->position,
                'goods_description' => $row->goods_description,
                'package_count' => $row->package_count,
                'actual_weight' => $row->actual_weight,
                'charged_weight' => $row->charged_weight,
            ])->values()->all(),
        ];
    }
}
