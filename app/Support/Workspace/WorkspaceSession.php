<?php

namespace App\Support\Workspace;

use Illuminate\Http\Request;

/**
 * Server-managed workspace context keys. Never accept client-supplied tenant IDs
 * as authority — only store values after CompanyAccessService validation.
 */
final class WorkspaceSession
{
    public const COMPANY_KEY = 'workspace.company_id';

    public const BRANCH_KEY = 'workspace.branch_id';

    public static function put(Request $request, string $companyId, ?string $branchId = null): void
    {
        $request->session()->put(self::COMPANY_KEY, $companyId);
        if ($branchId === null) {
            $request->session()->forget(self::BRANCH_KEY);
        } else {
            $request->session()->put(self::BRANCH_KEY, $branchId);
        }
    }

    public static function companyId(Request $request): ?string
    {
        $value = $request->session()->get(self::COMPANY_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function branchId(Request $request): ?string
    {
        $value = $request->session()->get(self::BRANCH_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function clear(Request $request): void
    {
        $request->session()->forget([self::COMPANY_KEY, self::BRANCH_KEY]);
    }
}
