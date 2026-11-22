<?php

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Invitation\Enums\InvitationStatus;
use App\Domains\Invitation\Exceptions\InvitationException;
use App\Domains\Invitation\InvitationService;
use App\Domains\Rbac\RoleAssignmentService;
use App\Mail\InvitationMail;
use App\Models\CompanyMembership;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditEntry;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxProcessor;
use App\Support\Outbox\OutboxStatus;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function inviteWorkspace(): CompanyWorkspaceService
{
    return app(CompanyWorkspaceService::class);
}

function invitations(): InvitationService
{
    return app(InvitationService::class);
}

function inviteOwnedCompany(): array
{
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = inviteWorkspace()->createCompany($owner, 'Invite Co');
    $branch = $company->branches()->where('name', 'Head Office')->firstOrFail();

    return compact('owner', 'company', 'branch');
}

test('super admin can create invitation with hashed token and outbox email', function () {
    Mail::fake();
    ['company' => $company, 'branch' => $branch] = inviteOwnedCompany();
    $sa = User::factory()->superAdmin()->create();

    $result = invitations()->create($sa, $company, 'new.member@example.com', $branch);

    $invitation = $result['invitation'];
    $plain = $result['plain_token'];

    expect($invitation->status)->toBe(InvitationStatus::Pending)
        ->and($invitation->token_hash)->toBe(hash('sha256', $plain))
        ->and($invitation->token_hash)->not->toBe($plain)
        ->and(strlen($plain))->toBeGreaterThanOrEqual(64)
        ->and(Invitation::query()->where('token_hash', $plain)->exists())->toBeFalse();

    $outbox = OutboxMessage::query()->where('event_type', InvitationService::OUTBOX_EVENT_TYPE)->sole();
    expect($outbox->event_id)->toBe($invitation->id)
        ->and($outbox->payload['email'])->toBe('new.member@example.com')
        ->and($outbox->payload)->toHaveKey('accept_url')
        ->and($outbox->payload['accept_url'])->toContain($plain);

    expect(AuditEntry::query()
        ->where('subject_type', Invitation::class)
        ->where('action', AuditAction::Created->value)
        ->where('subject_id', $invitation->id)
        ->exists())->toBeTrue();

    // Audit must not contain plaintext token
    $audit = AuditEntry::query()->where('subject_id', $invitation->id)->first();
    expect(json_encode($audit->after))->not->toContain($plain);
});

test('unauthorized users cannot create invitations', function () {
    ['company' => $company] = inviteOwnedCompany();
    $customer = User::factory()->customer()->create();
    $internal = User::factory()->internal()->create();

    expect(fn () => invitations()->create($customer, $company, 'x@example.com'))
        ->toThrow(InvitationException::class);

    expect(fn () => invitations()->create($internal, $company, 'y@example.com'))
        ->toThrow(InvitationException::class);

    $this->actingAs($customer)
        ->post(route('admin.invitations.store', $company), [
            'email' => 'z@example.com',
        ])
        ->assertRedirect();

    expect(Invitation::query()->where('email', 'z@example.com')->exists())->toBeFalse();
});

test('valid invitation is accepted for new customer without granting owner or super admin', function () {
    ['company' => $company, 'branch' => $branch, 'owner' => $owner] = inviteOwnedCompany();
    $sa = User::factory()->superAdmin()->create();
    $result = invitations()->create($sa, $company, 'fresh@example.com', $branch);

    $user = invitations()->accept($result['plain_token'], [
        'name' => 'Fresh Member',
        'password' => 'secure-password-123',
        'password_confirmation' => 'secure-password-123',
    ]);

    expect($user->isCustomer())->toBeTrue()
        ->and($user->isSuperAdmin())->toBeFalse()
        ->and($user->email)->toBe('fresh@example.com')
        ->and(Hash::check('secure-password-123', $user->password))->toBeTrue()
        ->and(app(CompanyAccessService::class)->activeMembership($user, $company))->not->toBeNull()
        ->and($company->fresh()->owner_user_id)->toBe($owner->id)
        ->and($result['invitation']->fresh()->status)->toBe(InvitationStatus::Accepted);
});

test('existing customer identity is reused without password overwrite', function () {
    ['company' => $companyA] = inviteOwnedCompany();
    ['company' => $companyB] = inviteOwnedCompany();

    $existing = User::factory()->customer()->create([
        'email' => 'reuse@example.com',
        'password' => Hash::make('original-password'),
    ]);
    inviteWorkspace()->addMembership($companyA, $existing, 'Existing membership');
    $passwordBefore = $existing->getAuthPassword();
    $membershipCountBefore = CompanyMembership::query()->where('user_id', $existing->id)->count();

    $sa = User::factory()->superAdmin()->create();
    $result = invitations()->create($sa, $companyB, 'reuse@example.com');

    $user = invitations()->accept($result['plain_token']);

    expect($user->id)->toBe($existing->id)
        ->and($user->fresh()->getAuthPassword())->toBe($passwordBefore)
        ->and(CompanyMembership::query()->where('user_id', $existing->id)->count())->toBe($membershipCountBefore + 1)
        ->and(app(CompanyAccessService::class)->activeMembership($existing, $companyA))->not->toBeNull()
        ->and(app(CompanyAccessService::class)->activeMembership($existing, $companyB))->not->toBeNull()
        ->and(User::query()->where('email', 'reuse@example.com')->count())->toBe(1);
});

test('internal identity cannot be reused as customer invitation', function () {
    ['company' => $company] = inviteOwnedCompany();
    $internal = User::factory()->internal()->create(['email' => 'internal@example.com']);
    $sa = User::factory()->superAdmin()->create();

    expect(fn () => invitations()->create($sa, $company, 'internal@example.com'))
        ->toThrow(InvitationException::class);
});

test('expired revoked accepted and invalid tokens are rejected', function () {
    ['company' => $company] = inviteOwnedCompany();
    $sa = User::factory()->superAdmin()->create();

    $expired = invitations()->create($sa, $company, 'expired@example.com');
    $expired['invitation']->forceFill([
        'expires_at' => now()->subMinute(),
    ])->save();

    expect(fn () => invitations()->accept($expired['plain_token'], [
        'name' => 'X',
        'password' => 'password-12345',
        'password_confirmation' => 'password-12345',
    ]))->toThrow(InvitationException::class);

    $revoked = invitations()->create($sa, $company, 'revoked@example.com');
    invitations()->revoke($sa, $revoked['invitation'], 'Withdrawn by admin');

    expect(fn () => invitations()->accept($revoked['plain_token'], [
        'name' => 'Y',
        'password' => 'password-12345',
        'password_confirmation' => 'password-12345',
    ]))->toThrow(InvitationException::class);

    $once = invitations()->create($sa, $company, 'once@example.com');
    invitations()->accept($once['plain_token'], [
        'name' => 'Once',
        'password' => 'password-12345',
        'password_confirmation' => 'password-12345',
    ]);

    expect(fn () => invitations()->accept($once['plain_token'], [
        'name' => 'Once',
        'password' => 'password-12345',
        'password_confirmation' => 'password-12345',
    ]))->toThrow(InvitationException::class);

    expect(fn () => invitations()->accept('not-a-real-token', [
        'name' => 'Nope',
        'password' => 'password-12345',
        'password_confirmation' => 'password-12345',
    ]))->toThrow(InvitationException::class);
});

test('concurrent acceptance results in exactly one membership', function () {
    ['company' => $company] = inviteOwnedCompany();
    $sa = User::factory()->superAdmin()->create();
    $result = invitations()->create($sa, $company, 'race@example.com');
    $token = $result['plain_token'];

    $credentials = [
        'name' => 'Race User',
        'password' => 'password-12345',
        'password_confirmation' => 'password-12345',
    ];

    $first = invitations()->accept($token, $credentials);

    expect(fn () => invitations()->accept($token, $credentials))
        ->toThrow(InvitationException::class);

    expect(User::query()->where('email', 'race@example.com')->count())->toBe(1)
        ->and(CompanyMembership::query()->where('user_id', $first->id)->where('company_id', $company->id)->count())->toBe(1)
        ->and($result['invitation']->fresh()->status)->toBe(InvitationStatus::Accepted);
});

test('outbox retry does not duplicate invitation email delivery', function () {
    Mail::fake();
    Queue::fake();

    ['company' => $company, 'owner' => $owner] = inviteOwnedCompany();
    $sa = User::factory()->superAdmin()->create();
    invitations()->create($sa, $company, 'mail@example.com');

    $message = OutboxMessage::query()->where('event_type', InvitationService::OUTBOX_EVENT_TYPE)->sole();
    $message->forceFill(['status' => OutboxStatus::Processing->value])->save();

    TenantContextHolder::inject(new TenantContext(
        companyId: $company->id,
        branchId: null,
        ownerId: (string) $owner->id,
        isInternal: true,
    ));

    $processor = app(OutboxProcessor::class);
    $processor->process($message->fresh());

    $message->forceFill(['status' => OutboxStatus::Processing->value])->save();
    $processor->process($message->fresh());

    Mail::assertSent(InvitationMail::class, 1);
    expect($message->fresh()->status)->toBe(OutboxStatus::Published->value);
});

test('failed acceptance does not partially create membership', function () {
    ['company' => $company] = inviteOwnedCompany();
    $sa = User::factory()->superAdmin()->create();
    $result = invitations()->create($sa, $company, 'partial@example.com');

    expect(fn () => invitations()->accept($result['plain_token'], [
        'name' => 'Partial',
        'password' => 'password-12345',
        'password_confirmation' => 'mismatch',
    ]))->toThrow(InvitationException::class);

    expect(User::query()->where('email', 'partial@example.com')->exists())->toBeFalse()
        ->and(CompanyMembership::query()->where('company_id', $company->id)->count())->toBe(1) // owner only
        ->and($result['invitation']->fresh()->status)->toBe(InvitationStatus::Pending);
});

test('invitation cannot cross company boundaries via client company_id', function () {
    ['company' => $companyA] = inviteOwnedCompany();
    ['company' => $companyB] = inviteOwnedCompany();
    $sa = User::factory()->superAdmin()->create();

    $result = invitations()->create($sa, $companyA, 'bound@example.com');

    $user = invitations()->accept($result['plain_token'], [
        'name' => 'Bound',
        'password' => 'password-12345',
        'password_confirmation' => 'password-12345',
    ]);

    expect(app(CompanyAccessService::class)->activeMembership($user, $companyA))->not->toBeNull()
        ->and(app(CompanyAccessService::class)->activeMembership($user, $companyB))->toBeNull();
});

test('super admin can create internal users and non-super-admin cannot', function () {
    $sa = User::factory()->superAdmin()->create();
    $customer = User::factory()->customer()->create();
    $role = Role::query()->where('slug', 'internal.admin')->firstOrFail();

    $created = app(RoleAssignmentService::class)->createInternalUser($sa, [
        'name' => 'New Internal',
        'email' => 'new.internal@example.com',
        'password' => 'password-12345',
    ], $role);

    expect($created->isInternal())->toBeTrue()
        ->and($created->isSuperAdmin())->toBeFalse();

    $this->actingAs($customer)
        ->post(route('admin.internal-users.store'), [
            'name' => 'Nope',
            'email' => 'nope.internal@example.com',
            'password' => 'password-12345',
            'password_confirmation' => 'password-12345',
        ])
        ->assertSessionHasErrors();

    expect(User::query()->where('email', 'nope.internal@example.com')->exists())->toBeFalse();
});

test('http invitation create and accept flows work', function () {
    ['company' => $company] = inviteOwnedCompany();
    $sa = User::factory()->superAdmin()->create();

    $this->actingAs($sa)
        ->post(route('admin.invitations.store', $company), [
            'email' => 'http@example.com',
        ])
        ->assertRedirect();

    $invitation = Invitation::query()->where('email', 'http@example.com')->sole();
    $outbox = OutboxMessage::query()->where('event_id', $invitation->id)->sole();
    $url = $outbox->payload['accept_url'];
    $token = basename(parse_url($url, PHP_URL_PATH));

    $this->get(route('invitations.accept', $token))->assertOk();

    $this->post(route('invitations.accept.store', $token), [
        'name' => 'Http User',
        'password' => 'password-12345',
        'password_confirmation' => 'password-12345',
    ])->assertRedirect(route('login'));

    expect(User::query()->where('email', 'http@example.com')->exists())->toBeTrue();
});

test('registration remains disabled', function () {
    $this->post('/register', [
        'name' => 'Nope',
        'email' => 'nope@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});
