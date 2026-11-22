<?php

namespace App\Domains\Enquiry\Webhook;

use App\Models\Company;
use App\Models\CompanyEnquiryWebhookCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Company-scoped website enquiry webhook credentials (hashed at rest).
 * Public webhook endpoint is Batch B — this is Batch A foundation only.
 */
final class EnquiryWebhookCredentialService
{
    /**
     * @return array{credential: CompanyEnquiryWebhookCredential, plain_token: string}
     */
    public function create(Company $company, ?User $actor = null, ?string $label = null): array
    {
        $plain = 'tb_enq_'.Str::lower(Str::random(40));
        $prefix = substr($plain, 0, 12);

        $credential = new CompanyEnquiryWebhookCredential;
        $credential->forceFill([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'token_prefix' => $prefix,
            'token_hash' => $this->hashToken($plain),
            'label' => $label,
            'created_by_user_id' => $actor?->id,
        ]);
        $credential->save();

        return [
            'credential' => $credential,
            'plain_token' => $plain,
        ];
    }

    /**
     * Rotate: create replacement credential; optionally revoke prior immediately.
     * Overlap duration is a technical parameter (not PO-prescribed policy).
     *
     * @return array{credential: CompanyEnquiryWebhookCredential, plain_token: string}
     */
    public function rotate(
        CompanyEnquiryWebhookCredential $existing,
        ?User $actor = null,
        bool $revokeExistingImmediately = false,
        ?string $label = null,
    ): array {
        return DB::transaction(function () use ($existing, $actor, $revokeExistingImmediately, $label): array {
            $created = $this->create($existing->company, $actor, $label ?? $existing->label);
            $created['credential']->forceFill([
                'replaces_credential_id' => $existing->id,
            ])->save();

            if ($revokeExistingImmediately) {
                $this->revoke($existing, $actor);
            }

            return $created;
        });
    }

    public function revoke(CompanyEnquiryWebhookCredential $credential, ?User $actor = null): CompanyEnquiryWebhookCredential
    {
        if ($credential->revoked_at === null) {
            $credential->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor?->id,
            ])->save();
        }

        return $credential->fresh();
    }

    public function findUsableByPlainToken(string $plainToken): ?CompanyEnquiryWebhookCredential
    {
        $prefix = substr($plainToken, 0, 12);
        $hash = $this->hashToken($plainToken);

        /** @var CompanyEnquiryWebhookCredential|null $credential */
        $credential = CompanyEnquiryWebhookCredential::query()
            ->where('token_prefix', $prefix)
            ->where('token_hash', $hash)
            ->first();

        if ($credential === null || ! $credential->isUsable()) {
            return null;
        }

        return $credential;
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
