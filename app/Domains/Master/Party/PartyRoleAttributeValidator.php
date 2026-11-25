<?php

namespace App\Domains\Master\Party;

use App\Domains\Company\CompanyProfileValidator;
use App\Domains\Master\Party\Enums\PartyRoleType;
use Illuminate\Validation\ValidationException;

/**
 * Typed allowlists and required sets for party / role_attributes (Rules §6).
 */
final class PartyRoleAttributeValidator
{
    /** @var list<string> */
    public const PAN_STATUSES = ['active', 'inactive', 'invalid', 'valid'];

    /** @var list<string> */
    private const CUSTOMER_FAMILY = ['customer', 'consignor', 'consignee'];

    /** @var array<string, list<string>> */
    private const ROLE_ATTRIBUTE_KEYS = [
        'customer' => [],
        'consignor' => [],
        'consignee' => [],
        'transporter' => [
            'pan',
            'pan_status',
            'declaration',
            'tds_percentage',
            'tds_override',
            'tds_adjustment_remark',
            'calculated_tds_rate',
            'applicable_tds_rate',
            'bank_account',
            'short_name',
            'beneficiary_id',
            'goods_vehicles_count',
            'declaration_type',
        ],
        'driver' => [
            'driver_type',
            'license_number',
            'license_expiry',
            'joining_date',
            'aadhaar',
            'pan',
            'bank_name',
            'bank_account_number',
            'ifsc',
            'emergency_contact_name',
            'emergency_contact_mobile',
            'monthly_payment_base',
        ],
        'general_vendor' => [],
        'pump_vendor' => [],
    ];

    /**
     * Validate party-level fields required by the selected roles.
     *
     * @param  array<string, mixed>  $partyAttributes
     * @param  list<string>  $roleValues
     */
    public function validatePartyAttributes(array $partyAttributes, array $roleValues): void
    {
        $roles = array_values(array_unique(array_filter($roleValues, is_string(...))));

        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => 'At least one party role is required.']);
        }

        $this->assertFilled($partyAttributes, 'name', 'Name is required.');

        $needsCustomerFamily = $this->intersects($roles, self::CUSTOMER_FAMILY);
        $needsTransporter = in_array(PartyRoleType::Transporter->value, $roles, true);
        $needsDriver = in_array(PartyRoleType::Driver->value, $roles, true);
        $needsVendor = $this->intersects($roles, [
            PartyRoleType::GeneralVendor->value,
            PartyRoleType::PumpVendor->value,
        ]);

        if ($needsCustomerFamily || $needsTransporter || $needsDriver) {
            $this->assertFilled($partyAttributes, 'contact_number', 'Contact number is required.');
        }

        if ($needsCustomerFamily || $needsTransporter) {
            $hasState = filled($partyAttributes['state'] ?? null) || filled($partyAttributes['state_id'] ?? null);
            $hasCity = filled($partyAttributes['city'] ?? null) || filled($partyAttributes['city_id'] ?? null);
            if (! $hasState) {
                throw ValidationException::withMessages(['state' => 'State is required.']);
            }
            if (! $hasCity) {
                throw ValidationException::withMessages(['city' => 'City is required.']);
            }
        }

        if ($needsVendor) {
            $this->assertFilled($partyAttributes, 'contact_person', 'Contact person is required for vendor roles.');
            $this->assertFilled($partyAttributes, 'contact_number', 'Contact number is required for vendor roles.');
        }

        if ($needsTransporter) {
            $this->assertFilled($partyAttributes, 'pan', 'PAN is required for transporters.');
            $this->assertPanFormat($partyAttributes['pan'] ?? null, 'pan');
        }

        $this->assertOptionalFormats($partyAttributes);
    }

    /**
     * Validate and normalize role_attributes for a single role.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $partyAttributes
     * @return array<string, mixed>
     */
    public function validateAndNormalize(
        PartyRoleType $role,
        array $attributes,
        array $partyAttributes = [],
    ): array {
        $allowlist = self::ROLE_ATTRIBUTE_KEYS[$role->value];
        $unknown = array_diff(array_keys($attributes), $allowlist);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'roles.attributes' => 'Unknown attribute(s) for '.$role->value.': '.implode(', ', $unknown).'.',
            ]);
        }

        return match ($role) {
            PartyRoleType::Transporter => $this->normalizeTransporter($attributes, $partyAttributes),
            PartyRoleType::Driver => $this->normalizeDriver($attributes, $partyAttributes),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $partyAttributes
     * @return array<string, mixed>
     */
    private function normalizeTransporter(array $attributes, array $partyAttributes): array
    {
        $pan = strtoupper(trim((string) ($attributes['pan'] ?? $partyAttributes['pan'] ?? '')));
        if ($pan === '') {
            throw ValidationException::withMessages([
                'roles.attributes.pan' => 'Transporter requires pan.',
            ]);
        }
        $this->assertPanFormat($pan, 'roles.attributes.pan');

        $partyPan = strtoupper(trim((string) ($partyAttributes['pan'] ?? '')));
        if ($partyPan !== '' && $partyPan !== $pan) {
            throw ValidationException::withMessages([
                'roles.attributes.pan' => 'Transporter PAN must match party PAN.',
            ]);
        }

        $panStatus = strtolower(trim((string) ($attributes['pan_status'] ?? '')));
        if (! in_array($panStatus, self::PAN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'roles.attributes.pan_status' => 'Transporter pan_status must be one of: '.implode(', ', self::PAN_STATUSES).'.',
            ]);
        }

        if (! array_key_exists('declaration', $attributes) || $attributes['declaration'] === null || $attributes['declaration'] === '') {
            throw ValidationException::withMessages([
                'roles.attributes.declaration' => 'Transporter requires declaration.',
            ]);
        }

        $declarationValid = $this->normalizeDeclarationFlag($attributes['declaration']);

        $normalized = [
            'pan' => $pan,
            'pan_status' => $panStatus,
            'declaration' => $declarationValid,
        ];

        if (array_key_exists('declaration_type', $attributes) && filled($attributes['declaration_type'])) {
            $type = strtolower(trim((string) $attributes['declaration_type']));
            if (! in_array($type, ['owner', 'agency'], true)) {
                throw ValidationException::withMessages([
                    'roles.attributes.declaration_type' => 'Declaration type must be owner or agency.',
                ]);
            }
            $normalized['declaration_type'] = $type;
        }

        if (array_key_exists('tds_percentage', $attributes) && $attributes['tds_percentage'] !== null && $attributes['tds_percentage'] !== '') {
            if (! is_numeric($attributes['tds_percentage'])) {
                throw ValidationException::withMessages([
                    'roles.attributes.tds_percentage' => 'TDS percentage must be numeric.',
                ]);
            }
            $normalized['tds_percentage'] = (string) $attributes['tds_percentage'];
        }

        $tdsOverride = filter_var($attributes['tds_override'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? (bool) ($attributes['tds_override'] ?? false);
        if ($tdsOverride) {
            $normalized['tds_override'] = true;
            if (! filled($attributes['tds_adjustment_remark'] ?? null)) {
                throw ValidationException::withMessages([
                    'roles.attributes.tds_adjustment_remark' => 'TDS adjustment remark is required when TDS is manually overridden.',
                ]);
            }
            $normalized['tds_adjustment_remark'] = trim((string) $attributes['tds_adjustment_remark']);
        } elseif (array_key_exists('tds_override', $attributes)) {
            $normalized['tds_override'] = false;
        }

        if (array_key_exists('tds_adjustment_remark', $attributes) && ! $tdsOverride && filled($attributes['tds_adjustment_remark'] ?? null)) {
            $normalized['tds_adjustment_remark'] = trim((string) $attributes['tds_adjustment_remark']);
        }

        foreach (['short_name', 'beneficiary_id'] as $optionalString) {
            if (array_key_exists($optionalString, $attributes) && filled($attributes[$optionalString])) {
                $normalized[$optionalString] = trim((string) $attributes[$optionalString]);
            }
        }

        if (array_key_exists('bank_account', $attributes) && $attributes['bank_account'] !== null && $attributes['bank_account'] !== '') {
            $normalized['bank_account'] = $attributes['bank_account'];
        }

        if (array_key_exists('goods_vehicles_count', $attributes) && $attributes['goods_vehicles_count'] !== null && $attributes['goods_vehicles_count'] !== '') {
            if (! is_numeric($attributes['goods_vehicles_count']) || (int) $attributes['goods_vehicles_count'] < 0) {
                throw ValidationException::withMessages([
                    'roles.attributes.goods_vehicles_count' => 'Goods vehicles count must be a non-negative integer.',
                ]);
            }
            $normalized['goods_vehicles_count'] = (int) $attributes['goods_vehicles_count'];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $partyAttributes
     * @return array<string, mixed>
     */
    private function normalizeDriver(array $attributes, array $partyAttributes): array
    {
        if (! filled($partyAttributes['contact_number'] ?? null)) {
            throw ValidationException::withMessages([
                'contact_number' => 'Contact number (mobile) is required for drivers.',
            ]);
        }

        $type = strtolower(trim((string) ($attributes['driver_type'] ?? '')));
        if (! in_array($type, ['permanent', 'market'], true)) {
            throw ValidationException::withMessages([
                'roles.attributes.driver_type' => 'Driver type must be permanent or market.',
            ]);
        }

        $normalized = ['driver_type' => $type];

        if ($type === 'permanent') {
            if (! filled($attributes['license_number'] ?? null)) {
                throw ValidationException::withMessages([
                    'roles.attributes.license_number' => 'Permanent drivers require a license number.',
                ]);
            }
            $normalized['license_number'] = trim((string) $attributes['license_number']);
        } elseif (filled($attributes['license_number'] ?? null)) {
            $normalized['license_number'] = trim((string) $attributes['license_number']);
        }

        if (filled($attributes['license_expiry'] ?? null)) {
            $expiry = trim((string) $attributes['license_expiry']);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
                throw ValidationException::withMessages([
                    'roles.attributes.license_expiry' => 'License expiry must be a date (YYYY-MM-DD).',
                ]);
            }
            $normalized['license_expiry'] = $expiry;
        }

        if ($type === 'permanent') {
            foreach ([
                'joining_date',
                'aadhaar',
                'pan',
                'bank_name',
                'bank_account_number',
                'ifsc',
                'emergency_contact_name',
                'emergency_contact_mobile',
                'monthly_payment_base',
            ] as $optional) {
                if (! array_key_exists($optional, $attributes) || ! filled($attributes[$optional])) {
                    continue;
                }

                if ($optional === 'joining_date') {
                    $date = trim((string) $attributes[$optional]);
                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        throw ValidationException::withMessages([
                            'roles.attributes.joining_date' => 'Joining date must be a date (YYYY-MM-DD).',
                        ]);
                    }
                    $normalized['joining_date'] = $date;

                    continue;
                }

                if ($optional === 'pan') {
                    $pan = strtoupper(trim((string) $attributes['pan']));
                    $this->assertPanFormat($pan, 'roles.attributes.pan');
                    $normalized['pan'] = $pan;

                    continue;
                }

                $normalized[$optional] = is_numeric($attributes[$optional]) && $optional === 'monthly_payment_base'
                    ? (string) $attributes[$optional]
                    : trim((string) $attributes[$optional]);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $partyAttributes
     */
    private function assertOptionalFormats(array $partyAttributes): void
    {
        if (filled($partyAttributes['email'] ?? null)) {
            $email = trim((string) $partyAttributes['email']);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'email' => 'Email format is invalid.',
                ]);
            }
        }

        if (filled($partyAttributes['gstin'] ?? null)) {
            $gstin = strtoupper(trim((string) $partyAttributes['gstin']));
            if (! preg_match(CompanyProfileValidator::GSTIN_PATTERN, $gstin)) {
                throw ValidationException::withMessages([
                    'gstin' => 'GSTIN format is invalid.',
                ]);
            }
        }

        if (filled($partyAttributes['pan'] ?? null)) {
            $this->assertPanFormat($partyAttributes['pan'], 'pan');
        }
    }

    private function assertPanFormat(mixed $pan, string $field): void
    {
        $value = strtoupper(trim((string) $pan));
        if (! preg_match(CompanyProfileValidator::PAN_PATTERN, $value)) {
            throw ValidationException::withMessages([
                $field => 'PAN format is invalid (AAAAA9999A).',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertFilled(array $attributes, string $key, string $message): void
    {
        if (! filled($attributes[$key] ?? null)) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }

    private function normalizeDeclarationFlag(mixed $declaration): bool
    {
        if (is_bool($declaration)) {
            return $declaration;
        }

        if (is_string($declaration)) {
            $lower = strtolower(trim($declaration));

            return in_array($lower, ['1', 'true', 'yes', 'on'], true);
        }

        if (is_int($declaration) || is_float($declaration)) {
            return (int) $declaration === 1;
        }

        return (bool) $declaration;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needles
     */
    private function intersects(array $haystack, array $needles): bool
    {
        return array_intersect($haystack, $needles) !== [];
    }
}
