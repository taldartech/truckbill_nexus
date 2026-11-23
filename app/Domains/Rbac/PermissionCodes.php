<?php

namespace App\Domains\Rbac;

/**
 * Stable permission codes.
 *
 * Platform operations: P2-WS-3.
 * Enquiry / quotation / LR: PO-P4-PERM (register only — no blanket role grants).
 */
final class PermissionCodes
{
    public const PLATFORM_VIEW = 'platform.operations.view';

    public const PLATFORM_CREATE = 'platform.operations.create';

    public const PLATFORM_EDIT = 'platform.operations.edit';

    public const PLATFORM_DELETE = 'platform.operations.delete';

    public const PLATFORM_CANCEL = 'platform.operations.cancel';

    public const PLATFORM_PRINT = 'platform.operations.print';

    public const PLATFORM_EXPORT = 'platform.operations.export';

    public const PLATFORM_MANAGE_SETTINGS = 'platform.operations.manage_settings';

    public const PLATFORM_VIEW_FREIGHT = 'platform.operations.view_freight';

    public const ENQUIRY_VIEW = 'enquiry.view';

    public const ENQUIRY_CREATE = 'enquiry.create';

    public const ENQUIRY_EDIT = 'enquiry.edit';

    public const ENQUIRY_ASSIGN = 'enquiry.assign';

    public const ENQUIRY_CONVERT = 'enquiry.convert';

    public const ENQUIRY_DELETE = 'enquiry.delete';

    public const QUOTATION_VIEW = 'quotation.view';

    public const QUOTATION_CREATE = 'quotation.create';

    public const QUOTATION_EDIT = 'quotation.edit';

    public const QUOTATION_SELECT = 'quotation.select';

    public const QUOTATION_BROADCAST = 'quotation.broadcast';

    public const LR_VIEW = 'lr.view';

    public const LR_CREATE = 'lr.create';

    public const LR_EDIT = 'lr.edit';

    public const LR_CANCEL = 'lr.cancel';

    public const LR_DELETE = 'lr.delete';

    public const LR_NUMBER_ADJUST = 'lr.number.adjust';

    public const TRIP_VIEW = 'trip.view';

    public const TRIP_CREATE = 'trip.create';

    public const TRIP_EDIT = 'trip.edit';

    public const TRIP_DISPATCH = 'trip.dispatch';

    public const TRIP_COMPLETE = 'trip.complete';

    public const TRIP_CANCEL = 'trip.cancel';

    public const TRIP_DELETE = 'trip.delete';

    /**
     * @return list<string>
     */
    public static function customerPlatformCatalogue(): array
    {
        return [
            self::PLATFORM_VIEW,
            self::PLATFORM_CREATE,
            self::PLATFORM_EDIT,
            self::PLATFORM_DELETE,
            self::PLATFORM_CANCEL,
            self::PLATFORM_PRINT,
            self::PLATFORM_EXPORT,
            self::PLATFORM_MANAGE_SETTINGS,
            self::PLATFORM_VIEW_FREIGHT,
        ];
    }

    /**
     * PO-P4-PERM approved Customer/Branch module codes (not auto-assigned to roles).
     *
     * @return list<string>
     */
    public static function customerPhase4Catalogue(): array
    {
        return [
            self::ENQUIRY_VIEW,
            self::ENQUIRY_CREATE,
            self::ENQUIRY_EDIT,
            self::ENQUIRY_ASSIGN,
            self::ENQUIRY_CONVERT,
            self::ENQUIRY_DELETE,
            self::QUOTATION_VIEW,
            self::QUOTATION_CREATE,
            self::QUOTATION_EDIT,
            self::QUOTATION_SELECT,
            self::QUOTATION_BROADCAST,
            self::LR_VIEW,
            self::LR_CREATE,
            self::LR_EDIT,
            self::LR_CANCEL,
            self::LR_DELETE,
            self::LR_NUMBER_ADJUST,
        ];
    }

    /**
     * Phase 5 OwnFleet Trip catalogue (registered; assigned explicitly — no blanket grants).
     *
     * @return list<string>
     */
    public static function customerPhase5Catalogue(): array
    {
        return [
            self::TRIP_VIEW,
            self::TRIP_CREATE,
            self::TRIP_EDIT,
            self::TRIP_DISPATCH,
            self::TRIP_COMPLETE,
            self::TRIP_CANCEL,
            self::TRIP_DELETE,
        ];
    }
}
