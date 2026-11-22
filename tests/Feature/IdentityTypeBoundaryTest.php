<?php

use App\Domains\Identity\Enums\IdentityType;
use App\Domains\Identity\Enums\InternalRole;
use App\Domains\Identity\Exceptions\IdentityHardDeleteProhibitedException;
use App\Domains\Identity\Exceptions\IdentityRestoreProhibitedException;
use App\Domains\Identity\Exceptions\IdentityTypeBoundaryException;
use App\Domains\Identity\Exceptions\LastSuperAdminProtectedException;
use App\Domains\Identity\IdentityLifecycleService;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditEntry;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    TenantContextHolder::clear();
    TenantContextHolder::inject(new TenantContext(
        companyId: null,
        branchId: null,
        ownerId: null,
        isInternal: true,
        isOwnerScope: false,
    ));
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('customer identity persists with customer type and no internal role', function () {
    $user = User::factory()->customer()->create();

    expect($user->identity_type)->toBe(IdentityType::Customer)
        ->and($user->internal_role)->toBeNull()
        ->and($user->isCustomer())->toBeTrue()
        ->and($user->isInternal())->toBeFalse()
        ->and($user->isActive())->toBeTrue();
});

test('internal identity persists with internal type and required role', function () {
    $user = User::factory()->internal(InternalRole::Admin)->create();

    expect($user->identity_type)->toBe(IdentityType::Internal)
        ->and($user->internal_role)->toBe(InternalRole::Admin)
        ->and($user->isInternal())->toBeTrue()
        ->and($user->isCustomer())->toBeFalse();
});

test('identity type enum casts serialize correctly', function () {
    $user = User::factory()->superAdmin()->create();

    $fresh = User::query()->findOrFail($user->id);

    expect($fresh->identity_type)->toBeInstanceOf(IdentityType::class)
        ->and($fresh->identity_type->value)->toBe('internal')
        ->and($fresh->internal_role)->toBe(InternalRole::SuperAdmin);
});

test('null identity type cannot be persisted', function () {
    $user = User::factory()->customer()->make();
    $user->identity_type = null;

    $user->save();
})->throws(IdentityTypeBoundaryException::class);

test('customer cannot be assigned an internal role', function () {
    $user = User::factory()->customer()->make();
    $user->internal_role = InternalRole::Admin;

    $user->save();
})->throws(IdentityTypeBoundaryException::class);

test('internal identity requires an internal role', function () {
    $user = User::factory()->internal()->make();
    $user->internal_role = null;

    $user->save();
})->throws(IdentityTypeBoundaryException::class);

test('database rejects invalid identity type values', function () {
    expect(fn () => DB::table('users')->insert([
        'name' => 'Invalid',
        'email' => 'invalid-type-'.uniqid('', true).'@example.com',
        'password' => Hash::make('password'),
        'identity_type' => 'both',
        'internal_role' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('database rejects customer rows with internal roles', function () {
    expect(fn () => DB::table('users')->insert([
        'name' => 'Invalid Customer Role',
        'email' => 'invalid-customer-role-'.uniqid('', true).'@example.com',
        'password' => Hash::make('password'),
        'identity_type' => 'customer',
        'internal_role' => 'admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('scopes do not mix identity domains', function () {
    User::factory()->customer()->create(['email' => 'scope-customer@example.com']);
    User::factory()->internal()->create(['email' => 'scope-internal@example.com']);

    expect(User::query()->customer()->pluck('email')->all())
        ->toContain('scope-customer@example.com')
        ->not->toContain('scope-internal@example.com');

    expect(User::query()->internal()->pluck('email')->all())
        ->toContain('scope-internal@example.com')
        ->not->toContain('scope-customer@example.com');
});

test('deactivated identity state is represented correctly', function () {
    $user = User::factory()->customer()->deactivated()->create();

    expect($user->isDeactivated())->toBeTrue()
        ->and($user->isActive())->toBeFalse();

    expect(User::query()->deactivated()->whereKey($user)->exists())->toBeTrue()
        ->and(User::query()->active()->whereKey($user)->exists())->toBeFalse();
});

test('soft delete hides identity without hard delete and restore is prohibited', function () {
    $user = User::factory()->customer()->create();
    $id = $user->id;

    $user->delete();

    expect(User::query()->find($id))->toBeNull()
        ->and(User::withTrashed()->find($id))->not->toBeNull();

    $trashed = User::withTrashed()->findOrFail($id);

    expect(fn () => $trashed->restore())->toThrow(IdentityRestoreProhibitedException::class);
    expect(fn () => $trashed->forceDelete())->toThrow(IdentityHardDeleteProhibitedException::class);
});

test('identity type alone does not grant company owner designation', function () {
    $internal = User::factory()->superAdmin()->create();
    $customer = User::factory()->customer()->create();

    expect($internal->isCompanyOwnerDesignation())->toBeFalse()
        ->and($customer->isCompanyOwnerDesignation())->toBeFalse()
        ->and(Gate::forUser($internal)->allows('actAsCompanyOwner', $internal))->toBeFalse();
});

test('policy denies treating customer as internal administration subject', function () {
    $actor = User::factory()->internal()->create();
    $customer = User::factory()->customer()->create();

    expect(Gate::forUser($actor)->allows('administerInternal', $customer))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('administerCustomer', $customer))->toBeTrue();
});

test('policy denies customer administering internal identities', function () {
    $customer = User::factory()->customer()->create();
    $internal = User::factory()->internal()->create();

    expect(Gate::forUser($customer)->allows('administerInternal', $internal))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('administerCustomer', $internal))->toBeFalse();
});

test('lifecycle create writes audit without remark and persists type', function () {
    $service = new IdentityLifecycleService;

    $user = $service->create([
        'name' => 'Lifecycle Customer',
        'email' => 'lifecycle-customer@example.com',
        'password' => 'password',
        'identity_type' => IdentityType::Customer,
    ]);

    expect($user->isCustomer())->toBeTrue();

    $entry = AuditEntry::query()->where('subject_id', (string) $user->id)->sole();
    expect($entry->action)->toBe(AuditAction::Created->value)
        ->and($entry->remark)->toBeNull();
});

test('lifecycle deactivate requires remark and protects last super admin', function () {
    $service = new IdentityLifecycleService;
    $onlySuperAdmin = User::factory()->superAdmin()->create();

    expect(fn () => $service->deactivate($onlySuperAdmin, 'Attempt demotion path'))
        ->toThrow(LastSuperAdminProtectedException::class);

    $second = User::factory()->superAdmin()->create();
    $service->deactivate($onlySuperAdmin, 'Second Super Admin exists');

    expect($onlySuperAdmin->fresh()->isDeactivated())->toBeTrue();

    $entry = AuditEntry::query()
        ->where('subject_id', (string) $onlySuperAdmin->id)
        ->where('action', AuditAction::Updated->value)
        ->sole();

    expect($entry->remark)->toBe('Second Super Admin exists');

    expect(fn () => $service->deactivate($second, 'Would leave zero Super Admins'))
        ->toThrow(LastSuperAdminProtectedException::class);
});

test('lifecycle demotion of last super admin is blocked', function () {
    $service = new IdentityLifecycleService;
    $only = User::factory()->superAdmin()->create();

    expect(fn () => $service->changeInternalRole($only, InternalRole::Admin, 'Demote last SA'))
        ->toThrow(LastSuperAdminProtectedException::class);
});

test('existing identity password is not overwritten by lifecycle helper', function () {
    $service = new IdentityLifecycleService;
    $user = User::factory()->customer()->create([
        'password' => 'original-password',
    ]);
    $hash = $user->getAuthPassword();

    expect(fn () => $service->updateWithoutOverwritingCredentials($user, [
        'password' => 'hijacked-password',
    ]))->toThrow(IdentityTypeBoundaryException::class);

    $service->updateWithoutOverwritingCredentials($user, [
        'name' => 'Renamed Without Password Change',
    ]);

    expect($user->fresh()->getAuthPassword())->toBe($hash)
        ->and(Hash::check('original-password', $user->fresh()->getAuthPassword()))->toBeTrue();
});

test('registration remains disabled', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Nope',
        'email' => 'nope@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});

test('factory default users authenticate as before', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});
