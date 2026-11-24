<?php

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\CustomerInvoice\UnifiedCustomerInvoiceReadFacade;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\LorryReceipt;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

function invoiceFacadeCustomerRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

function invoiceFacadeWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Invoice Facade Co');
    $branch = $company->branches()->firstOrFail();

    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = $workspace->addMembership($company, $member, 'Join');
    $assignment = $workspace->assignBranch($membership, $branch, 'Assign');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $assignment,
        invoiceFacadeCustomerRole('customer.admin'),
        'Grant admin',
    );

    return compact('owner', 'company', 'branch', 'member');
}

function invoiceFacadeLogin(User $user, string $password): void
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }

    test()->flushSession();
    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

beforeEach(function () {
    (new RbacSeeder)->run();
    tripGrant('customer.admin', PermissionCodes::INVOICE_VIEW);
    tripGrant('customer.admin', PermissionCodes::BROKER_INVOICE_VIEW);
});

test('resolveSingleSource rejects mixed own fleet and broker receipts', function () {
    $facade = app(UnifiedCustomerInvoiceReadFacade::class);
    ['company' => $company, 'branch' => $branch, 'owner' => $owner] = invoiceFacadeWorkspace();

    $ownFleet = LorryReceipt::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'workflow_context' => LrWorkflowContext::OwnFleet->value,
        'created_by_user_id' => $owner->id,
    ]);

    $broker = LorryReceipt::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'workflow_context' => LrWorkflowContext::Broker->value,
        'created_by_user_id' => $owner->id,
    ]);

    $facade->resolveSingleSource($company, $branch, [$ownFleet->id, $broker->id]);
})->throws(ValidationException::class);

test('unified invoice index is reachable for customer admin member', function () {
    ['member' => $member, 'company' => $company, 'branch' => $branch] = invoiceFacadeWorkspace();

    invoiceFacadeLogin($member, 'member-secret');
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->get(route('branch.invoices.index'))->assertOk();
});

test('unified invoice index applies source filter in inertia props', function () {
    ['member' => $member, 'company' => $company, 'branch' => $branch] = invoiceFacadeWorkspace();

    invoiceFacadeLogin($member, 'member-secret');
    enterBranchWorkspace($company, $branch)->assertRedirect();

    $this->get(route('branch.invoices.index', ['source' => 'own_fleet']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/invoices/index')
            ->where('filters.source', 'own_fleet'));
});
