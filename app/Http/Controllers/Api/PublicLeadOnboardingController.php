<?php

namespace App\Http\Controllers\Api;

use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Domains\Crm\Onboarding\LeadOnboardingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Public website → Nexus onboarding API (token-scoped, no CRM privileges).
 */
class PublicLeadOnboardingController extends Controller
{
    public function __construct(
        private readonly LeadOnboardingService $onboarding = new LeadOnboardingService,
    ) {}

    public function show(string $token): JsonResponse
    {
        try {
            $invitation = $this->onboarding->resolveByPlainToken($token);
        } catch (CrmDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $lead = $invitation->lead;
        $session = $invitation->session;

        return response()->json([
            'status' => $invitation->statusLabel(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'recipient' => [
                'email' => $invitation->email,
                'contact_name' => $lead?->contact_name,
                'company_name' => $lead?->company_name,
                'interested_product' => $lead?->interested_product?->value,
            ],
            'steps' => [
                'email_verified' => $session?->email_verified_at !== null,
                'policies_accepted' => $session?->policy_accepted_at !== null,
                'password_set' => filled($session?->password_hash),
                'completed' => $session?->completed_at !== null,
            ],
            'policies' => $this->policyList(),
            'login_url' => config('lead_onboarding.login_url'),
        ]);
    }

    public function sendOtp(string $token): JsonResponse
    {
        try {
            $result = $this->onboarding->sendOtp($token);
        } catch (CrmDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload = [
            'message' => 'OTP sent to the invitation email.',
            'expires_at' => $result['expires_at'],
        ];
        if (isset($result['plain_otp'])) {
            $payload['debug_otp'] = $result['plain_otp'];
        }

        return response()->json($payload);
    }

    public function verifyOtp(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'string', 'max:12'],
        ]);

        try {
            $this->onboarding->verifyOtp($token, $validated['otp']);
        } catch (CrmDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Email verified.']);
    }

    public function acceptPolicies(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'policies' => ['required', 'array', 'min:1'],
            'policies.*' => ['string', 'max:64'],
        ]);

        try {
            $this->onboarding->acceptPolicies($token, $validated['policies']);
        } catch (CrmDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Policies accepted.']);
    }

    public function setPassword(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'password_confirmation' => ['required', 'string'],
        ]);

        try {
            $this->onboarding->setPassword(
                $token,
                $validated['password'],
                $validated['password_confirmation'],
            );
        } catch (CrmDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json(['message' => 'Password saved.']);
    }

    public function complete(string $token): JsonResponse
    {
        try {
            $result = $this->onboarding->complete($token);
        } catch (CrmDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Onboarding completed.',
            'login_url' => $result['login_url'],
        ]);
    }

    /**
     * Website onboarding UI expects a list of {key, label, url}.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    private function policyList(): array
    {
        $configured = config('lead_onboarding.policies', []);
        if (! is_array($configured)) {
            return [];
        }

        $labels = [
            'terms' => 'Terms of Service',
            'privacy' => 'Privacy Policy',
            'refund' => 'Refund Policy',
            'acceptable_use' => 'Acceptable Use Policy',
        ];

        $policies = [];
        foreach ($configured as $key => $url) {
            if (! is_string($key) || ! is_string($url) || $url === '') {
                continue;
            }
            $policies[] = [
                'key' => $key,
                'label' => $labels[$key] ?? ucwords(str_replace('_', ' ', $key)),
                'url' => $url,
            ];
        }

        return $policies;
    }
}
