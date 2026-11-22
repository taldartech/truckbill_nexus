<?php

namespace App\Domains\Enquiry\Webhook;

use App\Domains\Enquiry\EnquiryService;
use App\Domains\Enquiry\Support\EnquiryPayloadFingerprint;
use App\Models\Enquiry;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates webhook auth context, idempotency, replay, and enquiry creation.
 */
final class EnquiryWebhookIntakeService
{
    public const IDEMPOTENCY_SCOPE = 'webhook.enquiries.create';

    public function __construct(
        private readonly EnquiryWebhookAuthenticator $authenticator = new EnquiryWebhookAuthenticator,
        private readonly EnquiryWebhookReplayGuard $replay = new EnquiryWebhookReplayGuard,
        private readonly EnquiryService $enquiries = new EnquiryService,
        private readonly IdempotencyGuard $idempotency = new IdempotencyGuard,
    ) {}

    /**
     * @return array{enquiry: Enquiry, created: bool, replayed: bool}
     */
    public function handle(Request $request): array
    {
        $this->assertJsonOnly($request);
        $this->assertContentLength($request);

        $auth = $this->authenticator->authenticate($request);
        $company = $auth['company'];

        $this->replay->assertFreshTimestamp($request);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Idempotency-Key header is required.',
            ]);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        if ($payload === []) {
            throw ValidationException::withMessages(['body' => 'JSON body is required.']);
        }

        $this->rejectTenantInjectionKeys($payload);

        $requestHash = EnquiryPayloadFingerprint::hash($this->fingerprintInput($payload));
        $correlationId = (string) $request->attributes->get('correlation_id', '');

        $previous = TenantContextHolder::get();
        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: null,
            ownerId: (string) $company->owner_user_id,
            isInternal: false,
            isOwnerScope: true,
        ));

        $callbackExecuted = false;

        try {
            $result = $this->idempotency->run(
                scope: self::IDEMPOTENCY_SCOPE,
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
                ttlHours: (int) config('enquiry_webhook.idempotency_ttl_hours', 72),
                callback: function () use ($company, $payload, $correlationId, $request, &$callbackExecuted): array {
                    $callbackExecuted = true;
                    // Consume nonce only when performing real work — after idempotency miss.
                    $this->replay->consumeNonce($company, $request);

                    $created = $this->enquiries->createFromWebsite(
                        company: $company,
                        payload: $payload,
                        correlationId: $correlationId !== '' ? $correlationId : null,
                    );

                    return [
                        'enquiry_id' => $created['enquiry']->id,
                        'enquiry_number' => $created['enquiry']->enquiry_number,
                        'created' => $created['created'],
                    ];
                },
            );
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::inject($previous);
            }
        }

        if (is_array($result) && isset($result['enquiry_id'])) {
            /** @var Enquiry $enquiry */
            $enquiry = Enquiry::query()
                ->where('company_id', $company->id)
                ->whereKey($result['enquiry_id'])
                ->with('goodsRows')
                ->firstOrFail();

            return [
                'enquiry' => $enquiry,
                'created' => (bool) ($result['created'] ?? false) && $callbackExecuted,
                'replayed' => ! $callbackExecuted,
            ];
        }

        throw ValidationException::withMessages(['idempotency' => 'Unexpected idempotency result.']);
    }

    private function assertJsonOnly(Request $request): void
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));
        if (str_contains($contentType, 'multipart/')) {
            throw ValidationException::withMessages([
                'content_type' => 'Multipart uploads are not supported on the enquiry webhook.',
            ]);
        }

        if ($contentType !== '' && ! str_contains($contentType, 'application/json') && ! str_contains($contentType, '+json')) {
            throw ValidationException::withMessages([
                'content_type' => 'Content-Type must be application/json.',
            ]);
        }
    }

    private function assertContentLength(Request $request): void
    {
        $max = (int) config('enquiry_webhook.max_content_length', 65536);
        $length = strlen($request->getContent());
        if ($length > $max) {
            throw ValidationException::withMessages([
                'body' => 'Request body exceeds the maximum allowed size.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rejectTenantInjectionKeys(array $payload): void
    {
        foreach (['company_id', 'branch_id', 'owner_id', 'created_by_user_id', 'assigned_user_id'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                abort(403, 'Client-supplied tenant identifiers are not permitted.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function fingerprintInput(array $payload): array
    {
        // Strip purely presentation noise; keep semantic business fields.
        unset($payload['company_id'], $payload['branch_id'], $payload['owner_id']);

        return $payload;
    }
}
