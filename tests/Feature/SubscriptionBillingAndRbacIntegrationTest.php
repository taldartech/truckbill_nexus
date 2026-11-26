<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Identity\Exceptions\LastSuperAdminProtectedException;
use App\Domains\Identity\IdentityLifecycleService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\Gateway\FakePaymentGateway;
use App\Domains\Subscription\Purchase\SubscriptionPurchaseService;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\CustomerWebSession;
use App\Models\InternalRoleAssignment;
use App\Models\PaymentIntent;
use App\Models\Permission;
use App\Models\PlatformBillingProfile;
use App\Models\Role;
use App\Models\State;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();

    config([
        'subscription.payments.razorpay.key_id' => '',
        'subscription.payments.razorpay.key_secret' => '',
        'subscription.payments.razorpay.webhook_secret' => '',
    ]);
});

afterEach(function () {
    TenantContextHolder::clear();
});

function sbLoginAs(User $user, string $password = 'secret123'): void
{
    if (auth()->check()) {
        $logout = test()->post(route('logout'));
        foreach ($logout->headers->getCookies() as $cookie) {
            test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }
    test()->flushSession();
    auth()->guard('web')->logout();
    app('auth')->forgetGuards();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    $login = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $login->assertRedirect();
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

function sbCreateSuperAdmin(): User
{
    return User::factory()->internal()->create([
        'is_super_admin' => true,
        'password' => Hash::make('secret123'),
    ]);
}

function sbSeedPlatformProfile(User $admin, string $stateCode = '27', string $stateName = 'Maharashtra'): PlatformBillingProfile
{
    $state = State::query()->firstOrCreate(
        ['code' => $stateCode === '27' ? 'MH' : 'GJ'],
        [
            'id' => (string) Str::uuid(),
            'name' => $stateName,
            'gst_code' => $stateCode,
            'is_active' => true,
        ],
    );

    return app(PlatformBillingProfileService::class)->upsert($admin, [
        'legal_name' => 'TruckBill SaaS Platform Pvt Ltd',
        'trade_name' => 'TruckBill Cloud',
        'registered_address' => '401 Tech Tower, Pune',
        'state_id' => $state->id,
        'state_name' => $state->name,
        'state_code' => $state->gst_code,
        'city' => 'Pune',
        'pin_code' => '411001',
        'gstin' => $stateCode.'AABCT1234F1Z5',
        'pan' => 'AABCT1234F',
        'contact_number' => '9876543210',
        'email' => 'billing@truckbill.test',
        'gst_rate_percent' => '18.00',
        'sac_code' => '998314',
        'invoice_prefix' => 'TBINV',
        'invoice_next_sequence' => 1,
        'credit_note_prefix' => 'TBCN',
        'credit_note_next_sequence' => 1,
    ], null, 'Initial platform profile setup');
}

// Scenarios 1, 2, 3, 4, 5: Simplified Purchase, Mid-term Prorated Upgrade, Accounting Add-on,
// Platform Billing Profile, Immutable CGST/SGST vs IGST Invoices, Credit Notes & Duplicate Callback Idempotency.
test('simplified subscription purchase, prorated upgrade, accounting add-on, immutable gst invoices and idempotent callbacks', function () {
    $superAdmin = sbCreateSuperAdmin();
    sbSeedPlatformProfile($superAdmin, '27', 'Maharashtra');

    $mhState = State::query()->where('gst_code', '27')->firstOrFail();
    $gjState = State::query()->firstOrCreate(
        ['code' => 'GJ'],
        ['id' => (string) Str::uuid(), 'name' => 'Gujarat', 'gst_code' => '24', 'is_active' => true],
    );

    $owner = User::factory()->customer()->create(['password' => Hash::make('secret123')]);
    $companyA = app(CompanyWorkspaceService::class)->createCompany($owner, 'Alpha Logistics Pvt Ltd');
    $companyA->forceFill([
        'registered_address' => '12 Transport Nagar, Mumbai',
        'state_id' => $mhState->id,
        'state' => 'Maharashtra',
        'city' => 'Mumbai',
        'pin' => '400001',
        'gstin' => '27AAACA1111B1Z2',
        'pan' => 'AAACA1111B',
        'mobile' => '9911223344',
        'email' => 'accounts@alphalogistics.test',
    ])->save();

    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $brokerPlan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('module', 'broker')->orderBy('sort_order')->firstOrFail();
    $ownFleetPlan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('module', 'ownfleet')->orderBy('sort_order')->firstOrFail();
    $accountingAddon = SubscriptionAddon::query()->where('catalogue_version_id', $catalogue->id)->where('code', 'accounting')->firstOrFail();
    $extraBranchAddon = SubscriptionAddon::query()->where('catalogue_version_id', $catalogue->id)->where('code', 'extra_branch')->firstOrFail();

    // Ensure unconfigured (zero-price) Accounting add-on is strictly rejected (#24).
    $originalPrice = (string) $accountingAddon->annual_price;
    $accountingAddon->forceFill(['annual_price' => '0.00'])->save();

    expect(fn () => app(SubscriptionPurchaseService::class)->quote($companyA, [
        'plan_ids' => [$brokerPlan->id],
        'addon_ids' => [$accountingAddon->id],
        'tenure' => SubscriptionTenure::Yearly->value,
    ]))->toThrow(SubscriptionDomainException::class);

    $accountingAddon->forceFill(['annual_price' => $originalPrice])->save();

    // Scenario 1: Single-screen Purchase -> Checkout -> Server Verification (Intra-state CGST + SGST).
    sbLoginAs($owner);
    $this->get(route('owner.companies.subscription.purchase', $companyA))->assertOk();

    // Verify Pricing quote uses authoritative backend calculation & resolves customer billing.
    $quoteResponse = $this->withCredentials()->postJson(route('owner.companies.subscription.quote', $companyA), [
        'plan_ids' => [$brokerPlan->id],
        'addon_ids' => [],
        'tenure' => SubscriptionTenure::Yearly->value,
        'mode' => 'initial',
    ])->assertOk();

    $quote = $quoteResponse->json();
    expect((float) $quote['gst']['cgst_amount'])->toBeGreaterThan(0)
        ->and((float) $quote['gst']['sgst_amount'])->toBeGreaterThan(0)
        ->and((float) $quote['gst']['igst_amount'])->toBe(0.0)
        ->and($quote['customer_snapshot']['legal_name'])->toBe('Alpha Logistics Pvt Ltd');

    $purchasePost = $this->post(route('owner.companies.subscription.store', $companyA), [
        'plan_ids' => [$brokerPlan->id],
        'addon_ids' => [],
        'tenure' => SubscriptionTenure::Yearly->value,
        'mode' => 'initial',
    ]);
    $purchasePost->assertRedirect();

    $intent = PaymentIntent::query()->where('company_id', $companyA->id)->latest('created_at')->firstOrFail();
    expect($intent->status)->toBe('order_created');

    // Before verification, paid subscription & invoice must NOT exist yet (first company only has its 15-day trial; Accounting is not in trial).
    expect(CompanySubscription::query()->where('company_id', $companyA->id)->where('status', 'active')->exists())->toBeFalse()
        ->and(SubscriptionInvoice::query()->where('company_id', $companyA->id)->exists())->toBeFalse()
        ->and(app(CompanyCapabilityResolver::class)->allowsRead($companyA->fresh(), CompanyCapabilities::ACCOUNTING))->toBeFalse();

    // Capture via server-verified simulate route (uses HMAC + FakePaymentGateway).
    $this->post(route('owner.companies.subscription.checkout.simulate', [$companyA, $intent]))
        ->assertRedirect(route('owner.companies.subscription.purchase', $companyA));

    $companyA->refresh();
    $resolver = app(CompanyCapabilityResolver::class);
    expect($resolver->allowsRead($companyA, CompanyCapabilities::BROKER_LR))->toBeTrue()
        ->and($resolver->allowsRead($companyA, CompanyCapabilities::OWNFLEET_TRIPS))->toBeFalse()
        ->and($resolver->allowsRead($companyA, CompanyCapabilities::ACCOUNTING))->toBeFalse();

    $subA = CompanySubscription::query()->where('company_id', $companyA->id)->where('status', 'active')->firstOrFail();
    $initialRenewalDate = $subA->common_renewal_date->toDateString();

    $invoice1 = SubscriptionInvoice::query()->where('payment_intent_id', $intent->id)->firstOrFail();
    expect($invoice1->document_type)->toBe('tax_invoice')
        ->and((bool) $invoice1->is_inter_state)->toBeFalse()
        ->and((float) $invoice1->cgst_amount)->toBeGreaterThan(0)
        ->and((float) $invoice1->sgst_amount)->toBeGreaterThan(0)
        ->and($invoice1->supplier_snapshot['legal_name'])->toBe('TruckBill SaaS Platform Pvt Ltd')
        ->and($invoice1->customer_snapshot['gstin'])->toBe('27AAACA1111B1Z2');

    // Scenario 5: Duplicate payment callback & webhook must NOT generate duplicate invoices or double-activate.
    $this->post(route('owner.companies.subscription.checkout.simulate', [$companyA, $intent]));
    expect(SubscriptionInvoice::query()->where('payment_intent_id', $intent->id)->count())->toBe(1);

    // Tampered webhook signature must reject (400); valid signed duplicate Razorpay webhook must be idempotent (1 invoice).
    config([
        'subscription.payments.razorpay.key_id' => 'rzp_test_ci_key',
        'subscription.payments.razorpay.key_secret' => 'test_secret',
        'subscription.payments.razorpay.webhook_secret' => 'wh_secret',
    ]);
    $rawPayload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => [
                'entity' => [
                    'id' => 'pay_wh_'.Str::random(8),
                    'order_id' => $intent->gateway_order_id,
                    'status' => 'captured',
                    'amount' => (int) round(((float) $intent->amount) * 100),
                    'currency' => 'INR',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $fakeSecret = app(FakePaymentGateway::class)->secret();
    $sig = hash_hmac('sha256', $rawPayload, $fakeSecret);

    $this->call('POST', route('webhooks.razorpay'), [], [], [], [
        'HTTP_X_RAZORPAY_SIGNATURE' => 'tampered-signature',
        'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_wh_bad_1',
        'CONTENT_TYPE' => 'application/json',
    ], $rawPayload)->assertStatus(400);

    $this->call('POST', route('webhooks.razorpay'), [], [], [], [
        'HTTP_X_RAZORPAY_SIGNATURE' => $sig,
        'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_wh_dup_1',
        'CONTENT_TYPE' => 'application/json',
    ], $rawPayload)->assertOk();
    $this->call('POST', route('webhooks.razorpay'), [], [], [], [
        'HTTP_X_RAZORPAY_SIGNATURE' => $sig,
        'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_wh_dup_1',
        'CONTENT_TYPE' => 'application/json',
    ], $rawPayload)->assertOk();
    expect(SubscriptionInvoice::query()->where('payment_intent_id', $intent->id)->count())->toBe(1);

    // Reset to fake gateway for Scenario 2 & 3 mid-term upgrade.
    config([
        'subscription.payments.razorpay.key_id' => '',
        'subscription.payments.razorpay.key_secret' => '',
        'subscription.payments.razorpay.webhook_secret' => '',
    ]);

    // Scenario 2 & 3: Existing Broker Company upgrades adding OwnFleet + Accounting + 1 Extra Branch.
    // Already-subscribed Broker plan must NOT be charged again; common renewal date preserved.
    $upgradeQuote = $this->withCredentials()->postJson(route('owner.companies.subscription.quote', $companyA), [
        'plan_ids' => [$brokerPlan->id, $ownFleetPlan->id],
        'addon_ids' => [$accountingAddon->id, $extraBranchAddon->id],
        'addon_quantities' => [$extraBranchAddon->id => 1],
        'tenure' => SubscriptionTenure::Yearly->value,
        'mode' => 'upgrade',
    ])->assertOk()->json();

    $itemIds = collect($upgradeQuote['line_items'])->pluck('id')->all();
    $itemCodes = collect($upgradeQuote['line_items'])->pluck('code')->all();
    expect($itemIds)->not->toContain($brokerPlan->id)
        ->and($itemIds)->toContain($ownFleetPlan->id)
        ->and($itemCodes)->toContain('accounting')
        ->and($upgradeQuote['common_renewal_date'])->toBe($initialRenewalDate);

    $this->post(route('owner.companies.subscription.store', $companyA), [
        'plan_ids' => [$ownFleetPlan->id],
        'addon_ids' => [$accountingAddon->id, $extraBranchAddon->id],
        'addon_quantities' => [$extraBranchAddon->id => 1],
        'mode' => 'upgrade',
    ])->assertRedirect();

    $upgradeIntent = PaymentIntent::query()
        ->where('company_id', $companyA->id)
        ->where('id', '!=', $intent->id)
        ->latest('created_at')
        ->firstOrFail();

    $this->post(route('owner.companies.subscription.checkout.simulate', [$companyA, $upgradeIntent]))->assertRedirect();

    $companyA->refresh();
    expect($resolver->allowsRead($companyA, CompanyCapabilities::BROKER_LR))->toBeTrue()
        ->and($resolver->allowsRead($companyA, CompanyCapabilities::OWNFLEET_TRIPS))->toBeTrue()
        ->and($resolver->allowsRead($companyA, CompanyCapabilities::ACCOUNTING))->toBeTrue()
        ->and($subA->fresh()->common_renewal_date->toDateString())->toBe($initialRenewalDate);

    // Scenario 4: Inter-state IGST test on Company B in Gujarat & Snapshot Immutability + Credit Note.
    $companyB = app(CompanyWorkspaceService::class)->createCompany($owner, 'Gujarat Freight Movers');
    $companyB->forceFill([
        'registered_address' => '88 Ring Road, Ahmedabad',
        'state_id' => $gjState->id,
        'state' => 'Gujarat',
        'city' => 'Ahmedabad',
        'pin' => '380001',
        'gstin' => '24AAACB2222C1Z9',
        'pan' => 'AAACB2222C',
    ])->save();

    $this->post(route('owner.companies.subscription.store', $companyB), [
        'plan_ids' => [$brokerPlan->id],
        'addon_ids' => [],
        'tenure' => SubscriptionTenure::Yearly->value,
        'mode' => 'initial',
    ])->assertRedirect();

    $intentB = PaymentIntent::query()->where('company_id', $companyB->id)->latest('created_at')->firstOrFail();
    $this->post(route('owner.companies.subscription.checkout.simulate', [$companyB, $intentB]))->assertRedirect();

    $invoiceB = SubscriptionInvoice::query()->where('payment_intent_id', $intentB->id)->firstOrFail();
    expect((bool) $invoiceB->is_inter_state)->toBeTrue()
        ->and((float) $invoiceB->igst_amount)->toBeGreaterThan(0)
        ->and((float) $invoiceB->cgst_amount)->toBe(0.0);

    // Owner Panel download works (switch active management company to Company B)
    $this->post(route('workspace.context.company', $companyB))->assertRedirect();
    $this->get(route('owner.subscription.invoices.download', $invoiceB))
        ->assertOk()
        ->assertSee('TruckBill SaaS Platform Pvt Ltd')
        ->assertSee('Gujarat Freight Movers');

    // Mutate PlatformBillingProfile & Customer Company AFTER invoice issuance — issued invoice snapshot MUST stay immutable.
    sbLoginAs($superAdmin);
    $this->post(route('admin.billing-profile.update'), [
        'legal_name' => 'Renamed Platform Entity LLP',
        'trade_name' => 'Renamed Trade',
        'registered_address' => '999 New Address',
        'state_id' => $mhState->id,
        'state_name' => 'Maharashtra',
        'state_code' => '27',
        'city' => 'Mumbai',
        'pin_code' => '400099',
        'gstin' => '27AABCT1234F1Z5',
        'pan' => 'AABCT1234F',
        'contact_number' => '9999988888',
        'email' => 'new@truckbill.test',
        'gst_rate_percent' => '18.00',
        'sac_code' => '998314',
        'invoice_prefix' => 'TBINV',
        'invoice_next_sequence' => 50,
        'credit_note_prefix' => 'TBCN',
        'credit_note_next_sequence' => 1,
        'remark' => 'Address update',
    ])->assertRedirect();

    $companyB->forceFill(['name' => 'Changed Customer Name'])->save();

    $reloadedB = $invoiceB->fresh();
    expect($reloadedB->supplier_snapshot['legal_name'])->toBe('TruckBill SaaS Platform Pvt Ltd')
        ->and($reloadedB->customer_snapshot['legal_name'])->toBe('Gujarat Freight Movers');

    // Admin/CRM invoice register search, view & credit note issuance
    $this->get(route('admin.subscription-invoices', ['search' => $invoiceB->invoice_number]))->assertOk();
    $this->get(route('admin.subscription-invoices.show', $invoiceB))->assertOk();
    $this->get(route('admin.subscription-invoices.download', $invoiceB))->assertOk();

    $this->post(route('admin.subscription-invoices.credit-note', $invoiceB), [
        'reason' => 'Billing adjustment approved by finance',
        'amount' => '100.00',
    ])->assertRedirect();

    $cn = SubscriptionInvoice::query()
        ->where('original_invoice_id', $invoiceB->id)
        ->where('document_type', 'credit_note')
        ->firstOrFail();
    expect((string) $cn->amount)->toBe('100.00');
});

// Scenarios 6, 7, 8: Internal & Customer RBAC Management, Mandatory Remark, Authority Ceiling,
// Last Super Admin Protection, Immediate Cache Invalidation & No-Freight View Enforcement.
test('internal and customer rbac role management, authority boundaries, cache invalidation and no-freight restrictions', function () {
    $superAdmin = sbCreateSuperAdmin();
    $adminRole = Role::query()->where('domain', PermissionDomain::Internal->value)->where('slug', 'internal.admin')->firstOrFail();
    $settingsManagePerm = Permission::query()->where('code', PermissionCodes::INTERNAL_SETTINGS_MANAGE)->firstOrFail();
    $crmViewPerm = Permission::query()->where('code', PermissionCodes::INTERNAL_CRM_VIEW)->firstOrFail();

    // Create a second internal admin user who holds settings.manage & crm.view, but NOT audit.view or platform_masters.manage.
    $limitedAdmin = User::factory()->internal()->create(['password' => Hash::make('secret123')]);
    InternalRoleAssignment::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $limitedAdmin->id,
        'role_id' => $adminRole->id,
        'deactivated_at' => null,
    ]);

    sbLoginAs($superAdmin);
    $this->get(route('admin.roles.index'))->assertOk();

    // Protect last active Super Admin & system roles: demoting/deactivating last Super Admin throws, toggling system role fails.
    expect(fn () => app(IdentityLifecycleService::class)->revokeSuperAdminPrivilege($superAdmin, 'Self demote'))
        ->toThrow(LastSuperAdminProtectedException::class);

    $this->post(route('admin.roles.toggle', $adminRole), ['remark' => 'Disable system role'])
        ->assertSessionHasErrors('role');

    // Scenario 6: Modify Internal Role permissions with mandatory remark validation.
    $this->put(route('admin.roles.permissions.sync', $adminRole), [
        'permission_ids' => [$settingsManagePerm->id, $crmViewPerm->id],
        'remark' => '',
    ])->assertSessionHasErrors('remark');

    $this->put(route('admin.roles.permissions.sync', $adminRole), [
        'permission_ids' => [$settingsManagePerm->id, $crmViewPerm->id],
        'remark' => 'Scoped admin role to CRM and settings only',
    ])->assertRedirect();

    // Non-Super-Admin must NOT grant permissions exceeding their own authority!
    $auditPerm = Permission::query()->where('code', PermissionCodes::INTERNAL_AUDIT_VIEW)->firstOrFail();
    sbLoginAs($limitedAdmin);
    $this->put(route('admin.roles.permissions.sync', $adminRole), [
        'permission_ids' => [$settingsManagePerm->id, $crmViewPerm->id, $auditPerm->id],
        'remark' => 'Self-escalation attempt',
    ])->assertSessionHasErrors('permissions');

    // Scenario 7: Customer Role management rejects Internal permissions & invalidates cache immediately.
    sbLoginAs($superAdmin);
    $customCustomerRoleResp = $this->post(route('admin.roles.store'), [
        'domain' => PermissionDomain::Customer->value,
        'name' => 'Branch Auditor',
        'description' => 'Read-only auditor',
    ]);
    $customCustomerRoleResp->assertRedirect();
    $customRole = Role::query()->where('slug', 'customer.branch_auditor')->firstOrFail();

    // Reject Internal Team permission on Customer role.
    $this->put(route('admin.roles.permissions.sync', $customRole), [
        'permission_ids' => [$crmViewPerm->id],
        'remark' => 'Cross-domain violation attempt',
    ])->assertSessionHasErrors('permissions');

    $ctx = tripWorkspace([SubscriptionModule::Broker->value, SubscriptionModule::OwnFleet->value], ['accounting']);
    $custUser = User::factory()->customer()->create(['password' => Hash::make('secret123')]);
    $workspace = app(CompanyWorkspaceService::class);
    $membership = $workspace->addMembership($ctx['company'], $custUser, 'Auditor join');
    $assignment = $workspace->assignBranch($membership, $ctx['branch'], 'Auditor branch');
    app(RoleAssignmentService::class)->assignCustomerBranchRole(
        $ctx['owner'],
        $membership,
        $ctx['branch'],
        $assignment,
        $customRole,
        'Assign custom role',
    );

    $lrViewPerm = Permission::query()->where('code', PermissionCodes::LR_VIEW)->firstOrFail();
    $acctViewPerm = Permission::query()->where('code', PermissionCodes::BRANCH_ACCOUNTING_VIEW)->firstOrFail();
    $viewFreightPerm = Permission::query()->where('code', PermissionCodes::PLATFORM_VIEW_FREIGHT)->firstOrFail();

    // Initially custom role has zero permissions -> authorizer returns false.
    $authorizer = app(PermissionAuthorizer::class);
    expect($authorizer->allowsCustomer($custUser, PermissionCodes::LR_VIEW, $ctx['company'], $ctx['branch']))->toBeFalse();

    // Grant LR_VIEW + ACCOUNTING_VIEW (WITHOUT PLATFORM_VIEW_FREIGHT = No-Freight View!)
    sbLoginAs($superAdmin);
    $this->put(route('admin.roles.permissions.sync', $customRole), [
        'permission_ids' => [$lrViewPerm->id, $acctViewPerm->id],
        'remark' => 'Grant operational view without freight view',
    ])->assertRedirect();

    // Cache must invalidate immediately!
    expect($authorizer->allowsCustomer($custUser, PermissionCodes::LR_VIEW, $ctx['company'], $ctx['branch']))->toBeTrue();

    // Scenario 8: No-Freight View restrictions across UI, Accounting & APIs.
    sbLoginAs($custUser);
    $this->post(route('workspace.companies.enter', $ctx['company']))->assertRedirect();
    $this->post(route('workspace.branches.enter', [$ctx['company'], $ctx['branch']]))->assertRedirect();

    // Accounting routes MUST be denied (403) when user lacks PLATFORM_VIEW_FREIGHT even though subscription & ACCOUNTING_VIEW exist.
    $this->get(route('branch.accounting.vouchers.index'))->assertForbidden();
    $this->get(route('branch.accounting.reports.reconciliation'))->assertForbidden();

    // Grant PLATFORM_VIEW_FREIGHT and verify immediate unlock without stale cache.
    sbLoginAs($superAdmin);
    $this->put(route('admin.roles.permissions.sync', $customRole), [
        'permission_ids' => [$lrViewPerm->id, $acctViewPerm->id, $viewFreightPerm->id],
        'remark' => 'Enable freight and accounting visibility',
    ])->assertRedirect();

    sbLoginAs($custUser);
    $this->post(route('workspace.companies.enter', $ctx['company']))->assertRedirect();
    $this->post(route('workspace.branches.enter', [$ctx['company'], $ctx['branch']]))->assertRedirect();
    $this->get(route('branch.accounting.vouchers.index'))->assertOk();
});
