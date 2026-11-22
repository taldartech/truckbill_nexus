<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Identity\IdentityLifecycleService;
use App\Models\CustomerWebSession;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    TenantContextHolder::clear();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function loginUser(User $user, string $password = 'password'): string
{
    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);

    $response->assertRedirect();
    test()->assertAuthenticatedAs($user);

    // Persist encrypted cookies for subsequent HTTP requests (Laravel test client does not auto-jar them).
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    return session()->getId();
}

function insertSessionRow(string $id, int $userId): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => time(),
    ]);
}

test('customer can log in and first session is accepted', function () {
    $user = User::factory()->customer()->create();

    $sessionId = loginUser($user);

    expect(CustomerWebSession::query()->where('user_id', $user->id)->value('session_id'))->toBe($sessionId);

    $this->get(route('dashboard'))->assertOk();
});

test('customer second successful login invalidates the first session', function () {
    $user = User::factory()->customer()->create();
    $service = app(WebSessionService::class);

    insertSessionRow('customer-session-one', $user->id);
    $service->registerAuthenticatedSession($user, 'customer-session-one');

    $secondSessionId = loginUser($user);

    expect($secondSessionId)->not->toBe('customer-session-one')
        ->and(CustomerWebSession::query()->where('user_id', $user->id)->value('session_id'))->toBe($secondSessionId)
        ->and(DB::table('sessions')->where('id', 'customer-session-one')->exists())->toBeFalse()
        ->and($service->isCustomerSessionValid($user, 'customer-session-one'))->toBeFalse()
        ->and($service->isCustomerSessionValid($user, $secondSessionId))->toBeTrue();

    $this->get(route('dashboard'))->assertOk();
});

test('failed customer login does not invalidate the existing session', function () {
    $user = User::factory()->customer()->create();
    $sessionId = loginUser($user);
    expect(CustomerWebSession::query()->where('user_id', $user->id)->value('session_id'))->toBe($sessionId);

    // Logout current browser (clears matching registry), then restore the prior active reference
    // to simulate another device still holding the valid Customer session.
    $this->post(route('logout'));
    CustomerWebSession::query()->updateOrCreate(
        ['user_id' => $user->id],
        ['session_id' => $sessionId],
    );

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
    expect(CustomerWebSession::query()->where('user_id', $user->id)->value('session_id'))->toBe($sessionId);
});

test('old customer session logout cannot invalidate a newer session', function () {
    $user = User::factory()->customer()->create();
    $service = app(WebSessionService::class);

    $service->registerAuthenticatedSession($user, 'older-customer-session');
    $newer = loginUser($user);

    expect(CustomerWebSession::query()->where('user_id', $user->id)->value('session_id'))->toBe($newer);

    $service->handleLogout($user, 'older-customer-session');

    expect(CustomerWebSession::query()->where('user_id', $user->id)->value('session_id'))->toBe($newer);
});

test('customer single-session policy applies across company memberships', function () {
    $user = User::factory()->customer()->create();
    $companyA = app(CompanyWorkspaceService::class)->createCompany($user, 'A');
    $companyB = app(CompanyWorkspaceService::class)->createCompany(
        User::factory()->customer()->create(),
        'B',
    );
    app(CompanyWorkspaceService::class)->addMembership($companyB, $user, 'Joined B');

    $service = app(WebSessionService::class);
    $service->registerAuthenticatedSession($user, 'membership-session-a');

    $second = loginUser($user);

    expect(CustomerWebSession::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($service->isCustomerSessionValid($user, 'membership-session-a'))->toBeFalse()
        ->and($service->isCustomerSessionValid($user, $second))->toBeTrue()
        ->and($companyA->id)->not->toBe($companyB->id);
});

test('missing customer session registry fails closed', function () {
    $user = User::factory()->customer()->create();
    $sessionId = loginUser($user);

    CustomerWebSession::query()->where('user_id', $user->id)->delete();

    expect(app(WebSessionService::class)->isCustomerSessionValid($user, $sessionId))->toBeFalse();

    // Disable test-only claim so missing registry matches production fail-closed behaviour.
    config(['auth.customer_session_test_claim' => false]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('internal users may hold multiple concurrent web sessions', function () {
    $user = User::factory()->internal()->create();

    insertSessionRow('existing-internal-session', $user->id);

    $sessionId = loginUser($user);

    expect(CustomerWebSession::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'existing-internal-session')->exists())->toBeTrue()
        ->and($sessionId)->not->toBe('existing-internal-session');
});

test('logging out one internal session does not invalidate another', function () {
    $user = User::factory()->internal()->create();
    loginUser($user);

    insertSessionRow('other-internal-session', $user->id);

    $this->post(route('logout'))->assertRedirect(route('home'));

    expect(DB::table('sessions')->where('id', 'other-internal-session')->exists())->toBeTrue();
});

test('super admin follows internal multi-session behaviour', function () {
    $user = User::factory()->superAdmin()->create();

    insertSessionRow('existing-sa-session', $user->id);

    loginUser($user);

    expect(DB::table('sessions')->where('id', 'existing-sa-session')->exists())->toBeTrue()
        ->and(CustomerWebSession::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('password change revokes other sessions but keeps current', function () {
    $user = User::factory()->customer()->create();
    $current = loginUser($user);

    insertSessionRow('other-customer-device', $user->id);

    $this->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertRedirect();

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'other-customer-device')->exists())->toBeFalse()
        ->and(CustomerWebSession::query()->where('user_id', $user->id)->value('session_id'))->toBe($current)
        ->and(AuditEntry::query()->where('remark', 'Password changed; other web sessions revoked')->exists())->toBeTrue();
});

test('failed password change does not revoke sessions', function () {
    $user = User::factory()->customer()->create();
    $sessionId = loginUser($user);

    insertSessionRow('other-session', $user->id);

    $this->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertSessionHasErrors('current_password');

    expect(DB::table('sessions')->where('id', 'other-session')->exists())->toBeTrue()
        ->and(CustomerWebSession::query()->where('user_id', $user->id)->value('session_id'))->toBe($sessionId);
});

test('successful password reset revokes all sessions', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
    Notification::fake();

    $user = User::factory()->customer()->create();

    insertSessionRow('pre-reset-session', $user->id);
    CustomerWebSession::query()->updateOrCreate(
        ['user_id' => $user->id],
        ['session_id' => 'pre-reset-session'],
    );

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'reset-password-123',
            'password_confirmation' => 'reset-password-123',
        ])->assertRedirect(route('login'));

        expect(DB::table('sessions')->where('id', 'pre-reset-session')->exists())->toBeFalse()
            ->and(CustomerWebSession::query()->where('user_id', $user->id)->exists())->toBeFalse();

        return true;
    });
});

test('failed password reset does not revoke sessions', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    $user = User::factory()->customer()->create();

    insertSessionRow('keep-on-failed-reset', $user->id);
    CustomerWebSession::query()->updateOrCreate(
        ['user_id' => $user->id],
        ['session_id' => 'keep-on-failed-reset'],
    );

    $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'reset-password-123',
        'password_confirmation' => 'reset-password-123',
    ])->assertSessionHasErrors('email');

    expect(DB::table('sessions')->where('id', 'keep-on-failed-reset')->exists())->toBeTrue()
        ->and(CustomerWebSession::query()->where('user_id', $user->id)->value('session_id'))->toBe('keep-on-failed-reset');
});

test('explicit security revocation invalidates customer and internal sessions', function () {
    $customer = User::factory()->customer()->create();
    $internal = User::factory()->internal()->create();

    insertSessionRow('customer-revocation-session', $customer->id);
    insertSessionRow('internal-revocation-session', $internal->id);
    insertSessionRow('internal-other', $internal->id);

    CustomerWebSession::query()->updateOrCreate(
        ['user_id' => $customer->id],
        ['session_id' => 'customer-revocation-session'],
    );

    app(WebSessionService::class)->revokeAllSessionsForSecurityEvent(
        $customer,
        'Security revocation test for customer',
    );
    app(WebSessionService::class)->revokeAllSessionsForSecurityEvent(
        $internal,
        'Security revocation test for internal',
    );

    expect(CustomerWebSession::query()->where('user_id', $customer->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $customer->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $internal->id)->exists())->toBeFalse();
});

test('deactivated and soft-deleted identities cannot authenticate', function () {
    $internalContext = new TenantContext(
        companyId: null,
        branchId: null,
        ownerId: null,
        isInternal: true,
    );
    TenantContextHolder::inject($internalContext);

    $deactivated = User::factory()->customer()->create();
    app(IdentityLifecycleService::class)->deactivate($deactivated, 'Deactivate for auth test');

    $this->post(route('login.store'), [
        'email' => $deactivated->email,
        'password' => 'password',
    ]);
    $this->assertGuest();

    TenantContextHolder::inject($internalContext);

    $deleted = User::factory()->customer()->create();
    app(IdentityLifecycleService::class)->softDelete($deleted, 'Soft delete for auth test');

    $this->post(route('login.store'), [
        'email' => $deleted->email,
        'password' => 'password',
    ]);
    $this->assertGuest();
});

test('session id is regenerated after login', function () {
    $user = User::factory()->customer()->create();

    $this->get(route('login'))->assertOk();
    $before = session()->getId();

    $after = loginUser($user);

    expect($after)->not->toBe($before);
});

test('logout invalidates the current session and csrf token', function () {
    $user = User::factory()->customer()->create();
    $sessionId = loginUser($user);
    $tokenBefore = session()->token();

    $this->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();

    expect(session()->token())->not->toBe($tokenBefore)
        ->and(CustomerWebSession::query()->where('user_id', $user->id)->where('session_id', $sessionId)->exists())->toBeFalse();
});

test('registration remains disabled and session cookie flags remain secure defaults', function () {
    $this->post('/register', [
        'name' => 'Nope',
        'email' => 'nope@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});

test('customer session middleware does not break guest routes', function () {
    $this->get(route('login'))->assertOk();
    $this->get(route('home'))->assertOk();
});

test('customer session registry lock serializes replacement', function () {
    $user = User::factory()->customer()->create();
    $service = app(WebSessionService::class);

    $service->registerAuthenticatedSession($user, 'session-a');
    $service->registerAuthenticatedSession($user, 'session-b');

    expect(CustomerWebSession::query()->where('user_id', $user->id)->value('session_id'))->toBe('session-b')
        ->and($service->isCustomerSessionValid($user, 'session-a'))->toBeFalse()
        ->and($service->isCustomerSessionValid($user, 'session-b'))->toBeTrue();
});

test('superseded customer session cannot access authenticated routes', function () {
    $user = User::factory()->customer()->create();
    loginUser($user);

    CustomerWebSession::query()->where('user_id', $user->id)->update([
        'session_id' => 'some-other-active-session',
    ]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});
