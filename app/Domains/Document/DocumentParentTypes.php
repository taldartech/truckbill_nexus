<?php

namespace App\Domains\Document;

/**
 * Authoritative supported document attachment parent types.
 *
 * Route constraints, controllers, services and UI must use this list —
 * not divergent hard-coded allow-lists.
 */
final class DocumentParentTypes
{
    public const PARTY = 'party';

    public const VEHICLE = 'vehicle';

    public const BANK_CASH_ACCOUNT = 'bank_cash_account';

    public const STATION = 'station';

    public const LORRY_RECEIPT = 'lorry_receipt';

    public const TRIP = 'trip';

    public const BROKER_DELIVERY = 'broker_delivery';

    public const COMPANY_RECORD = 'company_record';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PARTY,
            self::VEHICLE,
            self::BANK_CASH_ACCOUNT,
            self::STATION,
            self::LORRY_RECEIPT,
            self::TRIP,
            self::BROKER_DELIVERY,
            self::COMPANY_RECORD,
        ];
    }

    /**
     * Laravel route `where` pattern for `{parentType}`.
     */
    public static function routeConstraint(): string
    {
        return implode('|', self::all());
    }

    public static function isSupported(string $parentType): bool
    {
        return in_array($parentType, self::all(), true);
    }

    public static function isMasterParent(string $parentType): bool
    {
        return in_array($parentType, [
            self::PARTY,
            self::VEHICLE,
            self::BANK_CASH_ACCOUNT,
            self::STATION,
            self::COMPANY_RECORD,
        ], true);
    }

    /**
     * Parents without a verified document requirement. Historical attachments stay
     * listable/downloadable read-only; new uploads, replacements and deletions are rejected.
     *
     * @return list<string>
     */
    public static function retired(): array
    {
        return [
            self::STATION,
            self::BANK_CASH_ACCOUNT,
        ];
    }

    public static function isRetired(string $parentType): bool
    {
        return in_array($parentType, self::retired(), true);
    }

    /**
     * Parents eligible for the shared DocumentDrawer (excludes company_record hub and retired parents).
     *
     * @return list<string>
     */
    public static function drawerParents(): array
    {
        return [
            self::PARTY,
            self::VEHICLE,
            self::LORRY_RECEIPT,
            self::TRIP,
            self::BROKER_DELIVERY,
        ];
    }
}
