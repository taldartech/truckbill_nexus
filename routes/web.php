<?php

use App\Http\Controllers\Admin\CompanyLifecycleController;
use App\Http\Controllers\Admin\GstRateController;
use App\Http\Controllers\Admin\IdentityLifecycleController;
use App\Http\Controllers\Admin\InternalUserController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\OwnershipTransferController;
use App\Http\Controllers\Admin\SubscriptionInvoiceController;
use App\Http\Controllers\Admin\TdsRuleController;
use App\Http\Controllers\Branch\BankCashAccountController;
use App\Http\Controllers\Branch\BranchDashboardController;
use App\Http\Controllers\Branch\BranchSettingsController;
use App\Http\Controllers\Branch\CatalogueController;
use App\Http\Controllers\Branch\DocumentAttachmentController;
use App\Http\Controllers\Branch\EnquiryController;
use App\Http\Controllers\Branch\MastersHubController;
use App\Http\Controllers\Branch\PartyController;
use App\Http\Controllers\Branch\StationController;
use App\Http\Controllers\Branch\VehicleController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\Owner\OwnerAuditController;
use App\Http\Controllers\Owner\OwnerBranchesController;
use App\Http\Controllers\Owner\OwnerCompaniesController;
use App\Http\Controllers\Owner\OwnerDashboardController;
use App\Http\Controllers\Owner\OwnerSettingsController;
use App\Http\Controllers\Owner\OwnerSubscriptionController;
use App\Http\Controllers\Owner\OwnerUsersController;
use App\Http\Controllers\Webhooks\EnquiryWebhookController;
use App\Http\Controllers\Webhooks\RazorpayWebhookController;
use App\Http\Controllers\Workspace\EnterBranchController;
use App\Http\Controllers\Workspace\EnterCompanyController;
use App\Http\Controllers\Workspace\SelectBranchController;
use App\Http\Controllers\Workspace\SelectCompanyController;
use App\Http\Controllers\Workspace\WorkspaceLandingController;
use App\Http\Controllers\Workspace\WorkspaceNoAccessController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

Route::post('/webhooks/razorpay', RazorpayWebhookController::class)->name('webhooks.razorpay');
Route::post('/webhooks/enquiries', EnquiryWebhookController::class)->name('webhooks.enquiries');

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

    Route::get('/owner/companies/create', [OwnerCompaniesController::class, 'create'])->name('owner.companies.create');
    Route::post('/owner/companies', [OwnerCompaniesController::class, 'store'])->name('owner.companies.store');
    Route::get('/owner/companies/{company}', OwnerDashboardController::class)->name('owner.dashboard');
    Route::get('/owner/companies/{company}/subscription', [OwnerSubscriptionController::class, 'overview'])->name('owner.subscription');
    Route::get('/owner/companies/{company}/subscription/catalogue', [OwnerSubscriptionController::class, 'catalogue'])->name('owner.subscription.catalogue');
    Route::get('/owner/companies/{company}/subscription/drafts', [OwnerSubscriptionController::class, 'drafts'])->name('owner.subscription.drafts');
    Route::post('/owner/companies/{company}/subscription/drafts', [OwnerSubscriptionController::class, 'storeDraft'])->name('owner.subscription.drafts.store');
    Route::get('/owner/companies/{company}/subscription/drafts/{subscription}/edit', [OwnerSubscriptionController::class, 'editDraft'])
        ->name('owner.subscription.drafts.edit');
    Route::put('/owner/companies/{company}/subscription/drafts/{subscription}', [OwnerSubscriptionController::class, 'updateDraft'])
        ->name('owner.subscription.drafts.update');
    Route::get('/owner/companies/{company}/subscription/credits', [OwnerSubscriptionController::class, 'credits'])->name('owner.subscription.credits');
    Route::get('/owner/companies/{company}/subscription/payments', [OwnerSubscriptionController::class, 'payments'])->name('owner.subscription.payments');
    Route::get('/owner/companies/{company}/subscription/invoices', [OwnerSubscriptionController::class, 'invoices'])->name('owner.subscription.invoices');
    Route::get('/owner/companies/{company}/subscription/invoices/{invoice}', [OwnerSubscriptionController::class, 'invoiceShow'])->name('owner.subscription.invoices.show');
    Route::get('/owner/companies/{company}/subscription/invoices/{invoice}/download', [OwnerSubscriptionController::class, 'invoiceDownload'])->name('owner.subscription.invoices.download');
    Route::post('/owner/companies/{company}/subscription/invoices/{invoice}/email', [OwnerSubscriptionController::class, 'invoiceEmail'])->name('owner.subscription.invoices.email');
    Route::post('/owner/companies/{company}/subscription/cancel', [OwnerSubscriptionController::class, 'requestCancellation'])->name('owner.subscription.cancel');
    Route::post('/owner/companies/{company}/subscription/cancel/withdraw', [OwnerSubscriptionController::class, 'withdrawCancellation'])->name('owner.subscription.cancel.withdraw');
    Route::get('/owner/companies/{company}/subscription/drafts/{subscription}/checkout', [OwnerSubscriptionController::class, 'checkout'])
        ->name('owner.subscription.checkout');
    Route::post('/owner/companies/{company}/subscription/drafts/{subscription}/checkout/verify', [OwnerSubscriptionController::class, 'verifyCheckout'])
        ->name('owner.subscription.checkout.verify');
    Route::post('/owner/companies/{company}/subscription/drafts/{subscription}/checkout/simulate', [OwnerSubscriptionController::class, 'simulateCheckout'])
        ->name('owner.subscription.checkout.simulate');
    Route::post('/owner/companies/{company}/subscription/renew', [OwnerSubscriptionController::class, 'renew'])
        ->name('owner.subscription.renew');
    Route::get('/owner/companies/{company}/branches', [OwnerBranchesController::class, 'index'])->name('owner.branches');
    Route::get('/owner/companies/{company}/branches/create', [OwnerBranchesController::class, 'create'])->name('owner.branches.create');
    Route::post('/owner/companies/{company}/branches', [OwnerBranchesController::class, 'store'])->name('owner.branches.store');
    Route::get('/owner/companies/{company}/branches/{branch}', [OwnerBranchesController::class, 'show'])
        ->scopeBindings()
        ->name('owner.branches.show');
    Route::post('/owner/companies/{company}/branches/{branch}', [OwnerBranchesController::class, 'update'])
        ->scopeBindings()
        ->name('owner.branches.update');
    Route::post('/owner/companies/{company}/branches/{branch}/deactivate', [OwnerBranchesController::class, 'deactivate'])
        ->scopeBindings()
        ->name('owner.branches.deactivate');

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

    Route::get('/branch/companies/{company}/branches/{branch}/enquiries', [EnquiryController::class, 'index'])
        ->scopeBindings()
        ->name('branch.enquiries.index');
    Route::post('/branch/companies/{company}/branches/{branch}/enquiries', [EnquiryController::class, 'store'])
        ->scopeBindings()
        ->name('branch.enquiries.store');
    // Enquiry is company-scoped; controller enforces branch/unassigned visibility (no Branch::enquiries relation).
    Route::get('/branch/companies/{company}/branches/{branch}/enquiries/{enquiry}', [EnquiryController::class, 'show'])
        ->name('branch.enquiries.show');
    Route::patch('/branch/companies/{company}/branches/{branch}/enquiries/{enquiry}', [EnquiryController::class, 'update'])
        ->name('branch.enquiries.update');

    Route::get('/branch/companies/{company}/branches/{branch}/masters', MastersHubController::class)
        ->scopeBindings()
        ->name('branch.masters.index');

    Route::get('/branch/companies/{company}/branches/{branch}/parties', [PartyController::class, 'index'])
        ->name('branch.parties.index');
    Route::get('/branch/companies/{company}/branches/{branch}/parties/create', [PartyController::class, 'create'])
        ->name('branch.parties.create');
    Route::post('/branch/companies/{company}/branches/{branch}/parties', [PartyController::class, 'store'])
        ->name('branch.parties.store');
    Route::get('/branch/companies/{company}/branches/{branch}/parties/{party}', [PartyController::class, 'show'])
        ->name('branch.parties.show');
    Route::get('/branch/companies/{company}/branches/{branch}/parties/{party}/edit', [PartyController::class, 'edit'])
        ->name('branch.parties.edit');
    Route::patch('/branch/companies/{company}/branches/{branch}/parties/{party}', [PartyController::class, 'update'])
        ->name('branch.parties.update');
    Route::post('/branch/companies/{company}/branches/{branch}/parties/{party}/roles', [PartyController::class, 'assignRole'])
        ->name('branch.parties.roles.assign');
    Route::post('/branch/companies/{company}/branches/{branch}/parties/{party}/roles/{partyRole}/remove', [PartyController::class, 'removeRole'])
        ->name('branch.parties.roles.remove');
    Route::delete('/branch/companies/{company}/branches/{branch}/parties/{party}', [PartyController::class, 'destroy'])
        ->name('branch.parties.destroy');

    Route::get('/branch/companies/{company}/branches/{branch}/vehicles', [VehicleController::class, 'index'])
        ->name('branch.vehicles.index');
    Route::get('/branch/companies/{company}/branches/{branch}/vehicles/create', [VehicleController::class, 'create'])
        ->name('branch.vehicles.create');
    Route::post('/branch/companies/{company}/branches/{branch}/vehicles', [VehicleController::class, 'store'])
        ->name('branch.vehicles.store');
    Route::get('/branch/companies/{company}/branches/{branch}/vehicles/{vehicle}', [VehicleController::class, 'show'])
        ->name('branch.vehicles.show');
    Route::get('/branch/companies/{company}/branches/{branch}/vehicles/{vehicle}/edit', [VehicleController::class, 'edit'])
        ->name('branch.vehicles.edit');
    Route::patch('/branch/companies/{company}/branches/{branch}/vehicles/{vehicle}', [VehicleController::class, 'update'])
        ->name('branch.vehicles.update');
    Route::delete('/branch/companies/{company}/branches/{branch}/vehicles/{vehicle}', [VehicleController::class, 'destroy'])
        ->name('branch.vehicles.destroy');

    Route::get('/branch/companies/{company}/branches/{branch}/bank-cash', [BankCashAccountController::class, 'index'])
        ->name('branch.bank-cash.index');
    Route::get('/branch/companies/{company}/branches/{branch}/bank-cash/create', [BankCashAccountController::class, 'create'])
        ->name('branch.bank-cash.create');
    Route::post('/branch/companies/{company}/branches/{branch}/bank-cash', [BankCashAccountController::class, 'store'])
        ->name('branch.bank-cash.store');
    Route::get('/branch/companies/{company}/branches/{branch}/bank-cash/{bankCashAccount}', [BankCashAccountController::class, 'show'])
        ->name('branch.bank-cash.show');
    Route::get('/branch/companies/{company}/branches/{branch}/bank-cash/{bankCashAccount}/edit', [BankCashAccountController::class, 'edit'])
        ->name('branch.bank-cash.edit');
    Route::patch('/branch/companies/{company}/branches/{branch}/bank-cash/{bankCashAccount}', [BankCashAccountController::class, 'update'])
        ->name('branch.bank-cash.update');
    Route::delete('/branch/companies/{company}/branches/{branch}/bank-cash/{bankCashAccount}', [BankCashAccountController::class, 'destroy'])
        ->name('branch.bank-cash.destroy');

    Route::get('/branch/companies/{company}/branches/{branch}/stations', [StationController::class, 'index'])
        ->name('branch.stations.index');
    Route::get('/branch/companies/{company}/branches/{branch}/stations/create', [StationController::class, 'create'])
        ->name('branch.stations.create');
    Route::post('/branch/companies/{company}/branches/{branch}/stations', [StationController::class, 'store'])
        ->name('branch.stations.store');
    Route::get('/branch/companies/{company}/branches/{branch}/stations/{station}', [StationController::class, 'show'])
        ->name('branch.stations.show');
    Route::get('/branch/companies/{company}/branches/{branch}/stations/{station}/edit', [StationController::class, 'edit'])
        ->name('branch.stations.edit');
    Route::patch('/branch/companies/{company}/branches/{branch}/stations/{station}', [StationController::class, 'update'])
        ->name('branch.stations.update');
    Route::delete('/branch/companies/{company}/branches/{branch}/stations/{station}', [StationController::class, 'destroy'])
        ->name('branch.stations.destroy');
    Route::post('/branch/companies/{company}/branches/{branch}/stations/{station}/default-transporter', [StationController::class, 'setDefaultTransporter'])
        ->name('branch.stations.default-transporter');

    Route::get('/branch/companies/{company}/branches/{branch}/catalogues', [CatalogueController::class, 'index'])
        ->name('branch.catalogues.index');
    Route::post('/branch/companies/{company}/branches/{branch}/catalogues', [CatalogueController::class, 'store'])
        ->name('branch.catalogues.store');
    Route::patch('/branch/companies/{company}/branches/{branch}/catalogues/{catalogueItem}', [CatalogueController::class, 'update'])
        ->name('branch.catalogues.update');
    Route::delete('/branch/companies/{company}/branches/{branch}/catalogues/{catalogueItem}', [CatalogueController::class, 'destroy'])
        ->name('branch.catalogues.destroy');

    // Attachment is company-scoped in the controller; not a nested Branch relation.
    Route::post('/branch/companies/{company}/branches/{branch}/documents/attachments/{attachment}/replace', [DocumentAttachmentController::class, 'replace'])
        ->name('branch.documents.replace');
    Route::get('/branch/companies/{company}/branches/{branch}/documents/attachments/{attachment}/download', [DocumentAttachmentController::class, 'download'])
        ->name('branch.documents.download');
    Route::delete('/branch/companies/{company}/branches/{branch}/documents/attachments/{attachment}', [DocumentAttachmentController::class, 'destroy'])
        ->name('branch.documents.destroy');
    Route::get('/branch/companies/{company}/branches/{branch}/documents/{parentType}/{parentId}', [DocumentAttachmentController::class, 'index'])
        ->where('parentType', 'party|vehicle|bank_cash_account|station|company_record')
        ->name('branch.documents.index');
    Route::post('/branch/companies/{company}/branches/{branch}/documents/{parentType}/{parentId}', [DocumentAttachmentController::class, 'store'])
        ->where('parentType', 'party|vehicle|bank_cash_account|station|company_record')
        ->name('branch.documents.store');

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

    Route::get('/admin/subscription-invoices', [SubscriptionInvoiceController::class, 'index'])
        ->name('admin.subscription-invoices');
    Route::post('/admin/subscription-invoices/{invoice}/approve', [SubscriptionInvoiceController::class, 'approve'])
        ->name('admin.subscription-invoices.approve');

    Route::get('/admin/gst-rates', [GstRateController::class, 'index'])
        ->name('admin.gst-rates');
    Route::post('/admin/gst-rates', [GstRateController::class, 'store'])
        ->name('admin.gst-rates.store');
    Route::post('/admin/gst-rates/{rate}/deactivate', [GstRateController::class, 'deactivate'])
        ->name('admin.gst-rates.deactivate');

    Route::get('/admin/tds-rules', [TdsRuleController::class, 'index'])
        ->name('admin.tds-rules');
    Route::post('/admin/tds-rules', [TdsRuleController::class, 'store'])
        ->name('admin.tds-rules.store');
    Route::put('/admin/tds-rules/{rule}', [TdsRuleController::class, 'update'])
        ->name('admin.tds-rules.update');
});

require __DIR__.'/settings.php';
