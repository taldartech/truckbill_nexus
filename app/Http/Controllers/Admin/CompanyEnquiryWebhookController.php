<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Enquiry\Webhook\EnquiryWebhookCredentialService;
use App\Domains\Rbac\InternalAuthorization;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyEnquiryWebhookCredential;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CompanyEnquiryWebhookController extends Controller
{
    public function __construct(
        private readonly EnquiryWebhookCredentialService $credentials = new EnquiryWebhookCredentialService,
        private readonly CompanyCapabilityResolver $capabilities = new CompanyCapabilityResolver,
    ) {}

    public function store(Request $request, Company $company): RedirectResponse
    {
        $actor = $this->assertSuperAdmin($request);
        $this->assertEnquiriesEntitlement($company);

        $payload = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'receiving_branch_id' => ['nullable', 'uuid'],
        ]);

        $result = $this->credentials->create(
            company: $company,
            actor: $actor,
            label: $payload['label'] ?? null,
            receivingBranchId: $payload['receiving_branch_id'] ?? null,
        );

        return back()->with([
            'success' => 'Enquiry webhook token generated.',
            'enquiry_webhook_plain_token' => $result['plain_token'],
            'enquiry_webhook_credential_id' => $result['credential']->id,
        ]);
    }

    public function rotate(Request $request, Company $company, CompanyEnquiryWebhookCredential $credential): RedirectResponse
    {
        $actor = $this->assertSuperAdmin($request);
        $this->assertCredentialCompany($company, $credential);
        $this->assertEnquiriesEntitlement($company);

        $payload = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'receiving_branch_id' => ['nullable', 'uuid'],
            'revoke_existing' => ['sometimes', 'boolean'],
        ]);

        if (isset($payload['receiving_branch_id'])) {
            $this->credentials->updateReceivingBranch($credential, $payload['receiving_branch_id']);
            $credential = $credential->fresh();
        }

        $result = $this->credentials->rotate(
            existing: $credential,
            actor: $actor,
            revokeExistingImmediately: (bool) ($payload['revoke_existing'] ?? true),
            label: $payload['label'] ?? $credential->label,
        );

        if (isset($payload['receiving_branch_id'])) {
            $this->credentials->updateReceivingBranch($result['credential'], $payload['receiving_branch_id']);
        }

        return back()->with([
            'success' => 'Enquiry webhook token rotated.',
            'enquiry_webhook_plain_token' => $result['plain_token'],
            'enquiry_webhook_credential_id' => $result['credential']->id,
        ]);
    }

    public function revoke(Request $request, Company $company, CompanyEnquiryWebhookCredential $credential): RedirectResponse
    {
        $this->assertSuperAdmin($request);
        $this->assertCredentialCompany($company, $credential);
        $this->assertEnquiriesEntitlement($company);

        $this->credentials->revoke($credential, $request->user());

        return back()->with('success', 'Enquiry webhook token revoked.');
    }

    public function update(Request $request, Company $company, CompanyEnquiryWebhookCredential $credential): RedirectResponse
    {
        $this->assertSuperAdmin($request);
        $this->assertCredentialCompany($company, $credential);
        $this->assertEnquiriesEntitlement($company);

        $payload = $request->validate([
            'receiving_branch_id' => ['nullable', 'uuid'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        if (array_key_exists('receiving_branch_id', $payload)) {
            $this->credentials->updateReceivingBranch(
                $credential,
                $payload['receiving_branch_id'],
            );
        }

        if (array_key_exists('label', $payload)) {
            $credential->forceFill(['label' => $payload['label']])->save();
        }

        return back()->with('success', 'Enquiry webhook credential updated.');
    }

    private function assertEnquiriesEntitlement(Company $company): void
    {
        if (! $this->capabilities->allowsRead($company, CompanyCapabilities::ENQUIRIES)) {
            throw ValidationException::withMessages([
                'entitlement' => 'Company does not have enquiries capability enabled.',
            ]);
        }
    }

    private function assertCredentialCompany(Company $company, CompanyEnquiryWebhookCredential $credential): void
    {
        if ($credential->company_id !== $company->id) {
            abort(404);
        }
    }

    private function assertSuperAdmin(Request $request): User
    {
        return app(InternalAuthorization::class)->authorizeOrAbort(
            $request,
            PermissionCodes::INTERNAL_ONBOARDING_MANAGE,
        );
    }
}
