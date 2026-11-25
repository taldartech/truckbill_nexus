<?php

use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Domains\Crm\LeadService;
use App\Models\Crm\Lead;
use App\Models\GstRate;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function crmLogin(User $user, string $password = 'password'): TestResponse
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }

    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    return $response;
}

test('super admin can create lead and advance through normal status path', function () {
    $sa = User::factory()->superAdmin()->create([
        'password' => Hash::make('sa-secret'),
    ]);

    crmLogin($sa, 'sa-secret');

    $this->get(route('admin.crm.leads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/crm/leads/index'));

    $this->post(route('admin.crm.leads.store'), [
        'contact_name' => 'Priya Logistics',
        'email' => 'priya.lead@example.com',
        'company_name' => 'Priya Transport',
        'source' => LeadSource::Website->value,
        'interested_product' => InterestedProduct::OwnFleet->value,
        'remarks' => 'Inbound website form',
    ])->assertRedirect();

    $lead = Lead::query()->where('email', 'priya.lead@example.com')->firstOrFail();
    expect($lead->status)->toBe(LeadStatus::New);

    $this->post(route('admin.crm.leads.transition', $lead), [
        'status' => LeadStatus::Contacted->value,
        'remark' => 'Intro call completed',
    ])->assertRedirect(route('admin.crm.leads.show', $lead));

    expect($lead->fresh()->status)->toBe(LeadStatus::Contacted);

    app(LeadService::class)->transition($sa, $lead->fresh(), LeadStatus::Qualified, 'Budget confirmed');
    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
});

test('duplicate lead within 30 days requires override remark', function () {
    $sa = User::factory()->superAdmin()->create();
    $service = app(LeadService::class);

    $service->create($sa, [
        'contact_name' => 'First',
        'email' => 'dup@example.com',
        'source' => LeadSource::Referral->value,
        'interested_product' => InterestedProduct::Broker->value,
    ]);

    expect(fn () => $service->create($sa, [
        'contact_name' => 'Second',
        'email' => 'dup@example.com',
        'source' => LeadSource::Website->value,
        'interested_product' => InterestedProduct::Broker->value,
    ]))->toThrow(CrmDomainException::class, 'potential duplicate');

    $overridden = $service->create($sa, [
        'contact_name' => 'Second',
        'email' => 'dup@example.com',
        'source' => LeadSource::Website->value,
        'interested_product' => InterestedProduct::Broker->value,
        'duplicate_override' => true,
        'duplicate_override_remark' => 'Confirmed separate branch interest',
    ]);

    expect($overridden->duplicate_override)->toBeTrue()
        ->and(Lead::query()->where('email_normalized', 'dup@example.com')->count())->toBe(2);
});

test('disqualified transition requires remark and customer cannot access crm', function () {
    $sa = User::factory()->superAdmin()->create([
        'password' => Hash::make('sa-secret'),
    ]);
    $customer = User::factory()->customer()->create([
        'password' => Hash::make('customer-secret'),
    ]);
    $lead = app(LeadService::class)->create($sa, [
        'contact_name' => 'Terminal Lead',
        'email' => 'terminal@example.com',
        'source' => LeadSource::Other->value,
        'interested_product' => InterestedProduct::Undecided->value,
    ]);

    expect(fn () => app(LeadService::class)->transition($sa, $lead, LeadStatus::Disqualified))
        ->toThrow(CrmDomainException::class, 'Remarks are required');

    app(LeadService::class)->transition($sa, $lead, LeadStatus::Disqualified, 'Not a fit');
    expect($lead->fresh()->status)->toBe(LeadStatus::Disqualified);

    crmLogin($customer, 'customer-secret');
    $this->get(route('admin.crm.leads.index'))->assertForbidden();
});

test('admin can assign schedule and complete follow-up then convert qualified lead via http', function () {
    $sa = User::factory()->superAdmin()->create([
        'password' => Hash::make('sa-secret'),
    ]);
    $sales = User::factory()->internal()->create([
        'password' => Hash::make('sales-secret'),
    ]);

    crmLogin($sa, 'sa-secret');

    $this->get(route('admin.crm.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/crm/dashboard'));

    $this->post(route('admin.crm.leads.store'), [
        'contact_name' => 'Convert Me',
        'email' => 'convert.http@example.com',
        'mobile' => '9876543210',
        'company_name' => 'Convert Logistics',
        'source' => LeadSource::Website->value,
        'interested_product' => InterestedProduct::OwnFleet->value,
    ])->assertRedirect();

    $lead = Lead::query()->where('email', 'convert.http@example.com')->firstOrFail();

    $this->post(route('admin.crm.leads.assign', $lead), [
        'assigned_to_user_id' => $sales->id,
        'remark' => 'Assign to sales',
    ])->assertRedirect(route('admin.crm.leads.show', $lead));

    expect($lead->fresh()->assigned_to_user_id)->toBe($sales->id);

    $this->post(route('admin.crm.leads.follow-ups.store', $lead), [
        'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
        'notes' => 'Discovery call',
        'assigned_to_user_id' => $sales->id,
    ])->assertRedirect(route('admin.crm.leads.show', $lead));

    $followUp = $lead->fresh()->followUps()->firstOrFail();
    expect($followUp->completed_at)->toBeNull();

    $this->post(route('admin.crm.leads.follow-ups.complete', [$lead, $followUp]), [
        'remark' => 'Call done',
    ])->assertRedirect(route('admin.crm.leads.show', $lead));

    expect($followUp->fresh()->completed_at)->not->toBeNull();

    $this->post(route('admin.crm.leads.transition', $lead), [
        'status' => LeadStatus::Contacted->value,
        'remark' => 'Contacted',
    ])->assertRedirect();
    $this->post(route('admin.crm.leads.transition', $lead), [
        'status' => LeadStatus::Qualified->value,
        'remark' => 'Qualified',
    ])->assertRedirect();

    $rate = GstRate::query()->firstOrCreate(
        ['code' => 'GST18', 'version' => 1],
        [
            'name' => 'GST 18%',
            'rate_percent' => '18.000',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ],
    );

    $this->post(route('admin.crm.leads.convert', $lead), [
        'name' => 'Convert Logistics Pvt Ltd',
        'pan' => 'ABCDE1234F',
        'registered_address' => '12 Harbour Road',
        'state' => 'Maharashtra',
        'city' => 'Mumbai',
        'pin' => '400001',
        'mobile' => '9876543210',
        'email' => 'convert.http@example.com',
        'gst_registration_type' => GstRegistrationType::Regular->value,
        'tax_liability' => TaxLiability::Fcm->value,
        'gstin' => '27ABCDE1234F1Z5',
        'gst_rate_id' => $rate->id,
        'data_visibility' => DataVisibility::SharedData->value,
        'timezone' => 'Asia/Kolkata',
        'new_password' => 'CustomerPass!234',
    ])->assertRedirect(route('admin.crm.leads.show', $lead));

    expect($lead->fresh()->status)->toBe(LeadStatus::Converted)
        ->and($lead->fresh()->converted_company_id)->not->toBeNull();
});

test('lead create form loads assignable internal users without users.is_active column', function () {
    $sa = User::factory()->superAdmin()->create([
        'password' => Hash::make('sa-secret'),
    ]);
    $activeInternal = User::factory()->internal()->create([
        'name' => 'Active Sales',
        'email' => 'active.sales@example.com',
    ]);
    $deactivatedInternal = User::factory()->internal()->create([
        'name' => 'Inactive Sales',
        'email' => 'inactive.sales@example.com',
        'deactivated_at' => now(),
    ]);
    $customer = User::factory()->customer()->create([
        'email' => 'customer.not.assignable@example.com',
    ]);

    $assignable = app(LeadService::class)->assignableInternalUsers();
    $ids = $assignable->pluck('id')->all();
    expect($ids)->toContain($activeInternal->id)
        ->and($ids)->not->toContain($deactivatedInternal->id)
        ->and($ids)->not->toContain($customer->id);

    crmLogin($sa, 'sa-secret');

    // Regression: previously queried users.is_active (undefined column).
    $this->get(route('admin.crm.leads.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/crm/leads/create')
            ->has('assignable_users')
            ->where('assignable_users', fn ($users) => collect($users)->contains(
                fn (array $row): bool => (int) $row['id'] === (int) $activeInternal->id
            )));

    $this->post(route('admin.crm.leads.store'), [
        'contact_name' => 'Assignee Lead',
        'email' => 'assignee.lead@example.com',
        'company_name' => 'Assignee Co',
        'source' => LeadSource::Website->value,
        'interested_product' => InterestedProduct::Broker->value,
        'assigned_to_user_id' => $activeInternal->id,
    ])->assertRedirect();

    $lead = Lead::query()->where('email', 'assignee.lead@example.com')->firstOrFail();
    expect($lead->assigned_to_user_id)->toBe($activeInternal->id);
});
