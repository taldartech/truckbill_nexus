<?php

use App\Models\Branch;
use App\Models\Company;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Establish Owner management company context (session) then continue.
 */
function enterManagementCompany(Company $company, ?string $redirect = null): TestResponse
{
    return test()->post(route('workspace.context.company', ['company' => $company]), [
        'redirect' => $redirect ?? route('owner.companies.index'),
    ]);
}

/**
 * Establish Branch Panel session context then continue.
 */
function enterBranchWorkspace(Company $company, Branch $branch, ?string $redirect = null): TestResponse
{
    return test()->post(route('workspace.context.branch', [
        'company' => $company,
        'branch' => $branch,
    ]), [
        'redirect' => $redirect ?? route('branch.dashboard'),
    ]);
}

/**
 * Seed Branch Panel session keys without an HTTP round-trip (for actingAs JSON tests).
 */
function seedBranchSession(Company $company, Branch $branch): void
{
    test()->withSession([
        WorkspaceSession::COMPANY_KEY => $company->id,
        WorkspaceSession::BRANCH_KEY => $branch->id,
        WorkspaceSession::VERSION_KEY => 1,
    ]);
}
