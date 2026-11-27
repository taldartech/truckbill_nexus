<?php

declare(strict_types=1);

namespace App\Support\Presentation;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\Party;
use App\Models\PartyRole;

/**
 * Role-driven, read-only Party profile sections; empty values and empty sections are omitted.
 *
 * @phpstan-type ProfileItem array{label: string, value: string, kind?: 'date'}
 * @phpstan-type ProfileSection array{key: string, title: string, items: list<ProfileItem>}
 */
final class PartyProfilePresentation
{
    private const BUSINESS_ROLES = ['customer', 'consignor', 'consignee', 'transporter', 'general_vendor', 'pump_vendor'];

    /**
     * @return list<ProfileSection>
     */
    public function sections(Party $party): array
    {
        $roles = $party->roles->filter(fn (PartyRole $role): bool => $role->deactivated_at === null);
        $transporter = $this->roleAttributes($roles->all(), PartyRoleType::Transporter);
        $driver = $this->roleAttributes($roles->all(), PartyRoleType::Driver);
        $roleValues = $roles->map(fn (PartyRole $role): string => (string) $role->role)->all();

        $sections = [
            $this->section('basic', 'Basic information', [
                $this->item('Contact person', $party->contact_person),
                $this->item('Contact number', $party->contact_number),
                $this->item('Email', $party->email),
                $this->item('City', $party->city),
                $this->item('State', $party->state),
                $this->item('Address', $party->address),
            ]),
        ];

        if (array_intersect($roleValues, self::BUSINESS_ROLES) !== []) {
            $sections[] = $this->section('business', 'Business & tax', [
                $this->item('GSTIN', $party->gstin),
                $this->item('PAN', $party->pan),
                $this->item('GST applicable', $party->is_gst_applicable ? 'Yes' : 'No'),
                ...($transporter === null ? [] : [
                    $this->item('Short name', $transporter['short_name'] ?? null),
                    $this->item('PAN status', $this->titleCase($transporter['pan_status'] ?? null)),
                    $this->item('TDS declaration', $this->yesNo($transporter['declaration'] ?? null)),
                    $this->item('Declaration type', $this->titleCase($transporter['declaration_type'] ?? null)),
                    $this->item('Goods vehicles', $transporter['goods_vehicles_count'] ?? null),
                    $this->item('Applicable TDS rate', $this->percent($transporter['applicable_tds_rate'] ?? null)),
                ]),
            ]);
        }

        if ($driver !== null) {
            $sections[] = $this->section('driver', 'Driver details', [
                $this->item('Driver type', $this->titleCase($driver['driver_type'] ?? null)),
                $this->item('Licence number', $driver['license_number'] ?? null),
                $this->item('Licence expiry', $driver['license_expiry'] ?? null, 'date'),
                $this->item('Joining date', $driver['joining_date'] ?? null, 'date'),
                $this->item('Aadhaar', $this->masked($driver['aadhaar'] ?? null)),
                $this->item('Monthly payment base', $driver['monthly_payment_base'] ?? null),
                $this->item('Emergency contact', $driver['emergency_contact_name'] ?? null),
                $this->item('Emergency contact mobile', $driver['emergency_contact_mobile'] ?? null),
            ]);
        }

        $sections[] = $this->section('bank', 'Bank details', [
            $this->item('Bank name', $driver['bank_name'] ?? null),
            $this->item('Account number', $driver['bank_account_number'] ?? $transporter['bank_account'] ?? null),
            $this->item('IFSC', $driver['ifsc'] ?? null),
            $this->item('Beneficiary ID', $transporter['beneficiary_id'] ?? null),
        ]);

        return array_values(array_filter($sections, fn (array $section): bool => $section['items'] !== []));
    }

    /**
     * @param  array<int, PartyRole>  $roles
     * @return array<string, mixed>|null
     */
    private function roleAttributes(array $roles, PartyRoleType $type): ?array
    {
        foreach ($roles as $role) {
            if ((string) $role->role === $type->value) {
                return is_array($role->role_attributes) ? $role->role_attributes : [];
            }
        }

        return null;
    }

    /**
     * @param  list<ProfileItem|null>  $items
     * @return ProfileSection
     */
    private function section(string $key, string $title, array $items): array
    {
        return ['key' => $key, 'title' => $title, 'items' => array_values(array_filter($items))];
    }

    /**
     * @param  'date'|null  $kind
     * @return ProfileItem|null
     */
    private function item(string $label, mixed $value, ?string $kind = null): ?array
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $item = ['label' => $label, 'value' => trim((string) $value)];

        return $kind === null ? $item : [...$item, 'kind' => $kind];
    }

    private function titleCase(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? ucfirst(str_replace('_', ' ', $value)) : null;
    }

    private function yesNo(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    private function percent(mixed $value): ?string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.').'%' : null;
    }

    private function masked(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return strlen($digits) >= 4 ? 'XXXX XXXX '.substr($digits, -4) : null;
    }
}
