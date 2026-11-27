<?php

namespace App\Domains\Document;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\Company;
use App\Models\PartyRole;
use Illuminate\Support\Str;

/**
 * Authoritative document-type catalog per parent context.
 *
 * Upload validation, the document drawer type selector and section grouping all read
 * from here. Party types depend on the party's active roles.
 */
final class DocumentTypeCatalog
{
    public const POD = 'pod';

    public const SUPPORTING = 'supporting';

    public const SHORTAGE_DAMAGE = 'shortage_damage';

    public const INVOICE_SUPPORT = 'invoice_support';

    public const REGISTRATION_CERTIFICATE = 'registration_certificate';

    public const INSURANCE = 'insurance';

    public const FITNESS_CERTIFICATE = 'fitness_certificate';

    public const PERMIT = 'permit';

    public const PUC = 'puc';

    public const ROAD_TAX = 'road_tax';

    public const OTHER_VEHICLE = 'other_vehicle';

    public const PAN = 'pan';

    public const GST_REGISTRATION = 'gst_registration';

    public const TDS_DECLARATION = 'tds_declaration';

    public const DRIVING_LICENCE = 'driving_licence';

    public const OTHER_BUSINESS = 'other_business';

    private const LABELS = [
        self::POD => 'POD',
        self::SUPPORTING => 'Supporting Document',
        self::SHORTAGE_DAMAGE => 'Shortage / Damage Evidence',
        self::INVOICE_SUPPORT => 'Invoice Support',
        self::REGISTRATION_CERTIFICATE => 'Registration Certificate (RC)',
        self::INSURANCE => 'Insurance',
        self::FITNESS_CERTIFICATE => 'Fitness Certificate',
        self::PERMIT => 'Permit',
        self::PUC => 'PUC Certificate',
        self::ROAD_TAX => 'Road Tax',
        self::OTHER_VEHICLE => 'Other Vehicle Document',
        self::PAN => 'PAN',
        self::GST_REGISTRATION => 'GST Registration',
        self::TDS_DECLARATION => 'TDS Declaration',
        self::DRIVING_LICENCE => 'Driving Licence',
        self::OTHER_BUSINESS => 'Other Business Document',
    ];

    private const EXPIRY_REQUIRED = [
        self::INSURANCE,
        self::FITNESS_CERTIFICATE,
        self::PERMIT,
        self::PUC,
        self::ROAD_TAX,
    ];

    private const VALIDITY_TRACKED = [
        self::REGISTRATION_CERTIFICATE,
        self::INSURANCE,
        self::FITNESS_CERTIFICATE,
        self::PERMIT,
        self::PUC,
        self::ROAD_TAX,
        self::OTHER_VEHICLE,
        self::DRIVING_LICENCE,
    ];

    private const TRANSPORT_SECTIONS = [
        ['key' => 'pod', 'title' => 'POD', 'types' => [self::POD]],
        ['key' => 'supporting', 'title' => 'Supporting Documents', 'types' => null],
    ];

    public static function label(?string $documentType): string
    {
        if ($documentType === null || $documentType === '') {
            return 'Unclassified';
        }

        return self::LABELS[$documentType] ?? Str::headline($documentType);
    }

    public static function requiresExpiry(?string $documentType): bool
    {
        return $documentType !== null && in_array($documentType, self::EXPIRY_REQUIRED, true);
    }

    public static function tracksValidity(?string $documentType): bool
    {
        return $documentType !== null && in_array($documentType, self::VALIDITY_TRACKED, true);
    }

    /**
     * Resolve allowed types and sections for one parent record.
     */
    public function contextFor(Company $company, string $parentType, string $parentId): DocumentContext
    {
        if (DocumentParentTypes::isRetired($parentType)) {
            return new DocumentContext(
                title: 'Historical Documents (read-only)',
                allowedTypes: [],
                sections: [['key' => 'historical', 'title' => 'Historical Documents', 'types' => null]],
                retired: true,
            );
        }

        return match ($parentType) {
            DocumentParentTypes::LORRY_RECEIPT => $this->transportContext([self::POD, self::SUPPORTING, self::SHORTAGE_DAMAGE, self::INVOICE_SUPPORT]),
            DocumentParentTypes::TRIP, DocumentParentTypes::BROKER_DELIVERY => $this->transportContext([self::POD, self::SUPPORTING, self::SHORTAGE_DAMAGE]),
            DocumentParentTypes::VEHICLE => new DocumentContext(
                title: 'Vehicle Documents',
                allowedTypes: [
                    self::REGISTRATION_CERTIFICATE,
                    self::INSURANCE,
                    self::FITNESS_CERTIFICATE,
                    self::PERMIT,
                    self::PUC,
                    self::ROAD_TAX,
                    self::OTHER_VEHICLE,
                ],
                sections: [['key' => 'vehicle', 'title' => 'Vehicle Documents', 'types' => null]],
                retired: false,
            ),
            DocumentParentTypes::PARTY => $this->partyContext($this->activePartyRoles($company, $parentId)),
            DocumentParentTypes::COMPANY_RECORD => new DocumentContext(
                title: 'Company Documents',
                allowedTypes: null,
                sections: [['key' => 'company', 'title' => 'Company Documents', 'types' => null]],
                retired: false,
            ),
            default => new DocumentContext(
                title: 'Documents',
                allowedTypes: [],
                sections: [['key' => 'historical', 'title' => 'Historical Documents', 'types' => null]],
                retired: true,
            ),
        };
    }

    /**
     * @param  list<PartyRoleType>  $roles
     */
    public function partyContext(array $roles): DocumentContext
    {
        $has = function (PartyRoleType ...$candidates) use ($roles): bool {
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $roles, true)) {
                    return true;
                }
            }

            return false;
        };

        $types = [self::PAN];
        if ($has(PartyRoleType::Customer, PartyRoleType::Consignor, PartyRoleType::Consignee, PartyRoleType::Transporter)) {
            $types[] = self::GST_REGISTRATION;
        }
        if ($has(PartyRoleType::Transporter)) {
            $types[] = self::TDS_DECLARATION;
        }
        if ($has(PartyRoleType::Driver)) {
            $types[] = self::DRIVING_LICENCE;
        }
        $types[] = self::OTHER_BUSINESS;

        $isDriver = $has(PartyRoleType::Driver);
        $driverOnly = $isDriver && count($roles) === 1;

        if ($driverOnly) {
            $sections = [['key' => 'driver', 'title' => 'Driver Documents', 'types' => null]];
            $title = 'Driver Documents';
        } elseif ($isDriver) {
            $sections = [
                ['key' => 'compliance', 'title' => 'Compliance Documents', 'types' => null],
                ['key' => 'driver', 'title' => 'Driver Documents', 'types' => [self::DRIVING_LICENCE]],
            ];
            $title = 'Compliance & Driver Documents';
        } else {
            $sections = [['key' => 'compliance', 'title' => 'Compliance Documents', 'types' => null]];
            $title = 'Compliance Documents';
        }

        return new DocumentContext(
            title: $title,
            allowedTypes: $types,
            sections: $sections,
            retired: false,
        );
    }

    /**
     * @param  list<string>  $types
     */
    private function transportContext(array $types): DocumentContext
    {
        return new DocumentContext(
            title: 'POD & Supporting Documents',
            allowedTypes: $types,
            sections: self::TRANSPORT_SECTIONS,
            retired: false,
        );
    }

    /**
     * @return list<PartyRoleType>
     */
    private function activePartyRoles(Company $company, string $partyId): array
    {
        return array_values(PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $partyId)
            ->whereNull('deactivated_at')
            ->pluck('role')
            ->map(fn (mixed $role): string => (string) $role)
            ->unique()
            ->map(fn (string $role): ?PartyRoleType => PartyRoleType::tryFrom($role))
            ->filter()
            ->all());
    }
}
