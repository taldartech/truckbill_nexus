<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyEnquiryWebhookCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CompanyEnquiryWebhookCredential> */
class CompanyEnquiryWebhookCredentialFactory extends Factory
{
    protected $model = CompanyEnquiryWebhookCredential::class;

    public function definition(): array
    {
        $plain = 'tb_enq_'.Str::lower(Str::random(40));

        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => hash('sha256', $plain),
            'label' => 'test',
            'expires_at' => null,
            'revoked_at' => null,
            'created_by_user_id' => null,
            'revoked_by_user_id' => null,
            'replaces_credential_id' => null,
        ];
    }
}
