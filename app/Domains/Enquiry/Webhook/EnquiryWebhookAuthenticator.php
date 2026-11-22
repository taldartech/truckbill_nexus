<?php

namespace App\Domains\Enquiry\Webhook;

use App\Domains\Enquiry\Exceptions\EnquiryWebhookAuthenticationException;
use App\Models\Company;
use App\Models\CompanyEnquiryWebhookCredential;
use Illuminate\Http\Request;

/**
 * Resolves company from bearer / X-TruckBill-Webhook-Token only. Never trusts payload tenant fields.
 */
final class EnquiryWebhookAuthenticator
{
    public function __construct(
        private readonly EnquiryWebhookCredentialService $credentials = new EnquiryWebhookCredentialService,
    ) {}

    /**
     * @return array{company: Company, credential: CompanyEnquiryWebhookCredential}
     */
    public function authenticate(Request $request): array
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            throw new EnquiryWebhookAuthenticationException('Missing webhook credentials.');
        }

        $credential = $this->credentials->findUsableByPlainToken($token);
        if ($credential === null) {
            throw new EnquiryWebhookAuthenticationException('Invalid webhook credentials.');
        }

        /** @var Company $company */
        $company = Company::query()->findOrFail($credential->company_id);

        if (! $company->isActive()) {
            throw new EnquiryWebhookAuthenticationException('Company is not active.');
        }

        return [
            'company' => $company,
            'credential' => $credential,
        ];
    }

    private function extractToken(Request $request): ?string
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if ($authorization !== '' && preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) === 1) {
            return $matches[1];
        }

        $headerToken = trim((string) $request->header('X-TruckBill-Webhook-Token', ''));
        if ($headerToken !== '') {
            return $headerToken;
        }

        // Explicitly reject query-string tokens.
        if ($request->query->has('access_token') || $request->query->has('token')) {
            throw new EnquiryWebhookAuthenticationException('Webhook tokens must not be supplied in the query string.');
        }

        return null;
    }
}
