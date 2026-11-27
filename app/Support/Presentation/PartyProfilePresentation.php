<?php

declare(strict_types=1);

namespace App\Support\Presentation;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Tax\Enums\PanStatus;
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

    private const PAYABLE_ROLES = ['transporter', 'driver', 'general_vendor', 'pump_vendor'];

    /**
     * @param  bool  $mayViewFinancials  Financial visibility gate for the applicable TDS rate and bank details.
     * @return list<ProfileSection>
     */
    public function sections(Party $party, bool $mayViewFinancials): array
    {
        $roles = $party->roles->filter(fn (PartyRole $role): bool => $role->deactivated_at === null);
        $transporter = $this->roleAttributes($roles->all(), PartyRoleType::Transporter);
        $driver = $this->roleAttributes($roles->all(), PartyRoleType::Driver);
        $roleValues = $roles->map(fn (PartyRole $role): string => (string) $role->role)->all();

        $sections = [
            $this->section('general', 'General Information', [
                $this->item('Party Name', $party->name),
                $this->item('Party Short Name', $party->short_name),
                $this->item('Contact Person', $party->contact_person),
                $this->item('Contact Number', $party->contact_number),
                $this->item('Email', $party->email),
                $this->item('Address', $party->address),
                $this->item('City', $party->city),
                $this->item('State', $party->state),
            ]),
        ];

        if (array_intersect($roleValues, self::BUSINESS_ROLES) !== []) {
            $sections[] = $this->section('tax', 'Tax and Business Information', [
                $this->item('PAN', $party->pan),
                ...($transporter === null ? [] : [
                    $this->item('PAN Status', PanStatus::tryFrom((string) ($transporter['pan_status'] ?? ''))?->label()),
                ]),
                $this->item('GST Applicable', $party->is_gst_applicable ? 'Yes' : 'No'),
                $this->item('GSTIN', $party->gstin),
                ...($transporter === null ? [] : [
                    $this->item('TDS Declaration Received', $this->yesNo($transporter['declaration'] ?? null)),
                    $this->item('Goods Vehicle Count', $transporter['goods_vehicles_count'] ?? null),
                    $mayViewFinancials
                        ? $this->item('Applicable TDS Rate', $this->percent($transporter['applicable_tds_rate'] ?? null))
                        : null,
                ]),
            ]);
        }

        if ($driver !== null) {
            $sections[] = $this->section('driver', 'Driver Information', [
                $this->item('Driver Type', $this->titleCase($driver['driver_type'] ?? null)),
                $this->item('Licence Number', $driver['license_number'] ?? null),
                $this->item('Licence Expiry', $driver['license_expiry'] ?? null, 'date'),
                $this->item('Joining Date', $driver['joining_date'] ?? null, 'date'),
                $this->item('Aadhaar', $this->masked($driver['aadhaar'] ?? null)),
                $this->item('Monthly Payment Base', $driver['monthly_payment_base'] ?? null),
                $this->item('Emergency Contact', $driver['emergency_contact_name'] ?? null),
                $this->item('Emergency Contact Mobile', $driver['emergency_contact_mobile'] ?? null),
            ]);
        }

        if ($mayViewFinancials && array_intersect($roleValues, self::PAYABLE_ROLES) !== []) {
            $sections[] = $this->section('bank', 'Bank Details', [
                $this->item('Bank Name', $party->bank_name),
                $this->item('Account Holder Name', $party->bank_account_holder_name),
                $this->item('Account Number', AccountNumberMask::mask($party->bank_account_number)),
                $this->item('IFSC Code', $party->bank_ifsc),
                $this->item('Bank Short Name', $party->bank_short_name),
                $this->item('Beneficiary ID', $party->bank_beneficiary_id),
            ]);
        }

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
