<?php

namespace App\Domains\Crm\Onboarding;

use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Domains\Identity\Enums\IdentityType;
use App\Domains\Identity\IdentityLifecycleService;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\LeadOnboardingInvitation;
use App\Models\Crm\LeadOnboardingSession;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Lead → Owner onboarding (legacy Admin invite flow, Nexus security rules).
 * Does not create Company/Branch — Owner creates Company after login.
 */
final class LeadOnboardingService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly IdentityLifecycleService $identities = new IdentityLifecycleService,
        private readonly EmailOtpService $otps = new EmailOtpService,
    ) {}

    /**
     * @return array{invitation: LeadOnboardingInvitation, plain_token: string, accept_url: string}
     */
    public function sendInvite(User $actor, Lead $lead): array
    {
        $this->assertInternalActor($actor);

        if (! $lead->status->canReceiveOnboardingInvite()) {
            throw new CrmDomainException('This lead is not eligible for an onboarding invitation.');
        }

        $email = trim((string) $lead->email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new CrmDomainException('Lead must have a valid recipient email before inviting.');
        }

        $normalized = Str::lower($email);
        $existing = User::withTrashed()->where('email', $normalized)->first();
        if ($existing !== null && $existing->isInternal()) {
            throw new CrmDomainException('Cannot invite an Internal Team email as a Customer Owner.');
        }
        if ($existing !== null && $existing->isActive() && $existing->isCustomer()) {
            throw new CrmDomainException('An active Customer account already exists for this email.');
        }

        $active = LeadOnboardingInvitation::query()
            ->where('lead_id', $lead->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
        if ($active) {
            throw new CrmDomainException('An active onboarding invitation already exists for this lead. Resend or revoke it first.');
        }

        $ttlDays = max(1, (int) config('lead_onboarding.invitation_ttl_days', 7));
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = $this->hashToken($plainToken);

        return $this->withPlatformTenant(function () use ($actor, $lead, $email, $normalized, $plainToken, $tokenHash, $ttlDays): array {
            return $this->auditedTransaction->run(function () use ($actor, $lead, $email, $normalized, $plainToken, $tokenHash, $ttlDays): array {
                $invitation = LeadOnboardingInvitation::query()->create([
                    'lead_id' => $lead->id,
                    'email' => $email,
                    'email_normalized' => $normalized,
                    'token_hash' => $tokenHash,
                    'expires_at' => now()->addDays($ttlDays),
                    'created_by_user_id' => $actor->id,
                    'delivery_status' => 'pending',
                ]);

                LeadOnboardingSession::query()->create([
                    'invitation_id' => $invitation->id,
                ]);

                $acceptUrl = $this->acceptUrl($plainToken);

                try {
                    Mail::raw(
                        "You are invited to join TruckBill.\n\nOpen this link to continue onboarding:\n{$acceptUrl}\n\nThis invitation expires in {$ttlDays} days.",
                        function ($message) use ($email): void {
                            $message->to($email)->subject('TruckBill onboarding invitation');
                        }
                    );
                    $invitation->forceFill([
                        'sent_at' => now(),
                        'delivery_status' => 'sent',
                    ])->save();
                } catch (\Throwable $e) {
                    $invitation->forceFill([
                        'delivery_status' => 'failed',
                        'delivery_error' => Str::limit($e->getMessage(), 500),
                    ])->save();
                }

                if ($lead->status !== LeadStatus::OnboardingInviteSent) {
                    $before = $lead->status;
                    $lead->status = LeadStatus::OnboardingInviteSent;
                    $lead->save();
                    $this->recordActivity(
                        $lead,
                        $actor,
                        'status_changed',
                        'Status '.$before->value.' → '.LeadStatus::OnboardingInviteSent->value.' (onboarding invite)'
                    );
                }

                $this->recordActivity($lead, $actor, 'onboarding_invite_sent', 'Onboarding invitation sent to '.$email, [
                    'invitation_id' => $invitation->id,
                    'delivery_status' => $invitation->delivery_status,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    LeadOnboardingInvitation::class,
                    $invitation->id,
                    remark: 'Lead onboarding invitation created',
                    before: null,
                    after: [
                        'lead_id' => $lead->id,
                        'email' => $email,
                        'delivery_status' => $invitation->delivery_status,
                        'expires_at' => $invitation->expires_at->toIso8601String(),
                    ],
                );

                return [
                    'invitation' => $invitation->fresh() ?? $invitation,
                    'plain_token' => $plainToken,
                    'accept_url' => $acceptUrl,
                ];
            });
        });
    }

    /**
     * @return array{invitation: LeadOnboardingInvitation, plain_token: string, accept_url: string}
     */
    public function resendInvite(User $actor, Lead $lead): array
    {
        $this->assertInternalActor($actor);

        $current = $this->latestInvitation($lead);
        if ($current !== null && $current->isActive()) {
            $this->revokeInvite($actor, $lead, 'Superseded by resend');
        }

        return $this->sendInvite($actor, $lead);
    }

    public function revokeInvite(User $actor, Lead $lead, string $remark): LeadOnboardingInvitation
    {
        $this->assertInternalActor($actor);
        if (trim($remark) === '') {
            throw new CrmDomainException('A remark is required to revoke an invitation.');
        }

        $invitation = $this->latestInvitation($lead);
        if ($invitation === null || ! $invitation->isActive()) {
            throw new CrmDomainException('No active onboarding invitation to revoke.');
        }

        return $this->withPlatformTenant(function () use ($actor, $lead, $invitation, $remark): LeadOnboardingInvitation {
            return $this->auditedTransaction->run(function () use ($actor, $lead, $invitation, $remark): LeadOnboardingInvitation {
                $invitation->forceFill([
                    'revoked_at' => now(),
                    'delivery_status' => 'revoked',
                ])->save();

                $this->recordActivity($lead, $actor, 'onboarding_invite_revoked', $remark, [
                    'invitation_id' => $invitation->id,
                ]);

                return $invitation->fresh() ?? $invitation;
            });
        });
    }

    public function resolveByPlainToken(string $plainToken): LeadOnboardingInvitation
    {
        $invitation = LeadOnboardingInvitation::query()
            ->where('token_hash', $this->hashToken($plainToken))
            ->with(['lead', 'session'])
            ->first();

        if ($invitation === null) {
            throw new CrmDomainException('Invalid onboarding invitation.');
        }

        if ($invitation->revoked_at !== null) {
            throw new CrmDomainException('This invitation has been revoked.');
        }

        if ($invitation->used_at !== null) {
            throw new CrmDomainException('This invitation has already been completed.');
        }

        if ($invitation->expires_at->isPast()) {
            throw new CrmDomainException('This invitation has expired.');
        }

        return $invitation;
    }

    /**
     * @return array{otp_id: string, expires_at: string, plain_otp?: string}
     */
    public function sendOtp(string $plainToken): array
    {
        $invitation = $this->resolveByPlainToken($plainToken);

        return $this->otps->sendForInvitation($invitation);
    }

    public function verifyOtp(string $plainToken, string $otp): LeadOnboardingSession
    {
        $invitation = $this->resolveByPlainToken($plainToken);
        $this->otps->verifyForInvitation($invitation, $otp);

        $session = $invitation->session;
        if ($session === null) {
            throw new CrmDomainException('Onboarding session is missing.');
        }

        $session->forceFill(['email_verified_at' => now()])->save();

        return $session->fresh() ?? $session;
    }

    /**
     * @param  list<string>  $acceptedKeys
     */
    public function acceptPolicies(string $plainToken, array $acceptedKeys): LeadOnboardingSession
    {
        $invitation = $this->resolveByPlainToken($plainToken);
        $session = $invitation->session;
        if ($session === null || $session->email_verified_at === null) {
            throw new CrmDomainException('Verify email before accepting policies.');
        }

        $required = array_keys(config('lead_onboarding.policies', []));
        $accepted = array_values(array_unique(array_map('strval', $acceptedKeys)));
        sort($required);
        $sortedAccepted = $accepted;
        sort($sortedAccepted);

        foreach ($required as $key) {
            if (! in_array($key, $accepted, true)) {
                throw new CrmDomainException('All required policies must be accepted.');
            }
        }

        $session->forceFill([
            'policy_accepted_at' => now(),
            'accepted_policies' => $accepted,
        ])->save();

        return $session->fresh() ?? $session;
    }

    public function setPassword(string $plainToken, string $password, string $passwordConfirmation): LeadOnboardingSession
    {
        $invitation = $this->resolveByPlainToken($plainToken);
        $session = $invitation->session;
        if ($session === null || $session->email_verified_at === null || $session->policy_accepted_at === null) {
            throw new CrmDomainException('Complete email verification and policy acceptance before setting a password.');
        }

        if ($password !== $passwordConfirmation) {
            throw ValidationException::withMessages(['password' => 'Password confirmation does not match.']);
        }

        validator(
            ['password' => $password],
            ['password' => ['required', 'string', Password::defaults()]]
        )->validate();

        $session->forceFill([
            'password_hash' => Hash::make($password),
        ])->save();

        return $session->fresh() ?? $session;
    }

    /**
     * @return array{user: User, lead: Lead, login_url: string}
     */
    public function complete(string $plainToken): array
    {
        $invitation = $this->resolveByPlainToken($plainToken);
        $session = $invitation->session;
        $lead = $invitation->lead;

        if ($session === null || $lead === null) {
            throw new CrmDomainException('Onboarding session is incomplete.');
        }

        if ($session->email_verified_at === null || $session->policy_accepted_at === null || $session->password_hash === null) {
            throw new CrmDomainException('Onboarding steps are incomplete.');
        }

        if ($session->completed_at !== null || $invitation->used_at !== null) {
            throw new CrmDomainException('This invitation has already been completed.');
        }

        return $this->withPlatformTenant(function () use ($invitation, $session, $lead): array {
            return $this->auditedTransaction->run(function () use ($invitation, $session, $lead): array {
                $locked = LeadOnboardingInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
                if ($locked->used_at !== null || $locked->revoked_at !== null || $locked->expires_at->isPast()) {
                    throw new CrmDomainException('Invitation is no longer usable.');
                }

                $existing = User::withTrashed()->where('email', $locked->email_normalized)->first();
                if ($existing !== null && $existing->isInternal()) {
                    throw new CrmDomainException('Cannot create Customer Owner for an Internal email.');
                }

                if ($existing !== null && $existing->trashed()) {
                    throw new CrmDomainException('A deleted identity exists for this email. Contact support.');
                }

                if ($existing !== null) {
                    if (! $existing->isCustomer()) {
                        throw new CrmDomainException('Email is already associated with a non-customer identity.');
                    }
                    $user = $existing;
                    $user->forceFill([
                        'email_verified_at' => $session->email_verified_at ?? now(),
                        'deactivated_at' => null,
                        'name' => $lead->contact_name !== '' ? $lead->contact_name : $user->name,
                    ])->save();
                } else {
                    $user = $this->identities->create([
                        'name' => $lead->contact_name !== '' ? $lead->contact_name : 'Owner',
                        'email' => $locked->email_normalized,
                        'password' => 'Temporary-Pass1!',
                        'identity_type' => IdentityType::Customer,
                    ]);
                    $user->forceFill([
                        'email_verified_at' => $session->email_verified_at ?? now(),
                    ])->save();
                }

                // password_hash is already bcrypt — update via query to avoid double-hashing cast.
                User::query()->whereKey($user->id)->update([
                    'password' => $session->password_hash,
                ]);
                $user->refresh();

                $session->forceFill(['completed_at' => now()])->save();
                $locked->forceFill([
                    'used_at' => now(),
                    'completed_user_id' => $user->id,
                    'delivery_status' => 'completed',
                ])->save();

                if ($lead->status !== LeadStatus::Onboarded && ! $lead->status->isTerminal()) {
                    $before = $lead->status;
                    $lead->status = LeadStatus::Onboarded;
                    $lead->save();
                    $this->recordActivity(
                        $lead,
                        null,
                        'status_changed',
                        'Status '.$before->value.' → onboarded (onboarding completed)'
                    );
                }

                $this->recordActivity($lead, null, 'onboarding_completed', 'Owner onboarding completed for '.$user->email, [
                    'user_id' => $user->id,
                    'invitation_id' => $locked->id,
                ]);

                return [
                    'user' => $user->fresh() ?? $user,
                    'lead' => $lead->fresh() ?? $lead,
                    'login_url' => (string) config('lead_onboarding.login_url'),
                ];
            });
        });
    }

    public function latestInvitation(Lead $lead): ?LeadOnboardingInvitation
    {
        return LeadOnboardingInvitation::query()
            ->where('lead_id', $lead->id)
            ->orderByDesc('created_at')
            ->first();
    }

    public function acceptUrl(string $plainToken): string
    {
        return rtrim((string) config('lead_onboarding.frontend_url'), '/').'/onboarding/'.$plainToken;
    }

    public function hashToken(string $plainToken): string
    {
        return hash_hmac('sha256', $plainToken, (string) config('app.key'));
    }

    private function assertInternalActor(User $actor): void
    {
        if (! $actor->isInternal() || ! $actor->isActive()) {
            throw new CrmDomainException('Only active Internal Team members may manage onboarding invitations.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function recordActivity(Lead $lead, ?User $actor, string $type, ?string $body, ?array $meta = null): void
    {
        LeadActivity::query()->create([
            'lead_id' => $lead->id,
            'actor_user_id' => $actor?->id,
            'activity_type' => $type,
            'body' => $body,
            'meta' => $meta,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withPlatformTenant(callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(isInternal: true));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::set($previous);
            }
        }
    }
}
