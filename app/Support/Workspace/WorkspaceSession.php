<?php

namespace App\Support\Workspace;

use Illuminate\Http\Request;

/**
 * Server-managed workspace context keys. Never accept client-supplied tenant IDs
 * as authority — only store values after CompanyAccessService validation.
 *
 * CONTEXT_VERSION supports multi-tab stale-context rejection on mutations.
 */
final class WorkspaceSession
{
    public const COMPANY_KEY = 'workspace.company_id';

    public const BRANCH_KEY = 'workspace.branch_id';

    public const VERSION_KEY = 'workspace.context_version';

    public static function put(Request $request, string $companyId, ?string $branchId = null): void
    {
        $request->session()->put(self::COMPANY_KEY, $companyId);
        if ($branchId === null) {
            $request->session()->forget(self::BRANCH_KEY);
        } else {
            $request->session()->put(self::BRANCH_KEY, $branchId);
        }
        self::bumpVersion($request);
    }

    /**
     * Persist company/branch only when the active context actually changes.
     * Ordinary GET navigation must not bump context_version.
     */
    public static function sync(Request $request, string $companyId, ?string $branchId = null): void
    {
        if (self::companyId($request) === $companyId && self::branchId($request) === $branchId) {
            return;
        }

        self::put($request, $companyId, $branchId);
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

    public static function version(Request $request): int
    {
        return (int) $request->session()->get(self::VERSION_KEY, 0);
    }

    public static function bumpVersion(Request $request): void
    {
        $request->session()->put(self::VERSION_KEY, self::version($request) + 1);
    }

    public static function clear(Request $request): void
    {
        $request->session()->forget([self::COMPANY_KEY, self::BRANCH_KEY]);
        self::bumpVersion($request);
    }

    /**
     * Leave Branch Panel while retaining company for Owner management.
     */
    public static function clearBranch(Request $request): void
    {
        if (! $request->session()->has(self::BRANCH_KEY)) {
            return;
        }

        $request->session()->forget(self::BRANCH_KEY);
        self::bumpVersion($request);
    }

    /**
     * Ensure company is selected. When the company is unchanged, preserve branch.
     * When the company changes, clear branch (caller must re-select).
     */
    public static function ensureCompany(Request $request, string $companyId): void
    {
        if (self::companyId($request) === $companyId) {
            return;
        }

        self::put($request, $companyId, null);
    }

    /**
     * Compare client-submitted context version with the session (multi-tab safety).
     */
    public static function matchesSubmittedVersion(Request $request): bool
    {
        $submitted = $request->header('X-Workspace-Context-Version');
        if ($submitted === null || $submitted === '') {
            $submitted = $request->input('_workspace_context_version');
        }

        if ($submitted === null || $submitted === '') {
            // Allow uninstrumented clients during rollout; instrumented clients
            // that send a version are protected against multi-tab races.
            return true;
        }

        return (int) $submitted === self::version($request);
    }
}
