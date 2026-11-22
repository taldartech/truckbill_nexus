<?php

use App\Http\Controllers\Admin\CompanyLifecycleController;
use App\Http\Controllers\Admin\IdentityLifecycleController;
use App\Http\Controllers\Admin\InternalUserController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\OwnershipTransferController;
use App\Http\Controllers\Branch\BranchDashboardController;
use App\Http\Controllers\Branch\BranchSettingsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\Owner\OwnerAuditController;
use App\Http\Controllers\Owner\OwnerBranchesController;
use App\Http\Controllers\Owner\OwnerDashboardController;
use App\Http\Controllers\Owner\OwnerSettingsController;
use App\Http\Controllers\Owner\OwnerSubscriptionController;
use App\Http\Controllers\Owner\OwnerUsersController;
use App\Http\Controllers\Workspace\EnterBranchController;
use App\Http\Controllers\Workspace\EnterCompanyController;
use App\Http\Controllers\Workspace\SelectBranchController;
use App\Http\Controllers\Workspace\SelectCompanyController;
use App\Http\Controllers\Workspace\WorkspaceLandingController;
use App\Http\Controllers\Workspace\WorkspaceNoAccessController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

Route::get('/invitations/accept/{token}', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.accept');
Route::post('/invitations/accept/{token}', [InvitationAcceptanceController::class, 'store'])
    ->name('invitations.accept.store');

Route::middleware(['auth', 'verified'])->group(function () {
    // Technical shell for Internal Team (Customer workspace is under /workspace).
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // P2-WS-8 Customer workspace — company/branch from route model binding only.
    Route::get('/workspace', WorkspaceLandingController::class)->name('workspace.landing');
    Route::get('/workspace/no-access', WorkspaceNoAccessController::class)->name('workspace.no-access');
    Route::get('/workspace/companies/select', SelectCompanyController::class)->name('workspace.companies.select');
    Route::post('/workspace/companies/{company}/enter', EnterCompanyController::class)->name('workspace.companies.enter');
    Route::get('/workspace/companies/{company}/branches/select', SelectBranchController::class)->name('workspace.branches.select');
    Route::post('/workspace/companies/{company}/branches/{branch}/enter', EnterBranchController::class)
        ->scopeBindings()
        ->name('workspace.branches.enter');

    Route::get('/owner/companies/{company}', OwnerDashboardController::class)->name('owner.dashboard');
    Route::get('/owner/companies/{company}/subscription', OwnerSubscriptionController::class)->name('owner.subscription');
    Route::get('/owner/companies/{company}/branches', OwnerBranchesController::class)->name('owner.branches');

    Route::get('/owner/companies/{company}/users', [OwnerUsersController::class, 'index'])->name('owner.users');
    Route::post('/owner/companies/{company}/users/invite', [OwnerUsersController::class, 'invite'])->name('owner.users.invite');
    Route::post('/owner/companies/{company}/branches/{branch}/users/invite', [OwnerUsersController::class, 'inviteForBranch'])
        ->scopeBindings()
        ->name('owner.users.invite-for-branch');
    Route::post('/owner/companies/{company}/users/invitations/{invitation}/revoke', [OwnerUsersController::class, 'revokeInvitation'])
        ->name('owner.users.invitations.revoke');
    Route::get('/owner/companies/{company}/users/{membership}', [OwnerUsersController::class, 'show'])
        ->scopeBindings()
        ->name('owner.users.show');
    Route::post('/owner/companies/{company}/users/{membership}/deactivate', [OwnerUsersController::class, 'deactivateMembership'])
        ->scopeBindings()
        ->name('owner.users.deactivate');
    // Branch is company-scoped (not membership-scoped); controllers verify both parents.
    Route::post('/owner/companies/{company}/users/{membership}/branches/{branch}/assign', [OwnerUsersController::class, 'assignBranch'])
        ->name('owner.users.assign-branch');
    Route::post('/owner/companies/{company}/users/{membership}/branches/{branch}/roles', [OwnerUsersController::class, 'assignRole'])
        ->name('owner.users.assign-role');
    Route::post('/owner/companies/{company}/users/roles/{roleAssignment}/revoke', [OwnerUsersController::class, 'revokeRole'])
        ->name('owner.users.roles.revoke');

    Route::get('/owner/companies/{company}/settings', [OwnerSettingsController::class, 'show'])->name('owner.settings');
    Route::post('/owner/companies/{company}/settings', [OwnerSettingsController::class, 'update'])->name('owner.settings.update');
    Route::get('/owner/companies/{company}/audit', OwnerAuditController::class)->name('owner.audit');

    Route::get('/branch/companies/{company}/branches/{branch}', BranchDashboardController::class)
        ->scopeBindings()
        ->name('branch.dashboard');
    Route::get('/branch/companies/{company}/branches/{branch}/settings', BranchSettingsController::class)
        ->scopeBindings()
        ->name('branch.settings');

    // Company/branch come from route model binding (server-resolved), never request body
    // — RejectClientTenantIdentifiers forbids client-supplied company_id/branch_id.
    Route::post('/admin/companies/{company}/invitations', [InvitationController::class, 'store'])
        ->name('admin.invitations.store');
    Route::post('/admin/companies/{company}/branches/{branch}/invitations', [InvitationController::class, 'storeForBranch'])
        ->name('admin.invitations.store-for-branch');
    Route::post('/admin/invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])
        ->name('admin.invitations.revoke');
    Route::post('/admin/internal-users', [InternalUserController::class, 'store'])
        ->name('admin.internal-users.store');

    Route::post('/admin/companies/{company}/deactivate', [CompanyLifecycleController::class, 'deactivate'])
        ->name('admin.companies.deactivate');
    Route::post('/admin/companies/{company}/reactivate', [CompanyLifecycleController::class, 'reactivate'])
        ->name('admin.companies.reactivate');
    Route::post('/admin/companies/{company}/ownership-transfers', [OwnershipTransferController::class, 'store'])
        ->name('admin.companies.ownership-transfers.store');
    Route::post('/admin/identities/{user}/deactivate', [IdentityLifecycleController::class, 'deactivate'])
        ->name('admin.identities.deactivate');
    Route::post('/admin/identities/{user}/reactivate', [IdentityLifecycleController::class, 'reactivate'])
        ->name('admin.identities.reactivate');
});

require __DIR__.'/settings.php';
