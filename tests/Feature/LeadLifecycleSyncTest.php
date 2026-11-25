<?php

use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\LeadLifecycleService;
use App\Domains\Crm\LeadService;
use App\Domains\Crm\Onboarding\LeadOnboardingService;
use App\Domains\Subscription\Lifecycle\SubscriptionTrialService;
use App\Models\Company;
use App\Models\CompanySubscriptionTrial;
use App\Models\Crm\LeadOnboardingInvitation;
use App\Models\User;
use Illuminate\Support\Str;

test('first company trial activation moves linked lead to trial', function () {
    config(['mail.default' => 'array']);

    $sa = User::factory()->superAdmin()->create();
    $lead = app(LeadService::class)->create($sa, [
        'contact_name' => 'Lifecycle Owner',
        'email' => 'lifecycle.owner.'.uniqid().'@example.com',
        'company_name' => 'Lifecycle Co',
        'source' => LeadSource::Website->value,
        'interested_product' => InterestedProduct::Broker->value,
    ]);

    $invite = app(LeadOnboardingService::class)->sendInvite($sa, $lead);
    $token = $invite['plain_token'];

    $otp = $this->postJson(route('api.public.onboarding.otp.send', ['token' => $token]))
        ->json('debug_otp');
    $this->postJson(route('api.public.onboarding.otp.verify', ['token' => $token]), ['otp' => $otp])
        ->assertOk();
    $this->postJson(route('api.public.onboarding.policy', ['token' => $token]), [
        'policies' => array_keys(config('lead_onboarding.policies')),
    ])->assertOk();
    $this->postJson(route('api.public.onboarding.password', ['token' => $token]), [
        'password' => 'Owner-Pass!23456',
        'password_confirmation' => 'Owner-Pass!23456',
    ])->assertOk();
    $this->postJson(route('api.public.onboarding.complete', ['token' => $token]))->assertOk();

    expect($lead->fresh()->status)->toBe(LeadStatus::Onboarded);

    $owner = User::query()->where('email', $lead->email)->firstOrFail();
    $company = Company::factory()->create([
        'owner_user_id' => $owner->id,
        'name' => 'First Trial Co',
    ]);

    $trial = app(SubscriptionTrialService::class)->startFirstCompanyTrial($owner, $company);
    expect($trial)->not->toBeNull();
    expect($lead->fresh()->status)->toBe(LeadStatus::Trial);

    $activity = $lead->fresh()->activities()
        ->where('activity_type', 'status_changed')
        ->where('body', 'like', '%Trial%')
        ->latest('created_at')
        ->first();
    expect($activity)->not->toBeNull()
        ->and($activity?->actor_user_id)->toBeNull();
});

test('second company without trial does not reset lead status', function () {
    $owner = User::factory()->customer()->create();
    $sa = User::factory()->superAdmin()->create();
    $lead = app(LeadService::class)->create($sa, [
        'contact_name' => 'Multi Co',
        'email' => 'multi.co.'.uniqid().'@example.com',
        'source' => LeadSource::Website->value,
        'interested_product' => InterestedProduct::Broker->value,
    ]);
    $lead->forceFill(['status' => LeadStatus::Trial])->save();

    LeadOnboardingInvitation::query()->create([
        'id' => (string) Str::uuid(),
        'lead_id' => $lead->id,
        'email' => $lead->email,
        'email_normalized' => strtolower((string) $lead->email),
        'token_hash' => hash('sha256', 'x'),
        'expires_at' => now()->addDay(),
        'sent_at' => now(),
        'used_at' => now(),
        'completed_user_id' => $owner->id,
        'delivery_status' => 'sent',
        'created_by_user_id' => $sa->id,
    ]);

    $first = Company::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Co One']);
    app(SubscriptionTrialService::class)->startFirstCompanyTrial($owner, $first);

    $second = Company::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Co Two']);
    $again = app(SubscriptionTrialService::class)->startFirstCompanyTrial($owner, $second);
    expect($again)->toBeNull();
    expect($lead->fresh()->status)->toBe(LeadStatus::Trial);
});

test('reconcileOwner promotes onboarded lead when trial already active', function () {
    $owner = User::factory()->customer()->create();
    $sa = User::factory()->superAdmin()->create();
    $lead = app(LeadService::class)->create($sa, [
        'contact_name' => 'Reconcile',
        'email' => 'reconcile.'.uniqid().'@example.com',
        'source' => LeadSource::Website->value,
        'interested_product' => InterestedProduct::OwnFleet->value,
    ]);
    $lead->forceFill(['status' => LeadStatus::Onboarded])->save();

    LeadOnboardingInvitation::query()->create([
        'id' => (string) Str::uuid(),
        'lead_id' => $lead->id,
        'email' => $lead->email,
        'email_normalized' => strtolower((string) $lead->email),
        'token_hash' => hash('sha256', 'y'),
        'expires_at' => now()->addDay(),
        'sent_at' => now(),
        'used_at' => now(),
        'completed_user_id' => $owner->id,
        'delivery_status' => 'sent',
        'created_by_user_id' => $sa->id,
    ]);

    $company = Company::factory()->create(['owner_user_id' => $owner->id]);
    $trial = app(SubscriptionTrialService::class)->startFirstCompanyTrial($owner, $company);
    expect($trial)->not->toBeNull()
        ->and($lead->fresh()->status)->toBe(LeadStatus::Trial);

    // Simulate a pre-sync onboarded lead (stale status) then reconcile.
    $lead->refresh()->forceFill(['status' => LeadStatus::Onboarded])->save();
    expect($lead->fresh()->status)->toBe(LeadStatus::Onboarded);

    $result = app(LeadLifecycleService::class)->reconcileOwner($owner);
    expect($result['updated'])->toBeGreaterThanOrEqual(1)
        ->and($lead->fresh()->status)->toBe(LeadStatus::Trial);
});

test('expired trial moves linked lead from trial to expired', function () {
    $owner = User::factory()->customer()->create();
    $sa = User::factory()->superAdmin()->create();
    $lead = app(LeadService::class)->create($sa, [
        'contact_name' => 'Expire Trial',
        'email' => 'expire.trial.'.uniqid().'@example.com',
        'source' => LeadSource::Website->value,
        'interested_product' => InterestedProduct::Broker->value,
    ]);
    $lead->forceFill(['status' => LeadStatus::Trial])->save();

    LeadOnboardingInvitation::query()->create([
        'id' => (string) Str::uuid(),
        'lead_id' => $lead->id,
        'email' => $lead->email,
        'email_normalized' => strtolower((string) $lead->email),
        'token_hash' => hash('sha256', 'z'),
        'expires_at' => now()->addDay(),
        'sent_at' => now(),
        'used_at' => now(),
        'completed_user_id' => $owner->id,
        'delivery_status' => 'sent',
        'created_by_user_id' => $sa->id,
    ]);

    $company = Company::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Expired Co']);
    CompanySubscriptionTrial::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $company->id,
        'owner_user_id' => $owner->id,
        'starts_at' => now()->subDays(20),
        'ends_at' => now()->subDay(),
        'timezone' => 'UTC',
        'activated_notified_at' => now()->subDays(20),
        'reminder_sent_for_days' => [],
    ]);

    $processed = app(LeadLifecycleService::class)->processExpiredEntitlements();
    expect($processed)->toBeGreaterThanOrEqual(1)
        ->and($lead->fresh()->status)->toBe(LeadStatus::Expired);
});
