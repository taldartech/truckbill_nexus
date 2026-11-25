<?php

namespace App\Domains\Crm\Onboarding;

use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Models\Crm\LeadOnboardingInvitation;
use App\Models\EmailOtp;
use Illuminate\Support\Facades\Mail;

final class EmailOtpService
{
    public const PURPOSE_LEAD_ONBOARDING = 'lead_onboarding';

    /**
     * @return array{otp_id: string, expires_at: string, plain_otp?: string}
     */
    public function sendForInvitation(LeadOnboardingInvitation $invitation): array
    {
        $email = $invitation->email_normalized;
        $throttleSeconds = max(1, (int) config('lead_onboarding.otp_send_throttle_seconds', 60));

        $recent = EmailOtp::query()
            ->where('purpose', self::PURPOSE_LEAD_ONBOARDING)
            ->where('subject_id', $invitation->id)
            ->where('email_normalized', $email)
            ->whereNull('consumed_at')
            ->where('created_at', '>=', now()->subSeconds($throttleSeconds))
            ->exists();

        if ($recent) {
            throw new CrmDomainException('Please wait before requesting another OTP.');
        }

        // Invalidate prior unused OTPs for this invitation.
        EmailOtp::query()
            ->where('purpose', self::PURPOSE_LEAD_ONBOARDING)
            ->where('subject_id', $invitation->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $length = max(4, (int) config('lead_onboarding.otp_length', 6));
        $ttlMinutes = max(1, (int) config('lead_onboarding.otp_ttl_minutes', 3));
        $maxAttempts = max(1, (int) config('lead_onboarding.otp_max_attempts', 5));

        $plain = '';
        for ($i = 0; $i < $length; $i++) {
            $plain .= (string) random_int(0, 9);
        }

        $otp = EmailOtp::query()->create([
            'purpose' => self::PURPOSE_LEAD_ONBOARDING,
            'subject_type' => LeadOnboardingInvitation::class,
            'subject_id' => $invitation->id,
            'email_normalized' => $email,
            'otp_hash' => $this->hashOtp($email, $plain),
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        Mail::raw(
            'Your TruckBill verification code is '.$plain.'. It expires in '.$ttlMinutes.' minutes.',
            function ($message) use ($invitation): void {
                $message->to($invitation->email)
                    ->subject('TruckBill onboarding verification code');
            }
        );

        $payload = [
            'otp_id' => $otp->id,
            'expires_at' => $otp->expires_at->toIso8601String(),
        ];

        // Expose plain OTP only when using array/log mailers for automated tests.
        if (in_array(config('mail.default'), ['array', 'log'], true)) {
            $payload['plain_otp'] = $plain;
        }

        return $payload;
    }

    public function verifyForInvitation(LeadOnboardingInvitation $invitation, string $otp): void
    {
        $email = $invitation->email_normalized;
        $record = EmailOtp::query()
            ->where('purpose', self::PURPOSE_LEAD_ONBOARDING)
            ->where('subject_id', $invitation->id)
            ->where('email_normalized', $email)
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->first();

        if ($record === null) {
            throw new CrmDomainException('No active OTP found. Request a new code.');
        }

        if ($record->expires_at->isPast()) {
            throw new CrmDomainException('OTP has expired. Request a new code.');
        }

        if ($record->attempts >= $record->max_attempts) {
            throw new CrmDomainException('Too many OTP attempts. Request a new code.');
        }

        $record->attempts = $record->attempts + 1;
        $record->save();

        if (! hash_equals($record->otp_hash, $this->hashOtp($email, trim($otp)))) {
            throw new CrmDomainException('Incorrect OTP.');
        }

        $record->consumed_at = now();
        $record->save();
    }

    private function hashOtp(string $emailNormalized, string $otp): string
    {
        return hash_hmac('sha256', $emailNormalized.'|'.$otp, (string) config('app.key'));
    }
}
