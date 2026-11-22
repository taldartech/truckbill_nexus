<?php

namespace App\Domains\Rbac;

/**
 * Stable permission codes defined for P2-WS-3.
 *
 * Product-module catalogues (CRM dashboards, Broker, OwnFleet, Partload, etc.)
 * remain unresolved until those modules are authorized — do not invent them here.
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
}
