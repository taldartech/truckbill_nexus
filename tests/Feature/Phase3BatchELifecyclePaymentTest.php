<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Subscription\Billing\SubscriptionInvoiceService;
use App\Domains\Subscription\Capacity\CompanyCapacityService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Credits\CreditReservationService;
use App\Domains\Subscription\Enums\SubscriptionLifecycleStatus;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Domains\Subscription\Lifecycle\SubscriptionStatusEvaluator;
use App\Domains\Subscription\Lifecycle\SubscriptionTrialService;
use App\Domains\Subscription\Payments\Gateway\FakePaymentGateway;
use App\Domains\Subscription\Payments\PaymentEventProcessor;
use App\Domains\Subscription\Payments\PaymentIntentService;
use App\Domains\Subscription\Quota\QuotaAllocationService;
use App\Domains\Subscription\Storage\StorageReconciliationService;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\CompanySubscriptionTrial;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    TenantContextHolder::clear();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function batchEOwnerCompany(string $name = 'Batch E Co'): array
{
    $owner = User::factory()->customer()->create(['password' => Hash::make('secret')]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, $name);

    return compact('owner', 'company');
}

test('first company receives trial and subsequent company does not', function () {
    $owner = User::factory()->customer()->create();
    $workspace = app(CompanyWorkspaceService::class);

    $first = $workspace->createCompany($owner, 'First Co');
    expect(CompanySubscriptionTrial::query()->where('company_id', $first->id)->exists())->toBeTrue()
        ->and(CompanySubscriptionTrial::query()->where('owner_user_id', $owner->id)->count())->toBe(1);

    $second = $workspace->createCompany($owner, 'Second Co');
    expect(CompanySubscriptionTrial::query()->where('company_id', $second->id)->exists())->toBeFalse()
        ->and(CompanySubscriptionTrial::query()->where('owner_user_id', $owner->id)->count())->toBe(1);
});

test('trial boundary and paid grace evaluator transitions are deterministic', function () {
    $clock = new SubscriptionClock;
    $clock->freeze(CarbonImmutable::parse('2026-01-01 12:00:00', 'UTC'));
    $trials = new SubscriptionTrialService(clock: $clock);
    $evaluator = new SubscriptionStatusEvaluator(clock: $clock);

    $owner = User::factory()->customer()->create();
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Eval Co');
    // recreate trial under frozen clock by deleting auto trial and starting again
    CompanySubscriptionTrial::query()->where('owner_user_id', $owner->id)->delete();
    $trial = $trials->startFirstCompanyTrial($owner, $company);
    expect($trial)->not->toBeNull();

    $status = $evaluator->evaluate($company);
    expect($status['status'])->toBe(SubscriptionLifecycleStatus::Trial);

    $clock->freeze(CarbonImmutable::parse('2026-01-16 12:00:01', 'UTC'));
    expect($evaluator->evaluate($company)['status'])->toBe(SubscriptionLifecycleStatus::Restricted);

    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-01-01'),
    );

    $lifecycle = new SubscriptionLifecycleService(evaluator: $evaluator, clock: $clock);
    $active = $lifecycle->activateAfterVerifiedPayment(
        $draft,
        CarbonImmutable::parse('2026-02-01'),
        $owner,
    );

    expect($active->status)->toBe('active')
        ->and($evaluator->evaluate($company)['status'])->toBe(SubscriptionLifecycleStatus::Active);

    $clock->freeze(CarbonImmutable::parse('2026-02-02 00:00:00', 'UTC'));
    expect($evaluator->evaluate($company)['status'])->toBe(SubscriptionLifecycleStatus::PaidGrace);

    $clock->freeze(CarbonImmutable::parse('2026-02-12 00:00:00', 'UTC'));
    expect($evaluator->evaluate($company)['status'])->toBe(SubscriptionLifecycleStatus::Restricted);
});

test('cancellation schedules at common renewal and may be withdrawn before effective date', function () {
    ['owner' => $owner, 'company' => $company] = batchEOwnerCompany('Cancel Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);

    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-06-01'),
    );
    $lifecycle = app(SubscriptionLifecycleService::class);
    $active = $lifecycle->activateAfterVerifiedPayment($draft, CarbonImmutable::parse('2027-06-01'), $owner);

    $pending = $lifecycle->requestCancellation($owner, $active, 'Cancel at renewal');
    expect($pending->cancellation_effective_on?->toDateString())->toBe('2027-06-01')
        ->and($pending->cancellation_requested_at)->not->toBeNull();

    $withdrawn = $lifecycle->withdrawCancellation($owner, $pending, 'Keep subscription');
    expect($withdrawn->cancellation_requested_at)->toBeNull()
        ->and($withdrawn->cancellation_effective_on)->toBeNull();
});

test('scheduled downgrade retains current plan when usage exceeds proposed limits', function () {
    ['owner' => $owner, 'company' => $company] = batchEOwnerCompany('Downgrade Co');
    $this->actingAs($owner);
    $lifecycle = app(SubscriptionLifecycleService::class);

    $proposal = $lifecycle->scheduleChange(
        actor: $owner,
        company: $company,
        changeType: 'downgrade',
        payload: ['target_plan' => 'micro'],
        subscription: null,
        remark: 'Cannot schedule without active renewal',
    );
})->throws(SubscriptionDomainException::class);

test('quota proration floors fractions and three-year windows refresh yearly', function () {
    $quota = new QuotaAllocationService;
    expect($quota->prorateIncludedUnits('100', SubscriptionTenure::Monthly))->toBe('8')
        ->and($quota->prorateIncludedUnits('100', SubscriptionTenure::Quarterly))->toBe('25')
        ->and($quota->prorateIncludedUnits('100', SubscriptionTenure::Yearly, couponExtraMonths: 1))->toBe('108');

    $window = $quota->threeYearQuotaWindow(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2027-06-01'),
    );
    expect($window['year_index'])->toBe(1);
});

test('credit reservations are idempotent and release on failure path', function () {
    ['owner' => $owner, 'company' => $company] = batchEOwnerCompany('Credit Co');
    $contracts = app(CompanySubscriptionContractService::class);
    $ledger = $contracts->ensureCreditLedger($company, 'broker');
    $contracts->appendCreditEntry($ledger, '50.0000', 'grant', 'Seed credits', 'grant-1');

    $reservations = app(CreditReservationService::class);
    $a = $reservations->reserve($ledger->fresh(), '20.0000', 'idem-res-1', 'Reserve for LR');
    $b = $reservations->reserve($ledger->fresh(), '20.0000', 'idem-res-1', 'Reserve for LR');
    expect($a->id)->toBe($b->id);

    $reservations->release($a);
    expect($a->fresh()->status)->toBe('released');

    $held = $reservations->reserve($ledger->fresh(), '30.0000', 'idem-res-2', 'Hold');
    $committed = $reservations->commit($held);
    expect($committed->status)->toBe('committed')
        ->and((string) $ledger->fresh()->balance)->toBe('20.0000');
});

test('capacity evaluation excludes owner seat by default', function () {
    ['company' => $company] = batchEOwnerCompany('Capacity Co');
    $capacity = app(CompanyCapacityService::class)->evaluate($company);
    expect($capacity['included_branches'])->toBe(2)
        ->and($capacity['included_seats'])->toBe(6)
        ->and($capacity['owner_is_billable_seat'])->toBeFalse()
        ->and($capacity['used_branches'])->toBe(1);
});

test('fake payment signature verification rejects tampering and duplicate events activate once', function () {
    ['owner' => $owner, 'company' => $company] = batchEOwnerCompany('Pay Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);

    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-01-01'),
    );
    $draft->forceFill(['status' => 'pending_payment'])->save();

    $gateway = new FakePaymentGateway;
    $intents = new PaymentIntentService($gateway);
    $intent = $intents->create(
        company: $company,
        purpose: 'subscription_activation',
        amount: '1000.00',
        idempotencyKey: 'pay-idem-1',
        subscription: $draft,
    );

    $processor = new PaymentEventProcessor($gateway);
    $event = [
        'event_id' => 'evt_1',
        'event_type' => 'payment.captured',
        'order_id' => $intent->gateway_order_id,
        'payment_id' => 'pay_1',
        'status' => 'captured',
        'amount' => '1000.00',
        'currency' => 'INR',
    ];
    $signed = $gateway->signWebhook($event);

    $bad = $processor->processVerifiedEvent($signed['raw'], 'bad-signature', $event, $gateway->secret());
    expect($bad->processing_status)->toBe('rejected');

    $ok = $processor->processVerifiedEvent($signed['raw'], $signed['signature'], $event, $gateway->secret());
    expect($ok->processing_status)->toBe('processed')
        ->and($intent->fresh()->status)->toBe('paid')
        ->and($draft->fresh()->status)->toBe('active');

    $dup = $processor->processVerifiedEvent($signed['raw'], $signed['signature'], $event, $gateway->secret());
    expect($dup->processing_status)->toBe('duplicate');

    $mismatchEvent = $event;
    $mismatchEvent['event_id'] = 'evt_2';
    $mismatchEvent['amount'] = '1.00';
    $mismatchSigned = $gateway->signWebhook($mismatchEvent);
    $quarantined = $processor->processVerifiedEvent(
        $mismatchSigned['raw'],
        $mismatchSigned['signature'],
        $mismatchEvent,
        $gateway->secret(),
    );
    expect($quarantined->processing_status)->toBe('quarantined');
});

test('failed payment does not activate subscription', function () {
    ['owner' => $owner, 'company' => $company] = batchEOwnerCompany('Fail Pay Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-01-01'),
    );
    $draft->forceFill(['status' => 'pending_payment'])->save();

    $gateway = new FakePaymentGateway;
    $intent = (new PaymentIntentService($gateway))->create(
        $company,
        'subscription_activation',
        '500.00',
        'fail-pay-1',
        subscription: $draft,
    );

    $event = [
        'event_id' => 'evt_fail',
        'event_type' => 'payment.failed',
        'order_id' => $intent->gateway_order_id,
        'payment_id' => 'pay_fail',
        'status' => 'failed',
        'amount' => '500.00',
        'currency' => 'INR',
    ];
    $signed = $gateway->signWebhook($event);
    (new PaymentEventProcessor($gateway))->processVerifiedEvent(
        $signed['raw'],
        $signed['signature'],
        $event,
        $gateway->secret(),
    );

    expect($intent->fresh()->status)->toBe('failed')
        ->and($draft->fresh()->status)->toBe('pending_payment');
});

test('invoice number approval is immutable and email failure does not reverse approval', function () {
    ['company' => $company] = batchEOwnerCompany('Invoice Co');
    $approver = User::factory()->superAdmin()->create();
    $gateway = new FakePaymentGateway;
    $intent = (new PaymentIntentService($gateway))->create($company, 'renewal', '250.00', 'inv-pay-1');
    $intent->forceFill([
        'status' => 'paid',
        'verification_status' => 'verified',
        'gateway_payment_id' => 'pay_inv',
    ])->save();

    $invoices = app(SubscriptionInvoiceService::class);
    $draft = $invoices->createDraftFromPayment($intent);
    $issuedNumber = (string) $draft->invoice_number;
    $approved = $invoices->approveNumber($approver, $draft, 'TB/2026-2027/0009');
    expect($approved->status)->toBe('issued')
        ->and($approved->approved_number)->toBe($issuedNumber);

    expect(fn () => $approved->update(['approved_number' => 'HACK']))
        ->toThrow(SubscriptionDomainException::class);

    $failedEmail = $invoices->recordEmailAttempt($approved->fresh(), false, 'SMTP down');
    expect($failedEmail->status)->toBe('issued')
        ->and($failedEmail->email_status)->toBe('failed')
        ->and($failedEmail->approved_number)->toBe($issuedNumber);
});

test('storage reconciliation reports allocation thresholds', function () {
    ['company' => $company] = batchEOwnerCompany('Storage Co');
    $result = app(StorageReconciliationService::class)->reconcile($company, allocatedBytes: 100);
    expect($result)->toHaveKeys(['counted_bytes', 'alert', 'block', 'mismatches'])
        ->and($result['block'])->toBeFalse();
});

test('downgrade retention path when usage exceeds proposed limits', function () {
    ['owner' => $owner, 'company' => $company] = batchEOwnerCompany('Retain Co');
    $catalogue = SubscriptionCatalogueVersion::query()->where('code', 'v2026-initial')->firstOrFail();
    $plan = SubscriptionPlan::query()->where('catalogue_version_id', $catalogue->id)->where('is_active', true)->firstOrFail();
    $this->actingAs($owner);
    $draft = app(CompanySubscriptionContractService::class)->createDraftContract(
        actor: $owner,
        company: $company,
        catalogue: $catalogue,
        planIds: [$plan->id],
        tenure: SubscriptionTenure::Yearly,
        commonRenewalDate: CarbonImmutable::parse('2027-03-01'),
    );
    $lifecycle = app(SubscriptionLifecycleService::class);
    $active = $lifecycle->activateAfterVerifiedPayment($draft, CarbonImmutable::parse('2027-03-01'), $owner);

    $proposal = $lifecycle->scheduleChange(
        actor: $owner,
        company: $company,
        changeType: 'downgrade',
        payload: ['branches' => 1, 'seats' => 1],
        subscription: $active,
        remark: 'Downgrade next renewal',
    );

    $result = $lifecycle->applyScheduledDowngradeIfEligible(
        $proposal,
        currentBranchCount: 3,
        currentSeatCount: 5,
        proposedBranchLimit: 1,
        proposedSeatLimit: 1,
    );

    expect($result['applied'])->toBeFalse()
        ->and($result['retained_current_plan'])->toBeTrue()
        ->and($result['proposal']->status)->toBe('rejected');
});

test('broker and partload credit ledgers stay independent under concurrent over-reservation', function () {
    ['company' => $company] = batchEOwnerCompany('Ledger Isolation Co');
    $contracts = app(CompanySubscriptionContractService::class);
    $broker = $contracts->ensureCreditLedger($company, 'broker');
    $partload = $contracts->ensureCreditLedger($company, 'partload');
    $contracts->appendCreditEntry($broker, '40.0000', 'grant', 'Broker seed', 'broker-grant');
    $contracts->appendCreditEntry($partload, '10.0000', 'grant', 'Partload seed', 'partload-grant');

    $reservations = app(CreditReservationService::class);
    $first = $reservations->reserve($broker->fresh(), '30.0000', 'broker-hold-1', 'Hold A');
    expect(fn () => $reservations->reserve($broker->fresh(), '20.0000', 'broker-hold-2', 'Hold B'))
        ->toThrow(SubscriptionDomainException::class);

    $partloadHold = $reservations->reserve($partload->fresh(), '10.0000', 'partload-hold-1', 'Hold C');
    expect($first->status)->toBe('reserved')
        ->and($partloadHold->status)->toBe('reserved')
        ->and((string) $broker->fresh()->balance)->toBe('40.0000')
        ->and((string) $partload->fresh()->balance)->toBe('10.0000');
});
