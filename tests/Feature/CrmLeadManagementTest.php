<?php

use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Domains\Crm\LeadService;
use App\Models\Crm\Lead;
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
