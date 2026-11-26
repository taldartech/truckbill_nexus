<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceDocumentService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceService;
use App\Domains\Subscription\Capacity\CompanyCapacityService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Payments\Gateway\FakePaymentGateway;
use App\Domains\Subscription\Payments\PaymentIntentService;
use App\Domains\Subscription\Quota\QuotaAllocationService;
use App\Domains\Tax\GstRateService;
use App\Domains\Tax\TdsRuleService;
use App\Mail\SubscriptionInvoiceMail;
use App\Models\GstRate;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\TdsRule;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function batchGOwnerCompany(string $name = 'Batch G Co'): array
{
    $owner = User::factory()->customer()->create(['password' => Hash::make('secret')]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, $name);

    return compact('owner', 'company');
}

test('super admin can manage gst rates via admin ui', function () {
    $admin = User::factory()->internal()->superAdmin()->create();

    $this->actingAs($admin)
        ->get(route('admin.gst-rates'))
        ->assertOk();

    $this->actingAs($admin)
        ->post(route('admin.gst-rates.store'), [
            'rate_percent' => '12.000',
            'remark' => 'Batch G create',
        ])
        ->assertRedirect();

    $rate = GstRate::query()->where('rate_percent', '12.000')->where('is_active', true)->first();
    expect($rate)->not->toBeNull();

    $this->actingAs($admin)
        ->post(route('admin.gst-rates.deactivate', $rate), [
            'remark' => 'Deactivate for test',
        ])
        ->assertRedirect();

    expect($rate->fresh()->is_active)->toBeFalse();
});

test('customer cannot open gst or tds admin screens', function () {
    ['owner' => $owner] = batchGOwnerCompany();

    // Customers are redirected away from internal admin surfaces (session policy) or forbidden.
    $gst = $this->actingAs($owner)->get(route('admin.gst-rates'));
    expect(in_array($gst->status(), [302, 403], true))->toBeTrue();

    $tds = $this->actingAs($owner)->get(route('admin.tds-rules'));
    expect(in_array($tds->status(), [302, 403], true))->toBeTrue();
});

test('super admin can manage tds rules via admin ui', function () {
    $admin = User::factory()->internal()->superAdmin()->create();

    $this->actingAs($admin)
        ->post(route('admin.tds-rules.store'), [
            'code' => 'BATCH_G_TDS',
            'name' => 'Batch G TDS',
            'priority' => 55,
            'condition_logic' => 'AND',
            'conditions_json' => json_encode([
                ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'valid'],
            ]),
            'rate_percent' => '1.000',
            'remark' => 'Create via UI',
        ])
        ->assertRedirect();

    $rule = TdsRule::query()->where('code', 'BATCH_G_TDS')->first();
    expect($rule)->not->toBeNull()->and($rule->is_active)->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.tds-rules.update', $rule), [
            'is_active' => false,
            'priority' => 60,
            'remark' => 'Deactivate via UI',
        ])
        ->assertRedirect();

    expect($rule->fresh()->is_active)->toBeFalse()
        ->and($rule->fresh()->priority)->toBe(60);
});

test('owner subscription overview exposes capacity storage and quotas from services', function () {
    ['owner' => $owner, 'company' => $company] = batchGOwnerCompany('Overview Co');
    app(QuotaAllocationService::class)->ensureCounter($company, 'broker', '100.0000', '2026-01-01', '2026-12-31');

    seedManagementSession($company);

    $this->actingAs($owner)
        ->get(route('owner.subscription'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/overview')
            ->has('capacity.purchased_branches')
            ->has('capacity.available_user_capacity')
            ->has('storage.allocated_bytes')
            ->has('storage.remaining_bytes')
            ->has('quotas')
            ->where('quotas.0.quota_type', 'broker')
        );
});

test('scheduled downgrade finalization command is idempotent', function () {
    ['owner' => $owner, 'company' => $company] = batchGOwnerCompany('Downgrade Sched Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);

    $dueDate = CarbonImmutable::yesterday();
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: $dueDate,
    );
    $lifecycle = app(SubscriptionLifecycleService::class);
    $active = $lifecycle->activateAfterVerifiedPayment($draft, $dueDate, $owner);

    $proposal = $lifecycle->scheduleChange(
        actor: $owner,
        company: $company,
        changeType: 'downgrade',
        payload: ['branches' => 50, 'seats' => 50],
        subscription: $active,
        remark: 'Schedule downgrade',
    );

    expect($proposal->status)->toBe('scheduled')
        ->and($proposal->effective_on?->toDateString())->toBe($dueDate->toDateString());

    Artisan::call('subscription:apply-scheduled-changes');
    expect($proposal->fresh()->status)->toBe('applied');

    Artisan::call('subscription:apply-scheduled-changes');
    expect($proposal->fresh()->status)->toBe('applied');
});

test('invoice html preview download and email do not reverse approval', function () {
    Mail::fake();
    ['owner' => $owner, 'company' => $company] = batchGOwnerCompany('Invoice Doc Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);

    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-05-01'),
    );
    $draft->forceFill(['status' => 'pending_payment'])->save();

    $intent = (new PaymentIntentService(new FakePaymentGateway))->create(
        company: $company,
        purpose: 'subscription_activation',
        amount: '100.00',
        idempotencyKey: 'batch-g-inv-1',
        subscription: $draft,
    );
    $intent->forceFill([
        'status' => 'paid',
        'verification_status' => 'verified',
        'gateway_payment_id' => 'pay_batch_g',
    ])->save();

    $invoices = app(SubscriptionInvoiceService::class);
    $invoice = $invoices->ensureDraftQueuedAfterPayment($intent);
    $issuedNumber = (string) $invoice->invoice_number;
    $admin = User::factory()->internal()->superAdmin()->create();
    $approved = $invoices->approveNumber($admin, $invoice, 'TB/2026-2027/G001');

    // Avoid session-policy logout across sequential HTTP calls: exercise document service + one Inertia show.
    $html = app(SubscriptionInvoiceDocumentService::class)->renderHtml($approved);
    expect($html)->toContain('Supplier billing profile was not configured at issuance')
        ->and($html)->toContain($issuedNumber);

    seedManagementSession($company);

    $this->actingAs($owner)
        ->get(route('owner.subscription.invoices.show', ['invoice' => $approved]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/subscription/invoice-preview')
            ->where('document.invoice.approved_number', $issuedNumber)
        );

    Mail::fake();
    $emailed = app(SubscriptionInvoiceDocumentService::class)->dispatchEmail($approved->fresh(), $owner);
    Mail::assertSent(SubscriptionInvoiceMail::class);
    expect($emailed->approved_number)->toBe($issuedNumber)
        ->and($emailed->email_status)->toBe('sent');
});

test('owner cannot approve invoice numbers', function () {
    ['owner' => $owner, 'company' => $company] = batchGOwnerCompany('No Approve Co');
    $invoice = SubscriptionInvoice::query()->create([
        'company_id' => $company->id,
        'status' => 'awaiting_number_approval',
        'proposed_number' => 'TB/2026-2027/0099',
        'financial_year' => '2026-2027',
        'amount' => '10.00',
        'currency' => 'INR',
    ]);

    $this->actingAs($owner)
        ->post(route('admin.subscription-invoices.approve', $invoice), [
            'approved_number' => 'HACKED',
        ])
        ->assertForbidden();

    expect($invoice->fresh()->approved_number)->toBeNull()
        ->and($invoice->fresh()->status)->toBe('awaiting_number_approval');
});

test('capacity service reports purchased and available user capacity', function () {
    ['company' => $company] = batchGOwnerCompany('Capacity G Co');
    $capacity = app(CompanyCapacityService::class)->evaluate($company, extraPurchasedBranches: 1, extraPurchasedSeats: 2);

    expect($capacity['purchased_branches'])->toBe(1)
        ->and($capacity['purchased_seats'])->toBe(2)
        ->and($capacity['branch_limit'])->toBe(3)
        ->and($capacity['available_user_capacity'])->toBe($capacity['available_seat_slots']);
});

test('tax services remain authoritative for historical gst versions', function () {
    $admin = User::factory()->internal()->superAdmin()->create();
    $this->actingAs($admin);

    $gst = app(GstRateService::class);
    $v1 = $gst->createVersion($admin, [
        'rate_percent' => '18.000',
        'effective_from' => '2025-04-01',
    ]);
    $gst->createVersion($admin, [
        'rate_percent' => '18.000',
        'effective_from' => '2026-04-01',
    ]);

    expect($v1->fresh()->is_active)->toBeFalse();
    expect(app(TdsRuleService::class))->toBeInstanceOf(TdsRuleService::class);
});

test('invoice document service email failure preserves approval', function () {
    ['owner' => $owner, 'company' => $company] = batchGOwnerCompany('Email Fail Co');
    $owner->forceFill(['email' => ''])->save();

    $invoice = SubscriptionInvoice::query()->create([
        'company_id' => $company->id,
        'status' => 'approved',
        'proposed_number' => 'TB/2026-2027/E001',
        'approved_number' => 'TB/2026-2027/E001',
        'financial_year' => '2026-2027',
        'amount' => '25.00',
        'currency' => 'INR',
        'approved_at' => now(),
        'approved_by' => User::factory()->internal()->superAdmin()->create()->id,
    ]);

    $result = app(SubscriptionInvoiceDocumentService::class)->dispatchEmail($invoice->fresh());
    expect($result->email_status)->toBe('failed')
        ->and($result->approved_number)->toBe('TB/2026-2027/E001')
        ->and($result->status)->toBe('approved');
});
