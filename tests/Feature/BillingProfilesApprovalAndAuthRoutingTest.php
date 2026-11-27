<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceDocumentService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceService;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Payments\Gateway\FakePaymentGateway;
use App\Domains\Subscription\Payments\PaymentIntentService;
use App\Models\City;
use App\Models\GstRate;
use App\Models\InternalRoleAssignment;
use App\Models\PaymentIntent;
use App\Models\PlatformBillingProfile;
use App\Models\Role;
use App\Models\State;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Files\StoredFile;
use App\Support\Tenant\TenantContextHolder;
use App\Support\Workspace\WorkspaceSession;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    Storage::fake((string) config('files.disk', 'private_files'));
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

function bpSeedGeography(): array
{
    $mh = State::query()->firstOrCreate(
        ['code' => 'MH'],
        ['id' => (string) Str::uuid(), 'name' => 'Maharashtra', 'gst_code' => '27', 'is_active' => true, 'country_code' => 'IN'],
    );
    $gj = State::query()->firstOrCreate(
        ['code' => 'GJ'],
        ['id' => (string) Str::uuid(), 'name' => 'Gujarat', 'gst_code' => '24', 'is_active' => true, 'country_code' => 'IN'],
    );
    $pune = City::query()->firstOrCreate(
        ['state_id' => $mh->id, 'name_normalized' => 'pune'],
        ['id' => (string) Str::uuid(), 'name' => 'Pune', 'is_active' => true],
    );
    $ahmedabad = City::query()->firstOrCreate(
        ['state_id' => $gj->id, 'name_normalized' => 'ahmedabad'],
        ['id' => (string) Str::uuid(), 'name' => 'Ahmedabad', 'is_active' => true],
    );

    $gst18 = GstRate::query()->firstOrCreate(
        ['rate_percent' => '18.000', 'version' => 1],
        ['id' => (string) Str::uuid(), 'effective_from' => '2025-04-01', 'is_active' => true],
    );
    $gst5 = GstRate::query()->firstOrCreate(
        ['rate_percent' => '5.000', 'version' => 1],
        ['id' => (string) Str::uuid(), 'effective_from' => '2025-04-01', 'is_active' => true],
    );
    $gst0 = GstRate::query()->firstOrCreate(
        ['rate_percent' => '0.000', 'version' => 1],
        ['id' => (string) Str::uuid(), 'effective_from' => '2025-04-01', 'is_active' => true],
    );

    return compact('mh', 'gj', 'pune', 'ahmedabad', 'gst18', 'gst5', 'gst0');
}

function bpLogin(User $user, string $password = 'secret123'): TestResponse
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

    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    return $response;
}

test('multiple billing profiles crud, master validation, secure logo storage, edit remark and lifecycle', function () {
    $geo = bpSeedGeography();
    $superAdmin = User::factory()->internal()->superAdmin()->create(['password' => Hash::make('secret123')]);

    bpLogin($superAdmin)->assertRedirect(route('dashboard'));

    // Create first Billing Profile WITHOUT remark, WITH logo upload.
    $logo1 = UploadedFile::fake()->image('logo-one.png', 160, 80);
    $this->post(route('admin.billing-profiles.store'), [
        'legal_name' => 'TruckBill West Billing Pvt Ltd',
        'pan' => 'AABCT1234F',
        'logo' => $logo1,
        'contact_number' => '9876543210',
        'email' => 'west@truckbill.test',
        'registered_address' => '401 Tech Tower, Baner',
        'state_id' => $geo['mh']->id,
        'city_id' => $geo['pune']->id,
        'pin_code' => '411045',
        'gstin' => '27AABCT1234F1Z5',
        'bank_name' => 'HDFC Bank',
        'bank_account_name' => 'TruckBill West Billing Pvt Ltd',
        'bank_account_number' => '50200012345678',
        'bank_ifsc' => 'HDFC0000123',
        'website' => 'https://truckbill.test',
        'notes_terms' => 'Subject to Pune jurisdiction.',
    ])->assertRedirect();

    // Create second Billing Profile (Gujarat, different GSTIN) simultaneously active.
    $this->post(route('admin.billing-profiles.store'), [
        'legal_name' => 'TruckBill Gujarat Billing LLP',
        'pan' => 'AABCT5678G',
        'contact_number' => '9876500001',
        'email' => 'gujarat@truckbill.test',
        'registered_address' => '808 Corporate Hub, SG Highway',
        'state_id' => $geo['gj']->id,
        'city_id' => $geo['ahmedabad']->id,
        'pin_code' => '380054',
        'gstin' => '24AABCT5678G1Z1',
    ])->assertRedirect();

    expect(PlatformBillingProfile::query()->where('is_active', true)->count())->toBe(2);

    $west = PlatformBillingProfile::query()->where('legal_name', 'TruckBill West Billing Pvt Ltd')->firstOrFail();
    expect($west->logo_stored_file_id)->not->toBeNull();
    $logoFile = StoredFile::query()->whereKey($west->logo_stored_file_id)->firstOrFail();
    expect($logoFile->company_id)->toBe(StoredFile::PLATFORM_SCOPE);

    // Authorized Internal user can read platform logo; Customer cannot.
    $this->get(route('admin.billing-profiles.logo', $west))->assertOk();

    $customer = User::factory()->customer()->create(['password' => Hash::make('secret123')]);
    bpLogin($customer);
    $logoResp = $this->get(route('admin.billing-profiles.logo', $west));
    expect(in_array($logoResp->status(), [302, 403], true))->toBeTrue();

    bpLogin($superAdmin);

    // Validation failures: missing remark on edit, mismatched GSTIN state code, partial bank details.
    $this->post(route('admin.billing-profiles.update', $west), [
        'legal_name' => 'TruckBill West Updated',
        'pan' => 'AABCT1234F',
        'contact_number' => '9876543210',
        'email' => 'west@truckbill.test',
        'registered_address' => '401 Tech Tower, Baner',
        'state_id' => $geo['mh']->id,
        'city_id' => $geo['pune']->id,
        'pin_code' => '411045',
        'gstin' => '27AABCT1234F1Z5',
        'remark' => '',
    ])->assertSessionHasErrors('remark');

    expect(fn () => app(PlatformBillingProfileService::class)->create($superAdmin, [
        'legal_name' => 'Bad State Code Co',
        'pan' => 'AABCT1234F',
        'contact_number' => '9876543210',
        'email' => 'bad@truckbill.test',
        'registered_address' => 'Pune',
        'state_id' => $geo['mh']->id,
        'city_id' => $geo['pune']->id,
        'pin_code' => '411001',
        'gstin' => '24AABCT1234F1Z5', // 24 belongs to GJ, not MH (27)
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(PlatformBillingProfileService::class)->create($superAdmin, [
        'legal_name' => 'Partial Bank Co',
        'pan' => 'AABCT1234F',
        'contact_number' => '9876543210',
        'email' => 'bank@truckbill.test',
        'registered_address' => 'Pune',
        'state_id' => $geo['mh']->id,
        'city_id' => $geo['pune']->id,
        'pin_code' => '411001',
        'gstin' => null,
        'bank_name' => 'SBI',
    ]))->toThrow(ValidationException::class);

    // Edit with mandatory remark + lifecycle deactivate/reactivate.
    $this->post(route('admin.billing-profiles.update', $west), [
        'legal_name' => 'TruckBill West Billing Pvt Ltd',
        'pan' => 'AABCT1234F',
        'contact_number' => '9876543219',
        'email' => 'west@truckbill.test',
        'registered_address' => '402 Tech Tower, Baner',
        'state_id' => $geo['mh']->id,
        'city_id' => $geo['pune']->id,
        'pin_code' => '411045',
        'gstin' => '27AABCT1234F1Z5',
        'bank_name' => 'HDFC Bank',
        'bank_account_name' => 'TruckBill West Billing Pvt Ltd',
        'bank_account_number' => '50200012345678',
        'bank_ifsc' => 'HDFC0000123',
        'remark' => 'Updated office suite and phone',
    ])->assertRedirect();

    expect($west->fresh()->contact_number)->toBe('9876543219');

    $this->post(route('admin.billing-profiles.deactivate', $west), [
        'remark' => 'Retiring old unit',
    ])->assertRedirect();
    expect($west->fresh()->is_active)->toBeFalse();

    $this->post(route('admin.billing-profiles.reactivate', $west), [
        'remark' => 'Re-enabling West profile',
    ])->assertRedirect();
    expect($west->fresh()->is_active)->toBeTrue();
});

test('subscription approval separation, visibility restriction, gst conflict blocking, uniqueness, statutory window and snapshot immutability', function () {
    Mail::fake();
    $geo = bpSeedGeography();
    $superAdmin = User::factory()->internal()->superAdmin()->create(['password' => Hash::make('secret123')]);
    bpLogin($superAdmin);
    $profiles = app(PlatformBillingProfileService::class);
    $invoices = app(SubscriptionInvoiceService::class);
    $profiles->updateSubscriptionGstRate($superAdmin, $geo['gst18']->id);

    $logoOriginal = UploadedFile::fake()->image('orig-logo.png', 180, 90);
    $mhProfile18 = $profiles->create($superAdmin, [
        'legal_name' => 'TruckBill MH 18% Profile',
        'pan' => 'AABCT1111A',
        'contact_number' => '9876543210',
        'email' => 'mh18@truckbill.test',
        'registered_address' => 'Pune MH',
        'state_id' => $geo['mh']->id,
        'city_id' => $geo['pune']->id,
        'pin_code' => '411001',
        'gstin' => '27AABCT1111A1Z5',
        'bank_name' => 'ICICI Bank',
        'bank_account_name' => 'TruckBill MH 18%',
        'bank_account_number' => '112233445566',
        'bank_ifsc' => 'ICIC0000123',
        'website' => 'https://mh18.truckbill.test',
        'notes_terms' => 'Original Terms V1',
    ], $logoOriginal);

    // Customer Owner purchases Broker subscription in Maharashtra (intra-state CGST+SGST at 18%).
    $owner = User::factory()->customer()->create(['password' => Hash::make('secret123')]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Sahyadri Carriers Pvt Ltd');
    $company->forceFill([
        'registered_address' => '10 Highway Road, Nashik',
        'state_id' => $geo['mh']->id,
        'state' => 'Maharashtra',
        'city' => 'Nashik',
        'pin' => '422001',
        'gstin' => '27AAACS9999K1Z2',
        'pan' => 'AAACS9999K',
    ])->save();

    $otherOwner = User::factory()->customer()->create(['password' => Hash::make('secret123')]);
    $otherCompany = app(CompanyWorkspaceService::class)->createCompany($otherOwner, 'Other Transport Co');

    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $brokerPlan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('module', 'broker')->firstOrFail();

    bpLogin($owner)->assertRedirect(route('owner.home'));
    $this->post(route('owner.companies.subscription.store', $company), [
        'plan_ids' => [$brokerPlan->id],
        'addon_ids' => [],
        'tenure' => SubscriptionTenure::Yearly->value,
        'mode' => 'initial',
    ])->assertRedirect();

    $intent = PaymentIntent::query()->where('company_id', $company->id)->latest('created_at')->firstOrFail();
    $this->post(route('owner.companies.subscription.checkout.simulate', [$company, $intent]))
        ->assertRedirect(route('owner.companies.subscription.purchase', $company));

    // Step 2 & 3: Subscription activated immediately, invoice stays Pending Approval.
    expect(app(CompanyCapabilityResolver::class)->allowsRead($company->fresh(), CompanyCapabilities::BROKER_LR))->toBeTrue();
    $invoice = SubscriptionInvoice::query()->where('payment_intent_id', $intent->id)->firstOrFail();
    expect($invoice->status)->toBe(SubscriptionInvoice::STATUS_PENDING_APPROVAL)
        ->and($invoice->approved_number)->toBeNull()
        ->and($invoice->billing_profile_id)->toBeNull();

    // Step 5: Customer sees "Invoice Pending Approval" in Payment History & Invoices, but direct URLs are 403.
    enterManagementCompany($company)->assertRedirect();
    $this->get(route('owner.subscription.payments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('payments.0.invoice_status', 'pending_approval')
            ->where('payments.0.invoice_label', 'Invoice Pending Approval')
            ->where('payments.0.invoice_url', null)
            ->where('payments.0.invoice_download_url', null)
        );
    $this->get(route('owner.subscription.invoices'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('invoices.0.status_label', 'Invoice Pending Approval')
            ->where('invoices.0.preview_url', null)
            ->where('invoices.0.download_url', null)
        );
    $this->get(route('owner.subscription.invoices.show', $invoice))->assertForbidden();
    $this->get(route('owner.subscription.invoices.download', $invoice))->assertForbidden();
    $this->post(route('owner.subscription.invoices.email', $invoice))->assertForbidden();

    // Cross-company access must be denied (404).
    bpLogin($otherOwner);
    enterManagementCompany($otherCompany)->assertRedirect();
    $this->get(route('owner.subscription.invoices.show', $invoice))->assertNotFound();

    // Step 4: Create additional profiles (shared GSTIN, GJ inter-state mismatch) & verify approval guards.
    bpLogin($superAdmin);
    $mhSameGstin = $profiles->create($superAdmin, [
        'legal_name' => 'TruckBill MH Secondary Desk',
        'pan' => 'AABCT1111A',
        'contact_number' => '9876543211',
        'email' => 'mhdesk@truckbill.test',
        'registered_address' => 'Pune Camp',
        'state_id' => $geo['mh']->id,
        'city_id' => $geo['pune']->id,
        'pin_code' => '411001',
        'gstin' => '27AABCT1111A1Z5',
    ]);
    $gjProfile18 = $profiles->create($superAdmin, [
        'legal_name' => 'TruckBill GJ 18% Profile',
        'pan' => 'AABCT3333C',
        'contact_number' => '9876543213',
        'email' => 'gj18@truckbill.test',
        'registered_address' => 'Ahmedabad GJ',
        'state_id' => $geo['gj']->id,
        'city_id' => $geo['ahmedabad']->id,
        'pin_code' => '380001',
        'gstin' => '24AABCT3333C1Z5',
    ]);

    $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
    $yesterday = CarbonImmutable::now('Asia/Kolkata')->subDays(2)->toDateString();
    $tomorrow = CarbonImmutable::now('Asia/Kolkata')->addDay()->toDateString();

    expect(fn () => $invoices->approveInvoice($superAdmin, $invoice, $gjProfile18->id, 'TB/2026-27/BADSTATE', $today))
        ->toThrow(SubscriptionDomainException::class, 'GST configuration conflict');

    expect(fn () => $invoices->approveInvoice($superAdmin, $invoice, $mhProfile18->id, 'TB/2026-27/PAST', $yesterday))
        ->toThrow(SubscriptionDomainException::class, 'precede');

    expect(fn () => $invoices->approveInvoice($superAdmin, $invoice, $mhProfile18->id, 'TB/2026-27/FUTURE', $tomorrow))
        ->toThrow(SubscriptionDomainException::class, 'future');

    // Statutory 30-day window check when payment was 35 days ago.
    $oldIntent = (new PaymentIntentService(new FakePaymentGateway))->create($company, 'renewal', (string) $invoice->amount, 'old-stat-win');
    $oldIntent->timestamps = false;
    $oldIntent->forceFill([
        'status' => 'paid',
        'verification_status' => 'verified',
        'gateway_payment_id' => 'pay_old_35',
        'updated_at' => CarbonImmutable::now('Asia/Kolkata')->subDays(35),
    ])->save();
    $oldInvoice = $invoices->createPendingApprovalFromPayment($oldIntent);
    expect(fn () => $invoices->approveInvoice($superAdmin, $oldInvoice, $mhProfile18->id, 'TB/2026-27/LATE', $today))
        ->toThrow(SubscriptionDomainException::class, 'Statutory GST issuance deadline exceeded');

    // Changing the common subscription GST rate after purchase must not alter or block the paid invoice.
    $profiles->updateSubscriptionGstRate($superAdmin, $geo['gst5']->id, 'Rate revision for future purchases');

    // Valid approval using MH 18% Profile.
    $this->post(route('admin.subscription-invoices.approve', $invoice), [
        'billing_profile_id' => $mhProfile18->id,
        'invoice_number' => 'MH-26-0001',
        'invoice_date' => $today,
    ])->assertRedirect();

    $approved = $invoice->fresh();
    expect($approved->isApprovedForCustomer())->toBeTrue()
        ->and($approved->approved_number)->toBe('MH-26-0001')
        ->and($approved->billing_profile_id)->toBe($mhProfile18->id)
        ->and((string) $approved->gst_rate_percent)->toBe('18.00')
        ->and($approved->supplier_snapshot['legal_name'])->toBe('TruckBill MH 18% Profile')
        ->and($approved->supplier_snapshot['logo_snapshot_path'])->not->toBeNull();

    // Duplicate approval must be blocked.
    expect(fn () => $invoices->approveInvoice($superAdmin, $approved, $mhProfile18->id, 'MH-26-0099', $today))
        ->toThrow(SubscriptionDomainException::class, 'already been approved');

    // Duplicate (supplier_registration_key, FY, invoice_number) across SAME GSTIN must be blocked.
    $intent2 = (new PaymentIntentService(new FakePaymentGateway))->create($company, 'renewal', (string) $invoice->amount, 'dup-num-2');
    $intent2->forceFill([
        'status' => 'paid',
        'verification_status' => 'verified',
        'gateway_payment_id' => 'pay_dup2',
        'meta' => [
            'quote' => [
                'taxable_amount' => (string) $invoice->taxable_amount,
                'subtotal_amount' => (string) $invoice->subtotal_amount,
                'discount_amount' => (string) $invoice->discount_amount,
                'gst' => [
                    'gst_rate_percent' => (string) $invoice->gst_rate_percent,
                    'cgst_amount' => (string) $invoice->cgst_amount,
                    'sgst_amount' => (string) $invoice->sgst_amount,
                    'igst_amount' => (string) $invoice->igst_amount,
                    'total_tax_amount' => (string) $invoice->total_tax_amount,
                    'is_inter_state' => (bool) $invoice->is_inter_state,
                ],
            ],
        ],
    ])->save();
    $invoice2 = $invoices->createPendingApprovalFromPayment($intent2);
    expect(fn () => $invoices->approveInvoice($superAdmin, $invoice2, $mhSameGstin->id, 'mh-26-0001', $today))
        ->toThrow(SubscriptionDomainException::class, 'already issued');

    // Same invoice number IS allowed on a DIFFERENT supplier registration (GJ GSTIN, matching inter-state GJ customer).
    $gjOwner = User::factory()->customer()->create(['password' => Hash::make('secret123')]);
    $gjCompany = app(CompanyWorkspaceService::class)->createCompany($gjOwner, 'Gujarat Haulers Ltd');
    $gjCompany->forceFill([
        'state_id' => $geo['gj']->id,
        'state' => 'Gujarat',
        'city' => 'Ahmedabad',
        'pin' => '380001',
        'gstin' => '24AAACG1111L1Z5',
    ])->save();
    $gjIntent = (new PaymentIntentService(new FakePaymentGateway))->create($gjCompany, 'renewal', '1180.00', 'gj-same-num');
    $gjIntent->forceFill([
        'status' => 'paid',
        'verification_status' => 'verified',
        'gateway_payment_id' => 'pay_gj',
        'meta' => [
            'quote' => [
                'taxable_amount' => '1000.00',
                'subtotal_amount' => '1000.00',
                'discount_amount' => '0.00',
                'gst' => [
                    'gst_rate_percent' => '18.00',
                    'cgst_amount' => '90.00',
                    'sgst_amount' => '90.00',
                    'igst_amount' => '0.00',
                    'total_tax_amount' => '180.00',
                    'is_inter_state' => false,
                ],
            ],
        ],
    ])->save();
    $gjInvoice = $invoices->createPendingApprovalFromPayment($gjIntent);
    $gjApproved = $invoices->approveInvoice($superAdmin, $gjInvoice, $gjProfile18->id, 'MH-26-0001', $today);
    expect($gjApproved->approved_number)->toBe('MH-26-0001');

    // Admin/CRM Register filters: pending_approval, approved, company_id, billing_profile_id, date range.
    $this->get(route('admin.subscription-invoices', ['status' => 'pending_approval']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('invoices', fn ($rows) => collect($rows)->every(fn ($r) => $r['status'] === 'pending_approval'))
        );
    $this->get(route('admin.subscription-invoices', [
        'status' => 'approved',
        'customer_company' => $company->id,
        'billing_profile_id' => $mhProfile18->id,
        'date_from' => $today,
        'date_to' => $today,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invoices', 1)
            ->where('invoices.0.invoice_number', 'MH-26-0001')
        );

    // Step 6: Mutate & deactivate Billing Profile AND replace logo AFTER approval -> historical invoice MUST remain untouched.
    $newLogo = UploadedFile::fake()->image('new-logo.png', 180, 90);
    app(PlatformBillingProfileService::class)->update($superAdmin, $mhProfile18, [
        'legal_name' => 'TruckBill Mutated Legal Name',
        'pan' => 'AABCT1111A',
        'contact_number' => '9000000000',
        'email' => 'mutated@truckbill.test',
        'registered_address' => '999 Mutated Tower',
        'state_id' => $geo['mh']->id,
        'city_id' => $geo['pune']->id,
        'pin_code' => '411099',
        'gstin' => '27AABCT1111A1Z5',
        'bank_name' => 'Axis Bank',
        'bank_account_name' => 'Mutated Account',
        'bank_account_number' => '99999999',
        'bank_ifsc' => 'UTIB0000001',
        'notes_terms' => 'Mutated Terms V2',
    ], $newLogo, 'Post-approval profile edit');
    app(PlatformBillingProfileService::class)->deactivate($superAdmin, $mhProfile18->fresh(), 'Deactivated after issuance');

    $snapCheck = $approved->fresh();
    expect($snapCheck->supplier_snapshot['legal_name'])->toBe('TruckBill MH 18% Profile')
        ->and($snapCheck->supplier_snapshot['notes_terms'])->toBe('Original Terms V1')
        ->and(fn () => $snapCheck->delete())->toThrow(SubscriptionDomainException::class);

    $html = app(SubscriptionInvoiceDocumentService::class)->renderHtml($snapCheck);
    expect($html)->toContain('TruckBill MH 18% Profile')
        ->and($html)->toContain('Original Terms V1')
        ->and($html)->not->toContain('TruckBill Mutated Legal Name')
        ->and($html)->toContain('data:image/png;base64,');

    // Step 5: Customer can now view and download the approved invoice from Owner Panel.
    bpLogin($owner);
    enterManagementCompany($company)->assertRedirect();
    $this->get(route('owner.subscription.invoices.show', $snapCheck))->assertOk();
    $this->get(route('owner.subscription.invoices.download', $snapCheck))
        ->assertOk()
        ->assertSee('TruckBill MH 18% Profile')
        ->assertSee('MH-26-0001');
});

test('authoritative authentication routing eliminates crm login branch redirect error across all roles and edge cases', function () {
    $superAdmin = User::factory()->internal()->superAdmin()->create(['password' => Hash::make('secret123')]);
    $internalAdmin = User::factory()->internal()->create(['password' => Hash::make('secret123')]);
    $salesExec = User::factory()->internal()->create(['password' => Hash::make('secret123')]);

    $adminRole = Role::query()->where('domain', PermissionDomain::Internal->value)->where('slug', 'internal.admin')->firstOrFail();
    $salesRole = Role::query()->where('domain', PermissionDomain::Internal->value)->where('slug', 'internal.sales_executive')->firstOrFail();

    InternalRoleAssignment::query()->create(['id' => (string) Str::uuid(), 'user_id' => $internalAdmin->id, 'role_id' => $adminRole->id]);
    InternalRoleAssignment::query()->create(['id' => (string) Str::uuid(), 'user_id' => $salesExec->id, 'role_id' => $salesRole->id]);

    $owner = User::factory()->customer()->create(['password' => Hash::make('secret123')]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Auth Test Logistics');
    $branch1 = $company->branches()->where('name', 'Head Office')->firstOrFail();
    $branch2 = app(CompanyWorkspaceService::class)->createBranch($company, 'Surat Hub');

    $singleBranchUser = User::factory()->customer()->create(['password' => Hash::make('secret123')]);
    $multiBranchUser = User::factory()->customer()->create(['password' => Hash::make('secret123')]);
    $workspace = app(CompanyWorkspaceService::class);
    $customerAdminRole = Role::query()->where('domain', PermissionDomain::Customer->value)->where('slug', 'customer.admin')->firstOrFail();

    $m1 = $workspace->addMembership($company, $singleBranchUser, 'Single branch operator');
    $a1 = $workspace->assignBranch($m1, $branch1, 'HO');
    app(RoleAssignmentService::class)->assignCustomerBranchRole($owner, $m1, $branch1, $a1, $customerAdminRole, 'Admin on HO');

    $m2 = $workspace->addMembership($company, $multiBranchUser, 'Multi branch operator');
    $a2a = $workspace->assignBranch($m2, $branch1, 'HO');
    $a2b = $workspace->assignBranch($m2, $branch2, 'Surat');
    app(RoleAssignmentService::class)->assignCustomerBranchRole($owner, $m2, $branch1, $a2a, $customerAdminRole, 'Admin HO');
    app(RoleAssignmentService::class)->assignCustomerBranchRole($owner, $m2, $branch2, $a2b, $customerAdminRole, 'Admin Surat');

    // 1. Clean logins for Super Admin, Internal Admin, and Sales Executive land directly on /dashboard.
    foreach ([$superAdmin, $internalAdmin, $salesExec] as $internalUser) {
        $resp = bpLogin($internalUser);
        $resp->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }

    // 2. Internal login with stale Customer WorkspaceSession keys clears them and lands on /dashboard.
    auth()->guard('web')->logout();
    $this->withSession([
        WorkspaceSession::COMPANY_KEY => $company->id,
        WorkspaceSession::BRANCH_KEY => $branch1->id,
        WorkspaceSession::VERSION_KEY => 99,
    ]);
    $staleResp = $this->post(route('login.store'), [
        'email' => $superAdmin->email,
        'password' => 'secret123',
    ]);
    $staleResp->assertRedirect(route('dashboard'));
    foreach ($staleResp->headers->getCookies() as $cookie) {
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
    expect(session()->has(WorkspaceSession::COMPANY_KEY))->toBeFalse()
        ->and(session()->has(WorkspaceSession::BRANCH_KEY))->toBeFalse();
    $this->get(route('dashboard'))->assertOk();

    // 3. Internal login after hitting protected Branch URL (/operations) rejects cross-portal intended URL.
    $this->post(route('logout'));
    $this->flushSession();
    auth()->guard('web')->logout();
    $this->get(route('branch.dashboard'))->assertRedirect(route('login'));

    $intendedBranchResp = $this->post(route('login.store'), [
        'email' => $internalAdmin->email,
        'password' => 'secret123',
    ]);
    $intendedBranchResp->assertRedirect(route('dashboard'));
    foreach ($intendedBranchResp->headers->getCookies() as $cookie) {
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
    $this->get(route('dashboard'))->assertOk();

    // 4. Legitimate authorized Internal intended destination IS preserved; unauthorized Internal URL is rejected.
    $this->post(route('logout'));
    $this->flushSession();
    auth()->guard('web')->logout();
    $this->get(route('admin.billing-profiles.index'))->assertRedirect(route('login'));
    $legitInternalResp = $this->post(route('login.store'), [
        'email' => $superAdmin->email,
        'password' => 'secret123',
    ]);
    $legitInternalResp->assertRedirect(route('admin.billing-profiles.index'));

    $this->post(route('logout'));
    $this->flushSession();
    auth()->guard('web')->logout();
    $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
    $unauthInternalResp = $this->post(route('login.store'), [
        'email' => $salesExec->email,
        'password' => 'secret123',
    ]);
    $unauthInternalResp->assertRedirect(route('dashboard'));

    // 5. Customer Owner login rejects Internal intended URL and lands on Owner Panel.
    $this->post(route('logout'));
    $this->flushSession();
    auth()->guard('web')->logout();
    $this->get(route('admin.billing-profiles.index'))->assertRedirect(route('login'));
    $ownerResp = $this->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'secret123',
    ]);
    $ownerResp->assertRedirect(route('owner.home'));

    // 6. Single-branch Customer User lands on /operations; multi-branch Customer User lands on branch selection.
    bpLogin($singleBranchUser)->assertRedirect(route('branch.dashboard'));
    $this->get(route('branch.dashboard'))->assertOk();

    bpLogin($multiBranchUser)->assertRedirect(route('workspace.branches.select', ['company' => $company]));
});
