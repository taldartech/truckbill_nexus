<?php

namespace App\Domains\Company;

use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Geography\GeographyLookupService;
use App\Models\GstRate;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * PRD §7 company profile validation (D1 / PO-P1-P5-FULLSTACK-AUTH).
 */
final class CompanyProfileValidator
{
    public const PAN_PATTERN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public const GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public const PIN_PATTERN = '/^[1-9][0-9]{5}$/';

    public const MOBILE_PATTERN = '/^[6-9][0-9]{9}$/';

    public function __construct(
        private readonly GeographyLookupService $geography = new GeographyLookupService,
    ) {}

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function normalizeAndValidate(array $profile, bool $requireComplete): array
    {
        $stateId = filled($profile['state_id'] ?? null) ? (string) $profile['state_id'] : null;
        $cityId = filled($profile['city_id'] ?? null) ? (string) $profile['city_id'] : null;

        if ($stateId === null && filled($profile['state'] ?? null)) {
            $stateId = $this->geography->findActiveStateIdByName((string) $profile['state']);
        }
        if ($cityId === null && $stateId !== null && filled($profile['city'] ?? null)) {
            $cityId = $this->geography->findActiveCityIdByName($stateId, (string) $profile['city']);
        }

        try {
            $resolvedGeography = $this->geography->resolveAddressFields($stateId, $cityId);
        } catch (ValidationException $e) {
            $messages = $e->errors();
            $first = collect($messages)->flatten()->first();
            throw new CompanyDomainException(is_string($first) ? $first : 'State and city selection is invalid.');
        }

        $normalized = [
            'pan' => strtoupper(trim((string) ($profile['pan'] ?? ''))),
            'registered_address' => trim((string) ($profile['registered_address'] ?? '')),
            'state' => (string) ($resolvedGeography['state'] ?? ''),
            'state_id' => $resolvedGeography['state_id'],
            'city' => (string) ($resolvedGeography['city'] ?? ''),
            'city_id' => $resolvedGeography['city_id'],
            'pin' => trim((string) ($profile['pin'] ?? '')),
            'contact_person' => trim((string) ($profile['contact_person'] ?? '')),
            'mobile' => preg_replace('/\D+/', '', (string) ($profile['mobile'] ?? '')) ?? '',
            'email' => strtolower(trim((string) ($profile['email'] ?? ''))),
            'gst_registration_type' => $this->enumValue(GstRegistrationType::class, $profile['gst_registration_type'] ?? null),
            'tax_liability' => $this->enumValue(TaxLiability::class, $profile['tax_liability'] ?? null),
            'gstin' => strtoupper(trim((string) ($profile['gstin'] ?? ''))),
            'gst_rate_id' => filled($profile['gst_rate_id'] ?? null) ? (string) $profile['gst_rate_id'] : null,
            'data_visibility' => $this->enumValue(DataVisibility::class, $profile['data_visibility'] ?? null),
            'timezone' => trim((string) ($profile['timezone'] ?? 'Asia/Kolkata')) ?: 'Asia/Kolkata',
            'logo_file_id' => filled($profile['logo_file_id'] ?? null) ? (string) $profile['logo_file_id'] : null,
            'signature_file_id' => filled($profile['signature_file_id'] ?? null) ? (string) $profile['signature_file_id'] : null,
            'bank_name' => trim((string) ($profile['bank_name'] ?? '')),
            'account_number' => trim((string) ($profile['account_number'] ?? '')),
            'ifsc' => strtoupper(trim((string) ($profile['ifsc'] ?? ''))),
        ];

        if (! $requireComplete) {
            return $normalized;
        }

        if ($normalized['pan'] === '' || ! preg_match(self::PAN_PATTERN, $normalized['pan'])) {
            throw new CompanyDomainException('A valid PAN is required (AAAAA9999A).');
        }

        if ($normalized['registered_address'] === '') {
            throw new CompanyDomainException('Registered address is required.');
        }

        if ($normalized['state_id'] === null || $normalized['city_id'] === null
            || $normalized['state'] === '' || $normalized['city'] === '') {
            throw new CompanyDomainException('State and city must be selected from the geographic master.');
        }

        if ($normalized['pin'] === '' || ! preg_match(self::PIN_PATTERN, $normalized['pin'])) {
            throw new CompanyDomainException('A valid 6-digit PIN is required.');
        }

        if ($normalized['mobile'] === '' || ! preg_match(self::MOBILE_PATTERN, $normalized['mobile'])) {
            throw new CompanyDomainException('A valid 10-digit mobile number is required.');
        }

        if ($normalized['gst_registration_type'] === null) {
            throw new CompanyDomainException('GST registration type is required.');
        }

        if ($normalized['tax_liability'] === null) {
            throw new CompanyDomainException('Tax liability (FCM/RCM) is required.');
        }

        if ($normalized['data_visibility'] === null) {
            throw new CompanyDomainException('Data visibility is required.');
        }

        $type = GstRegistrationType::from($normalized['gst_registration_type']);
        if ($type !== GstRegistrationType::Unregistered) {
            if ($normalized['gstin'] === '' || ! preg_match(self::GSTIN_PATTERN, $normalized['gstin'])) {
                throw new CompanyDomainException('A valid GSTIN is required for Regular/Composition registration.');
            }

            if ($normalized['gst_rate_id'] === null) {
                throw new CompanyDomainException('An active GST rate is required for Regular/Composition registration.');
            }
        } else {
            $normalized['gstin'] = $normalized['gstin'] === '' ? null : $normalized['gstin'];
            if ($normalized['gstin'] !== null && ! preg_match(self::GSTIN_PATTERN, $normalized['gstin'])) {
                throw new CompanyDomainException('GSTIN format is invalid.');
            }
        }

        if ($normalized['gst_rate_id'] !== null) {
            $rate = GstRate::query()
                ->whereKey($normalized['gst_rate_id'])
                ->where('is_active', true)
                ->where('effective_from', '<=', Carbon::today())
                ->where(function ($q): void {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', Carbon::today());
                })
                ->first();

            if ($rate === null) {
                throw new CompanyDomainException('Selected GST rate is not active.');
            }

            $normalized['gst_rate_snapshot'] = $rate->toSnapshot();
        } else {
            $normalized['gst_rate_snapshot'] = null;
        }

        $bankFields = [$normalized['bank_name'], $normalized['account_number'], $normalized['ifsc']];
        $bankFilled = count(array_filter($bankFields, fn (string $v): bool => $v !== ''));
        if ($bankFilled > 0 && $bankFilled < 3) {
            throw new CompanyDomainException('Bank name, account number and IFSC must all be provided together.');
        }

        return $normalized;
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    private function enumValue(string $enum, mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            return null;
        }

        foreach ($enum::cases() as $case) {
            if ($case->value === $raw || strcasecmp($case->name, $raw) === 0) {
                return (string) $case->value;
            }
        }

        throw new CompanyDomainException('Invalid value for '.$enum.'.');
    }
}
