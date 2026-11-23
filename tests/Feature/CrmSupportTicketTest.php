<?php

use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Enums\SupportTicketPriority;
use App\Domains\Crm\Enums\SupportTicketStatus;
use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Domains\Crm\LeadConversionService;
use App\Domains\Crm\LeadService;
use App\Domains\Crm\SupportTicketService;
use App\Models\Crm\SupportTicket;
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

function crmSupportLogin(User $user, string $password = 'password'): TestResponse
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

test('support ticket can be created and transitioned with reopen reason', function () {
    $sa = User::factory()->superAdmin()->create([
        'password' => Hash::make('sa-secret'),
    ]);

    crmSupportLogin($sa, 'sa-secret');

    $this->post(route('admin.crm.tickets.store'), [
        'subject' => 'Cannot login',
        'description' => 'Owner cannot access workspace after invite.',
        'category' => 'system',
        'priority' => SupportTicketPriority::High->value,
    ])->assertRedirect();

    $ticket = SupportTicket::query()->where('subject', 'Cannot login')->firstOrFail();
    expect($ticket->status)->toBe(SupportTicketStatus::Open);

    app(SupportTicketService::class)->transition($sa, $ticket, SupportTicketStatus::InProgress);
    app(SupportTicketService::class)->transition($sa, $ticket->fresh(), SupportTicketStatus::Resolved, 'Fixed session');
    expect($ticket->fresh()->status)->toBe(SupportTicketStatus::Resolved);

    expect(fn () => app(SupportTicketService::class)->transition(
        $sa,
        $ticket->fresh(),
        SupportTicketStatus::InProgress,
    ))->toThrow(CrmDomainException::class, 'reason is required');

    app(SupportTicketService::class)->transition(
        $sa,
        $ticket->fresh(),
        SupportTicketStatus::InProgress,
        'Customer reports recurrence',
    );

    expect($ticket->fresh()->status)->toBe(SupportTicketStatus::InProgress);
});

test('qualified lead converts to customer company without overwriting existing password', function () {
    $sa = User::factory()->superAdmin()->create();
    $existing = User::factory()->customer()->create([
        'email' => 'existing.customer@example.com',
        'password' => Hash::make('keep-me-secret'),
    ]);
    $passwordBefore = $existing->getAuthPassword();

    $lead = app(LeadService::class)->create($sa, [
        'contact_name' => $existing->name,
        'email' => $existing->email,
        'source' => LeadSource::Referral->value,
        'interested_product' => InterestedProduct::Broker->value,
    ]);
    app(LeadService::class)->transition($sa, $lead, LeadStatus::Contacted);
    app(LeadService::class)->transition($sa, $lead->fresh(), LeadStatus::Qualified, 'Ready');

    $rate = GstRate::query()->firstOrCreate(
        ['code' => 'GST18', 'version' => 1],
        [
            'name' => 'GST 18%',
            'rate_percent' => '18.000',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ],
    );

    $result = app(LeadConversionService::class)->convert($sa, $lead->fresh(), [
        'name' => 'Converted Logistics',
        'pan' => 'ABCDE1234F',
        'registered_address' => '1 Main St',
        'state' => 'Maharashtra',
        'city' => 'Mumbai',
        'pin' => '400001',
        'mobile' => '9876543210',
        'email' => $existing->email,
        'gst_registration_type' => GstRegistrationType::Regular->value,
        'tax_liability' => TaxLiability::Fcm->value,
        'gstin' => '27ABCDE1234F1Z5',
        'gst_rate_id' => $rate->id,
        'data_visibility' => DataVisibility::SharedData->value,
        'timezone' => 'Asia/Kolkata',
    ]);

    expect($result['created_identity'])->toBeFalse()
        ->and($result['lead']->status)->toBe(LeadStatus::Converted)
        ->and($result['company']->owner_user_id)->toBe($existing->id)
        ->and($existing->fresh()->getAuthPassword())->toBe($passwordBefore);

    expect(fn () => app(LeadConversionService::class)->convert($sa, $result['lead']->fresh(), [
        'name' => 'Again',
        'pan' => 'ABCDE1234F',
        'registered_address' => '1 Main St',
        'state' => 'Maharashtra',
        'city' => 'Mumbai',
        'pin' => '400001',
        'mobile' => '9876543210',
        'gst_registration_type' => GstRegistrationType::Regular->value,
        'tax_liability' => TaxLiability::Fcm->value,
        'gstin' => '27ABCDE1234F1Z5',
        'gst_rate_id' => $rate->id,
        'data_visibility' => DataVisibility::SharedData->value,
    ]))->toThrow(CrmDomainException::class, 'already been converted');
});

test('support ticket can be assigned via http and appears on dashboard metrics', function () {
    $sa = User::factory()->superAdmin()->create([
        'password' => Hash::make('sa-secret'),
    ]);
    $agent = User::factory()->internal()->create();

    crmSupportLogin($sa, 'sa-secret');

    $this->post(route('admin.crm.tickets.store'), [
        'subject' => 'Billing question',
        'description' => 'Owner asks about trial expiry.',
        'category' => 'billing',
        'priority' => SupportTicketPriority::Medium->value,
        'assigned_to_user_id' => $agent->id,
    ])->assertRedirect();

    $ticket = SupportTicket::query()->where('subject', 'Billing question')->firstOrFail();

    $this->post(route('admin.crm.tickets.assign', $ticket), [
        'assigned_to_user_id' => $sa->id,
        'remark' => 'Reassign to SA for escalation',
    ])->assertRedirect(route('admin.crm.tickets.show', $ticket));

    expect($ticket->fresh()->assigned_to_user_id)->toBe($sa->id);

    $this->get(route('admin.crm.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/crm/dashboard')
            ->where('metrics.tickets_open', fn ($value) => $value >= 1));
});
