<?php

use App\Domains\Document\DocumentParentTypes;
use App\Http\Controllers\Admin\CompanyLifecycleController;
use App\Http\Controllers\Admin\Crm\CrmDashboardController;
use App\Http\Controllers\Admin\Crm\LeadController as CrmLeadController;
use App\Http\Controllers\Admin\Crm\SupportTicketController as CrmSupportTicketController;
use App\Http\Controllers\Admin\GstRateController;
use App\Http\Controllers\Admin\IdentityLifecycleController;
use App\Http\Controllers\Admin\InternalUserController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\OwnershipTransferController;
use App\Http\Controllers\Admin\SubscriptionInvoiceController;
use App\Http\Controllers\Admin\TdsRuleController;
use App\Http\Controllers\Api\GeographyController;
use App\Http\Controllers\Api\GeographyQuickCreateController;
use App\Http\Controllers\Api\PublicLeadController;
use App\Http\Controllers\Api\PublicLeadOnboardingController;
use App\Http\Controllers\Branch\Accounting\AccountingCashBankBookController;
use App\Http\Controllers\Branch\Accounting\AccountingFundTransferController;
use App\Http\Controllers\Branch\Accounting\AccountingLedgerController;
use App\Http\Controllers\Branch\Accounting\AccountingLedgerQuickCreateController;
use App\Http\Controllers\Branch\Accounting\AccountingOutstandingController;
use App\Http\Controllers\Branch\Accounting\AccountingReportController;
use App\Http\Controllers\Branch\Accounting\AccountingVoucherController;
use App\Http\Controllers\Branch\BankCashAccountController;
use App\Http\Controllers\Branch\BranchDashboardController;
use App\Http\Controllers\Branch\BranchSettingsController;
use App\Http\Controllers\Branch\BrokerAdjustmentController;
use App\Http\Controllers\Branch\BrokerDailyPaymentsController;
use App\Http\Controllers\Branch\BrokerDeliveryController;
use App\Http\Controllers\Branch\BrokerInvoiceController;
use App\Http\Controllers\Branch\BrokerRegistersController;
use App\Http\Controllers\Branch\CatalogueController;
use App\Http\Controllers\Branch\CustomerInvoiceController;
use App\Http\Controllers\Branch\DocumentAttachmentController;
use App\Http\Controllers\Branch\EnquiryController;
use App\Http\Controllers\Branch\LinkedBiltyGroupController;
use App\Http\Controllers\Branch\LorryHireController;
use App\Http\Controllers\Branch\LorryReceiptController;
use App\Http\Controllers\Branch\MastersHubController;
use App\Http\Controllers\Branch\PartloadChallanController;
use App\Http\Controllers\Branch\PartloadDeliveryReceiptController;
use App\Http\Controllers\Branch\PartloadInTransitController;
use App\Http\Controllers\Branch\PartloadInwardController;
use App\Http\Controllers\Branch\PartloadMainChallanController;
use App\Http\Controllers\Branch\PartloadPendingDeliveryController;
use App\Http\Controllers\Branch\PartloadPendingLoadingController;
use App\Http\Controllers\Branch\PartyController;
use App\Http\Controllers\Branch\StationController;
use App\Http\Controllers\Branch\TransporterBookBalanceController;
use App\Http\Controllers\Branch\TransporterVoucherController;
use App\Http\Controllers\Branch\TripController;
use App\Http\Controllers\Branch\TripInvoiceController;
use App\Http\Controllers\Branch\VehicleController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\Owner\OwnerAuditController;
use App\Http\Controllers\Owner\OwnerBranchesController;
use App\Http\Controllers\Owner\OwnerCompaniesController;
use App\Http\Controllers\Owner\OwnerGlobalAuditController;
use App\Http\Controllers\Owner\OwnerGlobalBranchesController;
use App\Http\Controllers\Owner\OwnerGlobalUsersController;
use App\Http\Controllers\Owner\OwnerHomeController;
use App\Http\Controllers\Owner\OwnerSettingsController;
use App\Http\Controllers\Owner\OwnerSubscriptionController;
use App\Http\Controllers\Owner\OwnerSubscriptionsController;
use App\Http\Controllers\Owner\OwnerUsersController;
use App\Http\Controllers\Webhooks\EnquiryWebhookController;
use App\Http\Controllers\Webhooks\RazorpayWebhookController;
use App\Http\Controllers\Workspace\EnterBranchController;
use App\Http\Controllers\Workspace\EnterCompanyController;
use App\Http\Controllers\Workspace\SelectBranchController;
use App\Http\Controllers\Workspace\SelectCompanyController;
use App\Http\Controllers\Workspace\WorkspaceContextController;
use App\Http\Controllers\Workspace\WorkspaceLandingController;
use App\Http\Controllers\Workspace\WorkspaceNoAccessController;
use App\Http\Middleware\LeadOnboardingCors;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

Route::post('/webhooks/razorpay', RazorpayWebhookController::class)->name('webhooks.razorpay');
Route::post('/webhooks/enquiries', EnquiryWebhookController::class)->name('webhooks.enquiries');

Route::post('/api/v1/public/leads', [PublicLeadController::class, 'store'])
    ->middleware(['website.lead.key', 'throttle:30,1'])
    ->name('api.public.leads.store');

Route::get('/invitations/accept/{token}', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.accept');
Route::post('/invitations/accept/{token}', [InvitationAcceptanceController::class, 'store'])
    ->name('invitations.accept.store');

Route::middleware([LeadOnboardingCors::class])->prefix('api/v1/public/onboarding/{token}')->group(function () {
    Route::match(['GET', 'OPTIONS'], '/', [PublicLeadOnboardingController::class, 'show'])
        ->name('api.public.onboarding.show');
    Route::match(['POST', 'OPTIONS'], '/otp/send', [PublicLeadOnboardingController::class, 'sendOtp'])
        ->middleware('throttle:5,1')
        ->name('api.public.onboarding.otp.send');
    Route::match(['POST', 'OPTIONS'], '/otp/verify', [PublicLeadOnboardingController::class, 'verifyOtp'])
        ->middleware('throttle:10,1')
        ->name('api.public.onboarding.otp.verify');
    Route::match(['POST', 'OPTIONS'], '/policy/accept', [PublicLeadOnboardingController::class, 'acceptPolicies'])
        ->middleware('throttle:10,1')
        ->name('api.public.onboarding.policy');
    Route::match(['POST', 'OPTIONS'], '/password', [PublicLeadOnboardingController::class, 'setPassword'])
        ->middleware('throttle:10,1')
        ->name('api.public.onboarding.password');
    Route::match(['POST', 'OPTIONS'], '/complete', [PublicLeadOnboardingController::class, 'complete'])
        ->middleware('throttle:10,1')
        ->name('api.public.onboarding.complete');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Technical shell for Internal Team (Customer workspace is under /workspace).
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('/api/geography/states', [GeographyController::class, 'states'])
        ->name('geography.states');
    Route::get('/api/geography/cities', [GeographyController::class, 'cities'])
        ->name('geography.cities');
    Route::post('/api/geography/states', [GeographyQuickCreateController::class, 'storeState'])
        ->middleware('throttle:20,1')
        ->name('geography.states.store');
    Route::post('/api/geography/cities', [GeographyQuickCreateController::class, 'storeCity'])
        ->middleware('throttle:30,1')
        ->name('geography.cities.store');

    // P2-WS-8 Customer workspace — company/branch from route model binding only.
    Route::get('/workspace', WorkspaceLandingController::class)->name('workspace.landing');
    Route::get('/workspace/no-access', WorkspaceNoAccessController::class)->name('workspace.no-access');
    Route::get('/workspace/companies/select', SelectCompanyController::class)->name('workspace.companies.select');
    Route::post('/workspace/companies/{company}/enter', EnterCompanyController::class)->name('workspace.companies.enter');
    Route::get('/workspace/companies/{company}/branches/select', SelectBranchController::class)->name('workspace.branches.select');
    Route::post('/workspace/companies/{company}/branches/{branch}/enter', EnterBranchController::class)
        ->scopeBindings()
        ->name('workspace.branches.enter');

    // Explicit session context switches (CSRF + authorization). IDs never authorize CRUD alone.
    Route::post('/workspace/context/companies/{company}', [WorkspaceContextController::class, 'company'])
        ->name('workspace.context.company');
    Route::post('/workspace/context/companies/{company}/branches/{branch}', [WorkspaceContextController::class, 'branch'])
        ->scopeBindings()
        ->name('workspace.context.branch');
    Route::post('/workspace/context/clear', [WorkspaceContextController::class, 'clear'])
        ->name('workspace.context.clear');
    Route::post('/workspace/context/leave-branch', [WorkspaceContextController::class, 'leaveBranch'])
        ->name('workspace.context.leave-branch');

    /*
    |--------------------------------------------------------------------------
    | Management (Owner) — canonical /management URLs (route names remain owner.*)
    |--------------------------------------------------------------------------
    */
    Route::get('/management', OwnerHomeController::class)->name('owner.home');
    Route::get('/management/companies', [OwnerCompaniesController::class, 'index'])->name('owner.companies.index');
    Route::get('/management/companies/create', [OwnerCompaniesController::class, 'create'])->name('owner.companies.create');
    Route::post('/management/companies', [OwnerCompaniesController::class, 'store'])->name('owner.companies.store');
    Route::get('/management/branches', OwnerGlobalBranchesController::class)->name('owner.branches.all');
    // Global Create Branch — explicit Company target via route/query (not WorkspaceSession).
    Route::get('/management/branches/create', [OwnerBranchesController::class, 'createGlobal'])
        ->name('owner.branches.create.global');
    Route::get('/management/companies/{company}/branches/create', [OwnerBranchesController::class, 'createForCompany'])
        ->name('owner.companies.branches.create');
    Route::post('/management/companies/{company}/branches', [OwnerBranchesController::class, 'storeForCompany'])
        ->name('owner.companies.branches.store');
    Route::get('/management/users', OwnerGlobalUsersController::class)->name('owner.users.all');
    Route::get('/management/subscriptions', [OwnerSubscriptionsController::class, 'index'])->name('owner.subscriptions.index');
    Route::get('/management/audit', OwnerGlobalAuditController::class)->name('owner.audit.all');

    // Company-scoped management — company resolved from session (management.company).
    // Prefer explicit /management/companies/{company}/… routes for mutations when available.
    Route::middleware('management.company')->group(function () {
        Route::get('/management/subscription', [OwnerSubscriptionController::class, 'overview'])->name('owner.subscription');
        Route::get('/management/subscription/catalogue', [OwnerSubscriptionController::class, 'catalogue'])->name('owner.subscription.catalogue');
        Route::get('/management/subscription/drafts', [OwnerSubscriptionController::class, 'drafts'])->name('owner.subscription.drafts');
        Route::post('/management/subscription/drafts', [OwnerSubscriptionController::class, 'storeDraft'])->name('owner.subscription.drafts.store');
        Route::get('/management/subscription/drafts/{subscription}/edit', [OwnerSubscriptionController::class, 'editDraft'])
            ->name('owner.subscription.drafts.edit');
        Route::put('/management/subscription/drafts/{subscription}', [OwnerSubscriptionController::class, 'updateDraft'])
            ->name('owner.subscription.drafts.update');
        Route::get('/management/subscription/credits', [OwnerSubscriptionController::class, 'credits'])->name('owner.subscription.credits');
        Route::get('/management/subscription/payments', [OwnerSubscriptionController::class, 'payments'])->name('owner.subscription.payments');
        Route::get('/management/subscription/invoices', [OwnerSubscriptionController::class, 'invoices'])->name('owner.subscription.invoices');
        Route::get('/management/subscription/invoices/{invoice}', [OwnerSubscriptionController::class, 'invoiceShow'])->name('owner.subscription.invoices.show');
        Route::get('/management/subscription/invoices/{invoice}/download', [OwnerSubscriptionController::class, 'invoiceDownload'])->name('owner.subscription.invoices.download');
        Route::post('/management/subscription/invoices/{invoice}/email', [OwnerSubscriptionController::class, 'invoiceEmail'])->name('owner.subscription.invoices.email');
        Route::post('/management/subscription/cancel', [OwnerSubscriptionController::class, 'requestCancellation'])->name('owner.subscription.cancel');
        Route::post('/management/subscription/cancel/withdraw', [OwnerSubscriptionController::class, 'withdrawCancellation'])->name('owner.subscription.cancel.withdraw');
        Route::get('/management/subscription/drafts/{subscription}/checkout', [OwnerSubscriptionController::class, 'checkout'])
            ->name('owner.subscription.checkout');
        Route::post('/management/subscription/drafts/{subscription}/checkout/verify', [OwnerSubscriptionController::class, 'verifyCheckout'])
            ->name('owner.subscription.checkout.verify');
        Route::post('/management/subscription/drafts/{subscription}/checkout/simulate', [OwnerSubscriptionController::class, 'simulateCheckout'])
            ->name('owner.subscription.checkout.simulate');
        Route::post('/management/subscription/renew', [OwnerSubscriptionController::class, 'renew'])
            ->name('owner.subscription.renew');

        Route::get('/management/company/branches', [OwnerBranchesController::class, 'index'])->name('owner.branches');
        Route::get('/management/company/branches/create', [OwnerBranchesController::class, 'create'])->name('owner.branches.create');
        Route::post('/management/company/branches', [OwnerBranchesController::class, 'store'])->name('owner.branches.store');
        Route::get('/management/company/branches/{branch}', [OwnerBranchesController::class, 'show'])
            ->name('owner.branches.show');
        Route::post('/management/company/branches/{branch}', [OwnerBranchesController::class, 'update'])
            ->name('owner.branches.update');
        Route::post('/management/company/branches/{branch}/deactivate', [OwnerBranchesController::class, 'deactivate'])
            ->name('owner.branches.deactivate');

        Route::get('/management/company/users', [OwnerUsersController::class, 'index'])->name('owner.users');
        Route::post('/management/company/users/invite', [OwnerUsersController::class, 'invite'])->name('owner.users.invite');
        Route::post('/management/company/branches/{branch}/users/invite', [OwnerUsersController::class, 'inviteForBranch'])
            ->name('owner.users.invite-for-branch');
        Route::post('/management/company/users/invitations/{invitation}/revoke', [OwnerUsersController::class, 'revokeInvitation'])
            ->name('owner.users.invitations.revoke');
        Route::get('/management/company/users/{membership}', [OwnerUsersController::class, 'show'])
            ->name('owner.users.show');
        Route::post('/management/company/users/{membership}/deactivate', [OwnerUsersController::class, 'deactivateMembership'])
            ->name('owner.users.deactivate');
        Route::post('/management/company/users/{membership}/branches/{branch}/assign', [OwnerUsersController::class, 'assignBranch'])
            ->name('owner.users.assign-branch');
        Route::post('/management/company/users/{membership}/branches/{branch}/roles', [OwnerUsersController::class, 'assignRole'])
            ->name('owner.users.assign-role');
        Route::post('/management/company/users/roles/{roleAssignment}/revoke', [OwnerUsersController::class, 'revokeRole'])
            ->name('owner.users.roles.revoke');

        Route::get('/management/company/settings', [OwnerSettingsController::class, 'show'])->name('owner.settings');
        Route::post('/management/company/settings', [OwnerSettingsController::class, 'update'])->name('owner.settings.update');
        Route::get('/management/company/audit', OwnerAuditController::class)->name('owner.audit');
    });

    /*
    |--------------------------------------------------------------------------
    | Operations Panel — session company/branch; clean URLs (no /branch prefix)
    |--------------------------------------------------------------------------
    */
    Route::middleware('branch.workspace')->group(function () {
        Route::get('/operations', BranchDashboardController::class)->name('branch.dashboard');
        Route::get('/settings/branch', BranchSettingsController::class)->name('branch.settings');

        Route::get('/enquiries', [EnquiryController::class, 'index'])->name('branch.enquiries.index');
        Route::get('/enquiries/create', [EnquiryController::class, 'create'])->name('branch.enquiries.create');
        Route::post('/enquiries', [EnquiryController::class, 'store'])->name('branch.enquiries.store');
        Route::get('/enquiries/{enquiry}', [EnquiryController::class, 'show'])->name('branch.enquiries.show');
        Route::patch('/enquiries/{enquiry}', [EnquiryController::class, 'update'])->name('branch.enquiries.update');
        Route::post('/enquiries/{enquiry}/assign', [EnquiryController::class, 'assign'])->name('branch.enquiries.assign');
        Route::post('/enquiries/{enquiry}/follow-ups', [EnquiryController::class, 'storeFollowUp'])->name('branch.enquiries.follow-ups.store');
        Route::post('/enquiries/{enquiry}/follow-ups/{followUp}/complete', [EnquiryController::class, 'completeFollowUp'])->name('branch.enquiries.follow-ups.complete');
        Route::post('/enquiries/{enquiry}/quotations', [EnquiryController::class, 'storeQuotation'])->name('branch.enquiries.quotations.store');
        Route::patch('/enquiries/{enquiry}/quotations/{quotation}', [EnquiryController::class, 'updateQuotation'])->name('branch.enquiries.quotations.update');
        Route::post('/enquiries/{enquiry}/quotations/{quotation}/select', [EnquiryController::class, 'selectQuotation'])->name('branch.enquiries.quotations.select');
        Route::post('/enquiries/{enquiry}/quotation-broadcasts', [EnquiryController::class, 'storeQuotationBroadcast'])->name('branch.enquiries.quotation-broadcasts.store');
        Route::get('/enquiries/{enquiry}/conversion-preview', [EnquiryController::class, 'conversionPreview'])->name('branch.enquiries.conversion-preview');
        Route::post('/enquiries/{enquiry}/convert', [EnquiryController::class, 'convert'])->name('branch.enquiries.convert');

        Route::get('/lorry-receipts', [LorryReceiptController::class, 'index'])->name('branch.lorry-receipts.index');
        Route::get('/lorry-receipts/create', [LorryReceiptController::class, 'create'])->name('branch.lorry-receipts.create');
        Route::post('/lorry-receipts', [LorryReceiptController::class, 'store'])->name('branch.lorry-receipts.store');
        Route::get('/lorry-receipts/{lorryReceipt}', [LorryReceiptController::class, 'show'])->name('branch.lorry-receipts.show');
        Route::get('/lorry-receipts/{lorryReceipt}/edit', [LorryReceiptController::class, 'edit'])->name('branch.lorry-receipts.edit');
        Route::patch('/lorry-receipts/{lorryReceipt}', [LorryReceiptController::class, 'update'])->name('branch.lorry-receipts.update');
        Route::delete('/lorry-receipts/{lorryReceipt}', [LorryReceiptController::class, 'destroy'])->name('branch.lorry-receipts.destroy');
        Route::post('/lorry-receipts/{lorryReceipt}/change-status', [LorryReceiptController::class, 'changeStatus'])->name('branch.lorry-receipts.change-status');
        Route::post('/lorry-receipts/{lorryReceipt}/cancel', [LorryReceiptController::class, 'cancel'])->name('branch.lorry-receipts.cancel');
        Route::get('/lorry-receipts/{lorryReceipt}/print', [LorryReceiptController::class, 'print'])->name('branch.lorry-receipts.print');
        Route::get('/lorry-receipts/{lorryReceipt}/create-trip', [TripController::class, 'createFromLorryReceipt'])->name('branch.trips.create-from-lr');
        Route::post('/lorry-receipts/{lorryReceipt}/trips', [TripController::class, 'storeFromLorryReceipt'])->name('branch.trips.store-from-lr');
        Route::get('/lorry-receipts/{lorryReceipt}/create-lorry-hire', [LorryHireController::class, 'createFromLorryReceipt'])->name('branch.broker.lorry-hires.create-from-lr');
        Route::post('/lorry-receipts/{lorryReceipt}/lorry-hires', [LorryHireController::class, 'storeFromLorryReceipt'])->name('branch.broker.lorry-hires.store-from-lr');

        Route::get('/broker/registers', [BrokerRegistersController::class, 'index'])->name('branch.broker.registers.index');

        Route::get('/broker/lorry-hires', [LorryHireController::class, 'index'])->name('branch.broker.lorry-hires.index');
        Route::get('/broker/lorry-hires/{lorryHire}', [LorryHireController::class, 'show'])->name('branch.broker.lorry-hires.show');
        Route::post('/broker/lorry-hires/{lorryHire}/cancel', [LorryHireController::class, 'cancel'])->name('branch.broker.lorry-hires.cancel');
        Route::get('/broker/lorry-hires/{lorryHire}/adjustments', [BrokerAdjustmentController::class, 'index'])->name('branch.broker.lorry-hires.adjustments.index');
        Route::post('/broker/lorry-hires/{lorryHire}/adjustments', [BrokerAdjustmentController::class, 'store'])->name('branch.broker.lorry-hires.adjustments.store');

        Route::get('/broker/deliveries', [BrokerDeliveryController::class, 'index'])->name('branch.broker.deliveries.index');
        Route::post('/broker/deliveries/memo', [BrokerDeliveryController::class, 'assignMemo'])->name('branch.broker.deliveries.memo');
        Route::post('/broker/deliveries/{lorryReceipt}/complete', [BrokerDeliveryController::class, 'complete'])->name('branch.broker.deliveries.complete');
        Route::post('/broker/deliveries/{brokerDelivery}/pod', [BrokerDeliveryController::class, 'attachPod'])->name('branch.broker.deliveries.pod');

        Route::get('/broker/vouchers', [TransporterVoucherController::class, 'index'])->name('branch.broker.vouchers.index');
        Route::get('/broker/vouchers/create', [TransporterVoucherController::class, 'create'])->name('branch.broker.vouchers.create');
        Route::get('/broker/vouchers/export-payout', [TransporterVoucherController::class, 'exportPayout'])->name('branch.broker.vouchers.export-payout');
        Route::get('/broker/vouchers/bulk', [TransporterVoucherController::class, 'bulkCreate'])->name('branch.broker.vouchers.bulk.create');
        Route::post('/broker/vouchers', [TransporterVoucherController::class, 'store'])->name('branch.broker.vouchers.store');
        Route::post('/broker/vouchers/bulk/preview', [TransporterVoucherController::class, 'bulkPreview'])->name('branch.broker.vouchers.bulk.preview');
        Route::post('/broker/vouchers/bulk', [TransporterVoucherController::class, 'bulkStore'])->name('branch.broker.vouchers.bulk.store');
        Route::get('/broker/vouchers/{voucher}', [TransporterVoucherController::class, 'show'])->name('branch.broker.vouchers.show');
        Route::post('/broker/vouchers/{voucher}/status', [TransporterVoucherController::class, 'updateStatus'])->name('branch.broker.vouchers.status');

        Route::get('/broker/daily-payments', [BrokerDailyPaymentsController::class, 'index'])->name('branch.broker.daily-payments.index');
        Route::post('/broker/daily-payments/bulk-status', [BrokerDailyPaymentsController::class, 'bulkStatus'])->name('branch.broker.daily-payments.bulk-status');

        Route::get('/broker/transporter-balances', [TransporterBookBalanceController::class, 'index'])->name('branch.broker.transporter-balances.index');

        Route::get('/broker/invoices', [BrokerInvoiceController::class, 'index'])->name('branch.broker.invoices.index');
        Route::get('/broker/invoices/create', [BrokerInvoiceController::class, 'create'])->name('branch.broker.invoices.create');
        Route::post('/broker/invoices/preview', [BrokerInvoiceController::class, 'preview'])->name('branch.broker.invoices.preview');
        Route::post('/broker/invoices', [BrokerInvoiceController::class, 'store'])->name('branch.broker.invoices.store');
        Route::get('/broker/invoices/{brokerInvoice}', [BrokerInvoiceController::class, 'show'])->name('branch.broker.invoices.show');
        Route::get('/broker/invoices/{brokerInvoice}/print', [BrokerInvoiceController::class, 'print'])->name('branch.broker.invoices.print');
        Route::post('/broker/invoices/{brokerInvoice}/cancel', [BrokerInvoiceController::class, 'cancel'])->name('branch.broker.invoices.cancel');
        Route::post('/broker/invoices/{brokerInvoice}/payments', [BrokerInvoiceController::class, 'storePayment'])->name('branch.broker.invoices.payments.store');

        Route::get('/broker/linked-bilties', [LinkedBiltyGroupController::class, 'index'])->name('branch.broker.linked-bilties.index');
        Route::get('/broker/linked-bilties/create', [LinkedBiltyGroupController::class, 'create'])->name('branch.broker.linked-bilties.create');
        Route::post('/broker/linked-bilties', [LinkedBiltyGroupController::class, 'store'])->name('branch.broker.linked-bilties.store');
        Route::get('/broker/linked-bilties/{linkedBiltyGroup}', [LinkedBiltyGroupController::class, 'show'])->name('branch.broker.linked-bilties.show');
        Route::post('/broker/linked-bilties/{linkedBiltyGroup}/pay/preview', [LinkedBiltyGroupController::class, 'payPreview'])->name('branch.broker.linked-bilties.pay.preview');
        Route::post('/broker/linked-bilties/{linkedBiltyGroup}/pay', [LinkedBiltyGroupController::class, 'payStore'])->name('branch.broker.linked-bilties.pay.store');

        Route::get('/partload/challans', [PartloadChallanController::class, 'index'])->name('branch.partload.challans.index');
        Route::get('/partload/challans/create', [PartloadChallanController::class, 'create'])->name('branch.partload.challans.create');
        Route::post('/partload/challans', [PartloadChallanController::class, 'store'])->name('branch.partload.challans.store');
        Route::get('/partload/challans/{partloadChallan}', [PartloadChallanController::class, 'show'])->name('branch.partload.challans.show');
        Route::post('/partload/challans/{partloadChallan}/dispatch', [PartloadChallanController::class, 'dispatch'])->name('branch.partload.challans.dispatch');
        Route::post('/partload/challans/{partloadChallan}/cancel', [PartloadChallanController::class, 'cancel'])->name('branch.partload.challans.cancel');

        Route::get('/partload/pending-loading', [PartloadPendingLoadingController::class, 'index'])->name('branch.partload.pending-loading.index');
        Route::get('/partload/in-transit', [PartloadInTransitController::class, 'index'])->name('branch.partload.in-transit.index');
        Route::get('/partload/pending-delivery', [PartloadPendingDeliveryController::class, 'index'])->name('branch.partload.pending-delivery.index');

        Route::get('/partload/main-challans', [PartloadMainChallanController::class, 'index'])->name('branch.partload.main-challans.index');
        Route::get('/partload/main-challans/create', [PartloadMainChallanController::class, 'create'])->name('branch.partload.main-challans.create');
        Route::post('/partload/main-challans', [PartloadMainChallanController::class, 'store'])->name('branch.partload.main-challans.store');
        Route::get('/partload/main-challans/{partloadMainChallan}', [PartloadMainChallanController::class, 'show'])->name('branch.partload.main-challans.show');
        Route::post('/partload/main-challans/{partloadMainChallan}/dispatch', [PartloadMainChallanController::class, 'dispatch'])->name('branch.partload.main-challans.dispatch');
        Route::post('/partload/main-challans/{partloadMainChallan}/cancel', [PartloadMainChallanController::class, 'cancel'])->name('branch.partload.main-challans.cancel');
        Route::post('/partload/inwards', [PartloadInwardController::class, 'store'])->name('branch.partload.inwards.store');
        Route::get('/partload/inwards/{partloadInward}', [PartloadInwardController::class, 'show'])->name('branch.partload.inwards.show');
        Route::get('/partload/delivery-receipts/create', [PartloadDeliveryReceiptController::class, 'create'])->name('branch.partload.delivery-receipts.create');
        Route::post('/partload/delivery-receipts', [PartloadDeliveryReceiptController::class, 'store'])->name('branch.partload.delivery-receipts.store');

        Route::get('/accounting/vouchers', [AccountingVoucherController::class, 'index'])->name('branch.accounting.vouchers.index');
        Route::get('/accounting/vouchers/create', [AccountingVoucherController::class, 'create'])->name('branch.accounting.vouchers.create');
        Route::post('/accounting/vouchers', [AccountingVoucherController::class, 'store'])->name('branch.accounting.vouchers.store');
        Route::get('/accounting/vouchers/{accountingVoucher}', [AccountingVoucherController::class, 'show'])->name('branch.accounting.vouchers.show');
        Route::get('/accounting/ledgers', [AccountingLedgerController::class, 'index'])->name('branch.accounting.ledgers.index');
        Route::post('/accounting/ledgers/quick', [AccountingLedgerQuickCreateController::class, 'store'])->name('branch.accounting.ledgers.quick-store');
        Route::get('/accounting/ledgers/{glLedger}', [AccountingLedgerController::class, 'show'])->name('branch.accounting.ledgers.show');
        Route::get('/accounting/reports/trial-balance', [AccountingReportController::class, 'trialBalance'])->name('branch.accounting.reports.trial-balance');
        Route::get('/accounting/reports/profit-and-loss', [AccountingReportController::class, 'profitAndLoss'])->name('branch.accounting.reports.profit-and-loss');
        Route::get('/accounting/reports/balance-sheet', [AccountingReportController::class, 'balanceSheet'])->name('branch.accounting.reports.balance-sheet');
        Route::get('/accounting/reports/cash-book', [AccountingCashBankBookController::class, 'cash'])->name('branch.accounting.reports.cash-book');
        Route::get('/accounting/reports/bank-book', [AccountingCashBankBookController::class, 'bank'])->name('branch.accounting.reports.bank-book');
        Route::get('/accounting/reports/outstanding', [AccountingOutstandingController::class, 'index'])->name('branch.accounting.reports.outstanding');
        Route::get('/accounting/reports/tax-summary', [AccountingOutstandingController::class, 'tax'])->name('branch.accounting.reports.tax-summary');
        Route::get('/accounting/fund-transfers/create', [AccountingFundTransferController::class, 'create'])->name('branch.accounting.fund-transfers.create');
        Route::post('/accounting/fund-transfers', [AccountingFundTransferController::class, 'store'])->name('branch.accounting.fund-transfers.store');

        Route::get('/trips', [TripController::class, 'index'])->name('branch.trips.index');
        Route::get('/trips/{trip}', [TripController::class, 'show'])->name('branch.trips.show');
        Route::get('/trips/{trip}/multi-point', [TripController::class, 'multiPoint'])->name('branch.trips.multi-point');
        Route::get('/trips/{trip}/expenses', [TripController::class, 'expenses'])->name('branch.trips.expenses');
        Route::post('/trips/{trip}/dispatch', [TripController::class, 'dispatch'])->name('branch.trips.dispatch');
        Route::post('/trips/{trip}/complete', [TripController::class, 'complete'])->name('branch.trips.complete');
        Route::post('/trips/{trip}/cancel', [TripController::class, 'cancel'])->name('branch.trips.cancel');
        Route::delete('/trips/{trip}', [TripController::class, 'destroy'])->name('branch.trips.destroy');
        Route::post('/trips/{trip}/pickups', [TripController::class, 'storePickup'])->name('branch.trips.pickups.store');
        Route::delete('/trips/{trip}/pickups/{pickup}', [TripController::class, 'destroyPickup'])->name('branch.trips.pickups.destroy');
        Route::post('/trips/{trip}/drops', [TripController::class, 'storeDrop'])->name('branch.trips.drops.store');
        Route::delete('/trips/{trip}/drops/{drop}', [TripController::class, 'destroyDrop'])->name('branch.trips.drops.destroy');
        Route::post('/trips/{trip}/allocations', [TripController::class, 'storeAllocation'])->name('branch.trips.allocations.store');
        Route::delete('/trips/{trip}/allocations/{allocation}', [TripController::class, 'destroyAllocation'])->name('branch.trips.allocations.destroy');
        Route::post('/trips/{trip}/expenses', [TripController::class, 'storeExpense'])->name('branch.trips.expenses.store');
        Route::delete('/trips/{trip}/expenses/{expense}', [TripController::class, 'destroyExpense'])->name('branch.trips.expenses.destroy');

        Route::get('/invoices', [CustomerInvoiceController::class, 'index'])->name('branch.invoices.index');
        Route::get('/invoices/create', [CustomerInvoiceController::class, 'create'])->name('branch.invoices.create');
        Route::post('/invoices/route-create', [CustomerInvoiceController::class, 'routeCreate'])->name('branch.invoices.route-create');

        Route::get('/trip-invoices', [TripInvoiceController::class, 'index'])->name('branch.trip-invoices.index');
        Route::get('/trip-invoices/create', [TripInvoiceController::class, 'create'])->name('branch.trip-invoices.create');
        Route::get('/trip-invoices/consolidate', [TripInvoiceController::class, 'consolidate'])->name('branch.trip-invoices.consolidate');
        Route::post('/trip-invoices/individual', [TripInvoiceController::class, 'storeIndividual'])->name('branch.trip-invoices.store-individual');
        Route::post('/trip-invoices/consolidated', [TripInvoiceController::class, 'storeConsolidated'])->name('branch.trip-invoices.store-consolidated');
        Route::get('/trip-invoices/{tripInvoice}', [TripInvoiceController::class, 'show'])->name('branch.trip-invoices.show');
        Route::get('/trip-invoices/{tripInvoice}/edit-charges', [TripInvoiceController::class, 'editCharges'])->name('branch.trip-invoices.edit-charges');
        Route::patch('/trip-invoices/{tripInvoice}/charges', [TripInvoiceController::class, 'updateCharges'])->name('branch.trip-invoices.update-charges');
        Route::get('/trip-invoices/{tripInvoice}/print', [TripInvoiceController::class, 'print'])->name('branch.trip-invoices.print');
        Route::post('/trip-invoices/{tripInvoice}/cancel', [TripInvoiceController::class, 'cancel'])->name('branch.trip-invoices.cancel');
        Route::delete('/trip-invoices/{tripInvoice}', [TripInvoiceController::class, 'destroy'])->name('branch.trip-invoices.destroy');
        Route::post('/trip-invoices/{tripInvoice}/payments', [TripInvoiceController::class, 'storePayment'])->name('branch.trip-invoices.payments.store');

        Route::get('/payments', [TripInvoiceController::class, 'paymentsIndex'])->name('branch.payments.index');

        Route::get('/masters', MastersHubController::class)->name('branch.masters.index');

        Route::get('/parties', [PartyController::class, 'index'])->name('branch.parties.index');
        Route::get('/parties/options', [PartyController::class, 'options'])->name('branch.parties.options');
        Route::get('/parties/tds-for-payee', [PartyController::class, 'tdsForPayee'])->name('branch.parties.tds-for-payee');
        Route::get('/parties/create', [PartyController::class, 'create'])->name('branch.parties.create');
        Route::post('/parties/tds-preview', [PartyController::class, 'previewTds'])->name('branch.parties.tds-preview');
        Route::post('/parties', [PartyController::class, 'store'])->name('branch.parties.store');
        Route::get('/parties/{party}', [PartyController::class, 'show'])->name('branch.parties.show');
        Route::get('/parties/{party}/edit', [PartyController::class, 'edit'])->name('branch.parties.edit');
        Route::patch('/parties/{party}', [PartyController::class, 'update'])->name('branch.parties.update');
        Route::post('/parties/{party}/roles', [PartyController::class, 'assignRole'])->name('branch.parties.roles.assign');
        Route::post('/parties/{party}/roles/{partyRole}/remove', [PartyController::class, 'removeRole'])->name('branch.parties.roles.remove');
        Route::delete('/parties/{party}', [PartyController::class, 'destroy'])->name('branch.parties.destroy');

        Route::get('/vehicles', [VehicleController::class, 'index'])->name('branch.vehicles.index');
        Route::get('/vehicles/options', [VehicleController::class, 'options'])->name('branch.vehicles.options');
        Route::get('/vehicles/create', [VehicleController::class, 'create'])->name('branch.vehicles.create');
        Route::post('/vehicles', [VehicleController::class, 'store'])->name('branch.vehicles.store');
        Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->name('branch.vehicles.show');
        Route::get('/vehicles/{vehicle}/edit', [VehicleController::class, 'edit'])->name('branch.vehicles.edit');
        Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('branch.vehicles.update');
        Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->name('branch.vehicles.destroy');

        Route::get('/bank-cash', [BankCashAccountController::class, 'index'])->name('branch.bank-cash.index');
        Route::get('/bank-cash/create', [BankCashAccountController::class, 'create'])->name('branch.bank-cash.create');
        Route::post('/bank-cash', [BankCashAccountController::class, 'store'])->name('branch.bank-cash.store');
        Route::get('/bank-cash/{bankCashAccount}', [BankCashAccountController::class, 'show'])->name('branch.bank-cash.show');
        Route::get('/bank-cash/{bankCashAccount}/edit', [BankCashAccountController::class, 'edit'])->name('branch.bank-cash.edit');
        Route::patch('/bank-cash/{bankCashAccount}', [BankCashAccountController::class, 'update'])->name('branch.bank-cash.update');
        Route::delete('/bank-cash/{bankCashAccount}', [BankCashAccountController::class, 'destroy'])->name('branch.bank-cash.destroy');

        Route::get('/stations', [StationController::class, 'index'])->name('branch.stations.index');
        Route::get('/stations/create', [StationController::class, 'create'])->name('branch.stations.create');
        Route::post('/stations', [StationController::class, 'store'])->name('branch.stations.store');
        Route::get('/stations/{station}', [StationController::class, 'show'])->name('branch.stations.show');
        Route::get('/stations/{station}/edit', [StationController::class, 'edit'])->name('branch.stations.edit');
        Route::patch('/stations/{station}', [StationController::class, 'update'])->name('branch.stations.update');
        Route::delete('/stations/{station}', [StationController::class, 'destroy'])->name('branch.stations.destroy');
        Route::post('/stations/{station}/default-transporter', [StationController::class, 'setDefaultTransporter'])
            ->name('branch.stations.default-transporter');

        Route::get('/catalogues', [CatalogueController::class, 'legacyIndex'])->name('branch.catalogues.index');
        Route::get('/goods-types', [CatalogueController::class, 'goodsTypes'])->name('branch.goods-types.index');
        Route::post('/goods-types', [CatalogueController::class, 'storeGoodsTypes'])->name('branch.goods-types.store');
        Route::get('/truck-types', [CatalogueController::class, 'truckTypes'])->name('branch.truck-types.index');
        Route::post('/truck-types', [CatalogueController::class, 'storeTruckTypes'])->name('branch.truck-types.store');
        Route::get('/tyre-layouts', [CatalogueController::class, 'tyreLayouts'])->name('branch.tyre-layouts.index');
        Route::post('/tyre-layouts', [CatalogueController::class, 'storeTyreLayouts'])->name('branch.tyre-layouts.store');
        Route::get('/document-types', [CatalogueController::class, 'documentTypes'])->name('branch.document-types.index');
        Route::post('/document-types', [CatalogueController::class, 'storeDocumentTypes'])->name('branch.document-types.store');
        Route::get('/expense-categories', [CatalogueController::class, 'expenseCategories'])->name('branch.expense-categories.index');
        Route::post('/expense-categories', [CatalogueController::class, 'storeExpenseCategories'])->name('branch.expense-categories.store');
        Route::get('/package-types', [CatalogueController::class, 'packageTypes'])->name('branch.package-types.index');
        Route::post('/package-types', [CatalogueController::class, 'storePackageTypes'])->name('branch.package-types.store');
        Route::patch('/catalogues/{catalogueItem}', [CatalogueController::class, 'update'])->name('branch.catalogues.update');
        Route::delete('/catalogues/{catalogueItem}', [CatalogueController::class, 'destroy'])->name('branch.catalogues.destroy');

        Route::post('/documents/attachments/{attachment}/replace', [DocumentAttachmentController::class, 'replace'])
            ->name('branch.documents.replace');
        Route::get('/documents/attachments/{attachment}/download', [DocumentAttachmentController::class, 'download'])
            ->name('branch.documents.download');
        Route::delete('/documents/attachments/{attachment}', [DocumentAttachmentController::class, 'destroy'])
            ->name('branch.documents.destroy');
        Route::get('/documents/{parentType}/{parentId}', [DocumentAttachmentController::class, 'index'])
            ->where('parentType', DocumentParentTypes::routeConstraint())
            ->name('branch.documents.index');
        Route::post('/documents/{parentType}/{parentId}', [DocumentAttachmentController::class, 'store'])
            ->where('parentType', DocumentParentTypes::routeConstraint())
            ->name('branch.documents.store');
    });

    // Legacy Owner URLs → management (no silent company switch).
    Route::redirect('/owner', '/management', 301);
    Route::get('/owner/{any?}', function (?string $any = null) {
        $target = '/management'.($any ? '/'.$any : '');
        // Map old company-prefixed paths that no longer exist to the companies list.
        if ($any !== null && preg_match('#^companies/[0-9a-f-]{36}(/|$)#i', $any)) {
            return redirect('/management/companies', 302);
        }

        return redirect($target, 301);
    })->where('any', '.*');

    // Legacy Branch UUID URLs — do not silently switch session context.
    Route::get('/branch/companies/{company}/branches/{branch}/{any?}', function () {
        return redirect()
            ->route('workspace.landing')
            ->with('error', 'Open the branch from Management or Workspace to continue. Context is not changed from legacy links.');
    })->where('any', '.*');

    // Legacy /branch/* operational URLs → clean session paths (context unchanged).
    Route::get('/branch/{any?}', function (?string $any = null) {
        $path = trim((string) $any, '/');

        if ($path === '') {
            return redirect('/operations', 301);
        }

        if ($path === 'settings') {
            return redirect('/settings/branch', 301);
        }

        if ($path === 'masters') {
            return redirect('/parties', 301);
        }

        $segments = explode('/', $path);
        $root = $segments[0];
        $allowed = [
            'enquiries',
            'lorry-receipts',
            'parties',
            'vehicles',
            'bank-cash',
            'stations',
            'catalogues',
            'documents',
        ];

        if (in_array($root, $allowed, true)) {
            return redirect('/'.$path, 301);
        }

        return redirect('/operations', 301);
    })->where('any', '.*');

    // Company/branch come from route model binding (server-resolved), never request body
    // — RejectClientTenantIdentifiers forbids client-supplied company_id/branch_id.
    Route::post('/admin/companies/{company}/invitations', [InvitationController::class, 'store'])
        ->name('admin.invitations.store');
    Route::post('/admin/companies/{company}/branches/{branch}/invitations', [InvitationController::class, 'storeForBranch'])
        ->name('admin.invitations.store-for-branch');
    Route::post('/admin/invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])
        ->name('admin.invitations.revoke');

    Route::get('/admin/companies', [CompanyLifecycleController::class, 'index'])
        ->name('admin.companies.index');
    Route::get('/admin/companies/{company}', [CompanyLifecycleController::class, 'show'])
        ->name('admin.companies.show');
    Route::post('/admin/companies/{company}/deactivate', [CompanyLifecycleController::class, 'deactivate'])
        ->name('admin.companies.deactivate');
    Route::post('/admin/companies/{company}/reactivate', [CompanyLifecycleController::class, 'reactivate'])
        ->name('admin.companies.reactivate');
    Route::post('/admin/companies/{company}/ownership-transfers', [OwnershipTransferController::class, 'store'])
        ->name('admin.companies.ownership-transfers.store');

    // Customer Identities register removed (PO 25 Nov 2026). Lifecycle show/deactivate
    // remains for deep links from Users / company administration.
    Route::get('/admin/identities/{user}', [IdentityLifecycleController::class, 'show'])
        ->name('admin.identities.show');
    Route::post('/admin/identities/{user}/deactivate', [IdentityLifecycleController::class, 'deactivate'])
        ->name('admin.identities.deactivate');
    Route::post('/admin/identities/{user}/reactivate', [IdentityLifecycleController::class, 'reactivate'])
        ->name('admin.identities.reactivate');

    Route::get('/admin/internal-users', [InternalUserController::class, 'index'])
        ->name('admin.internal-users.index');
    Route::post('/admin/internal-users', [InternalUserController::class, 'store'])
        ->name('admin.internal-users.store');

    Route::get('/admin/crm', CrmDashboardController::class)
        ->name('admin.crm.dashboard');
    Route::get('/admin/crm/leads', [CrmLeadController::class, 'index'])
        ->name('admin.crm.leads.index');
    Route::get('/admin/crm/leads/create', [CrmLeadController::class, 'create'])
        ->name('admin.crm.leads.create');
    Route::post('/admin/crm/leads', [CrmLeadController::class, 'store'])
        ->name('admin.crm.leads.store');
    Route::get('/admin/crm/leads/{lead}/edit', [CrmLeadController::class, 'edit'])
        ->name('admin.crm.leads.edit');
    Route::put('/admin/crm/leads/{lead}', [CrmLeadController::class, 'update'])
        ->name('admin.crm.leads.update');
    Route::get('/admin/crm/leads/{lead}', [CrmLeadController::class, 'show'])
        ->name('admin.crm.leads.show');
    Route::post('/admin/crm/leads/{lead}/transition', [CrmLeadController::class, 'transition'])
        ->name('admin.crm.leads.transition');
    Route::post('/admin/crm/leads/{lead}/assign', [CrmLeadController::class, 'assign'])
        ->name('admin.crm.leads.assign');
    Route::post('/admin/crm/leads/{lead}/follow-ups', [CrmLeadController::class, 'storeFollowUp'])
        ->name('admin.crm.leads.follow-ups.store');
    Route::post('/admin/crm/leads/{lead}/follow-ups/{followUp}/complete', [CrmLeadController::class, 'completeFollowUp'])
        ->name('admin.crm.leads.follow-ups.complete');
    Route::post('/admin/crm/leads/{lead}/notes', [CrmLeadController::class, 'storeNote'])
        ->name('admin.crm.leads.notes.store');
    Route::post('/admin/crm/leads/{lead}/invite', [CrmLeadController::class, 'invite'])
        ->name('admin.crm.leads.invite');
    Route::post('/admin/crm/leads/{lead}/invite/resend', [CrmLeadController::class, 'resendInvite'])
        ->name('admin.crm.leads.invite.resend');
    Route::post('/admin/crm/leads/{lead}/invite/revoke', [CrmLeadController::class, 'revokeInvite'])
        ->name('admin.crm.leads.invite.revoke');

    Route::get('/admin/crm/tickets', [CrmSupportTicketController::class, 'index'])
        ->name('admin.crm.tickets.index');
    Route::get('/admin/crm/tickets/create', [CrmSupportTicketController::class, 'create'])
        ->name('admin.crm.tickets.create');
    Route::post('/admin/crm/tickets', [CrmSupportTicketController::class, 'store'])
        ->name('admin.crm.tickets.store');
    Route::get('/admin/crm/tickets/{ticket}', [CrmSupportTicketController::class, 'show'])
        ->name('admin.crm.tickets.show');
    Route::post('/admin/crm/tickets/{ticket}/transition', [CrmSupportTicketController::class, 'transition'])
        ->name('admin.crm.tickets.transition');
    Route::post('/admin/crm/tickets/{ticket}/assign', [CrmSupportTicketController::class, 'assign'])
        ->name('admin.crm.tickets.assign');

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

    Route::get('/admin/locations', [LocationController::class, 'index'])
        ->name('admin.locations.index');
    Route::post('/admin/locations/cities', [LocationController::class, 'storeCity'])
        ->name('admin.locations.cities.store');
    Route::post('/admin/locations/cities/{city}/toggle', [LocationController::class, 'toggleCity'])
        ->name('admin.locations.cities.toggle');

    Route::get('/admin/states', [LocationController::class, 'statesIndex'])
        ->name('admin.states.index');
    Route::post('/admin/states', [LocationController::class, 'storeState'])
        ->name('admin.states.store');
    Route::post('/admin/states/{state}/toggle', [LocationController::class, 'toggleState'])
        ->name('admin.states.toggle');

    Route::get('/admin/cities', [LocationController::class, 'citiesIndex'])
        ->name('admin.cities.index');
    Route::post('/admin/cities', [LocationController::class, 'storeCity'])
        ->name('admin.cities.store');
    Route::post('/admin/cities/{city}/toggle', [LocationController::class, 'toggleCity'])
        ->name('admin.cities.toggle');

    Route::get('/admin/tds-rules', [TdsRuleController::class, 'index'])
        ->name('admin.tds-rules');
    Route::post('/admin/tds-rules', [TdsRuleController::class, 'store'])
        ->name('admin.tds-rules.store');
    Route::put('/admin/tds-rules/{rule}', [TdsRuleController::class, 'update'])
        ->name('admin.tds-rules.update');
    Route::post('/admin/tds-rules/evaluate', [TdsRuleController::class, 'evaluate'])
        ->name('admin.tds-rules.evaluate');
});

require __DIR__.'/settings.php';
