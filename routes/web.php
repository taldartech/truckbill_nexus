<?php

use App\Http\Controllers\Admin\CompanyLifecycleController;
use App\Http\Controllers\Admin\IdentityLifecycleController;
use App\Http\Controllers\Admin\InternalUserController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationAcceptanceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

Route::get('/invitations/accept/{token}', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.accept');
Route::post('/invitations/accept/{token}', [InvitationAcceptanceController::class, 'store'])
    ->name('invitations.accept.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

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
    Route::post('/admin/identities/{user}/deactivate', [IdentityLifecycleController::class, 'deactivate'])
        ->name('admin.identities.deactivate');
    Route::post('/admin/identities/{user}/reactivate', [IdentityLifecycleController::class, 'reactivate'])
        ->name('admin.identities.reactivate');
});

require __DIR__.'/settings.php';
