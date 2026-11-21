<?php

use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditWriter;
use App\Support\Audit\Exceptions\AuditImmutabilityException;
use App\Support\Audit\Exceptions\AuditRemarkRequiredException;
use App\Support\Audit\Harness\AuditHarnessRecord;
use App\Support\Audit\Harness\AuditHarnessService;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;

beforeEach(function () {
    TenantContextHolder::clear();
    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-audit',
        branchId: 'br-audit',
        ownerId: 'own-audit',
        isInternal: false,
        isOwnerScope: false,
    ));
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('successful creation writes exactly one audit record without remark', function () {
    $service = new AuditHarnessService;
    $record = $service->create(['name' => 'Alpha']);

    expect(AuditHarnessRecord::query()->count())->toBe(1);
    expect(AuditEntry::query()->count())->toBe(1);

    $entry = AuditEntry::query()->first();
    expect($entry->action)->toBe(AuditAction::Created->value);
    expect($entry->subject_id)->toBe((string) $record->id);
    expect($entry->remark)->toBeNull();
    expect($entry->company_id)->toBe('co-audit');
    expect($entry->branch_id)->toBe('br-audit');
});

test('successful update writes before and after change information', function () {
    $service = new AuditHarnessService;
    $record = $service->create(['name' => 'Before']);
    $service->update($record, ['name' => 'After'], 'Corrected name spelling');

    $entry = AuditEntry::query()->where('action', AuditAction::Updated->value)->sole();
    expect($entry->before['name'])->toBe('Before');
    expect($entry->after['name'])->toBe('After');
    expect($entry->remark)->toBe('Corrected name spelling');
});

test('update without a remark is rejected', function () {
    $service = new AuditHarnessService;
    $record = $service->create(['name' => 'Item']);

    expect(fn () => $service->update($record, ['name' => 'Changed'], ''))
        ->toThrow(AuditRemarkRequiredException::class);

    expect($record->refresh()->name)->toBe('Item');
    expect(AuditEntry::query()->where('action', AuditAction::Updated->value)->count())->toBe(0);
});

test('successful deletion writes an audit record and removes the harness row', function () {
    $service = new AuditHarnessService;
    $record = $service->create(['name' => 'Disposable']);
    $id = $record->id;

    $service->delete($record, 'Removing harness fixture');

    expect(AuditHarnessRecord::query()->find($id))->toBeNull();
    $entry = AuditEntry::query()->where('action', AuditAction::Deleted->value)->sole();
    expect($entry->before['name'])->toBe('Disposable');
    expect($entry->after)->toBeNull();
    expect($entry->remark)->toBe('Removing harness fixture');
});

test('delete without a remark is rejected', function () {
    $service = new AuditHarnessService;
    $record = $service->create(['name' => 'Keep']);

    expect(fn () => $service->delete($record, '   '))
        ->toThrow(AuditRemarkRequiredException::class);

    expect(AuditHarnessRecord::query()->whereKey($record->id)->exists())->toBeTrue();
    expect(AuditEntry::query()->where('action', AuditAction::Deleted->value)->count())->toBe(0);
});

test('cancellation requires reason and applicable date time', function () {
    $service = new AuditHarnessService;
    $record = $service->create(['name' => 'Cancellable']);

    expect(fn () => (new AuditWriter)->write(
        action: AuditAction::Cancelled,
        subjectType: AuditHarnessRecord::class,
        subjectId: $record->id,
        remark: 'Cancel request',
        cancellationReason: '',
        cancellationAt: now(),
    ))->toThrow(AuditRemarkRequiredException::class);

    expect(fn () => (new AuditWriter)->write(
        action: AuditAction::Cancelled,
        subjectType: AuditHarnessRecord::class,
        subjectId: $record->id,
        remark: 'Cancel request',
        cancellationReason: 'Customer withdrew',
        cancellationAt: null,
    ))->toThrow(InvalidArgumentException::class);

    $cancelled = $service->cancel(
        $record,
        'Cancel harness fixture',
        'Customer withdrew',
        now(),
    );

    expect($cancelled->status)->toBe('cancelled');
    $entry = AuditEntry::query()->where('action', AuditAction::Cancelled->value)->sole();
    expect($entry->cancellation_reason)->toBe('Customer withdrew');
    expect($entry->cancellation_at)->not->toBeNull();
    expect($entry->remark)->toBe('Cancel harness fixture');
});

test('audit records cannot be updated or deleted through the application API', function () {
    $service = new AuditHarnessService;
    $service->create(['name' => 'Immutable']);
    $entry = AuditEntry::query()->first();

    expect(fn () => $entry->forceFill(['remark' => 'tamper'])->save())
        ->toThrow(AuditImmutabilityException::class);

    expect(fn () => $entry->delete())
        ->toThrow(AuditImmutabilityException::class);

    expect(AuditEntry::query()->count())->toBe(1);
    expect($entry->fresh()->remark)->toBeNull();
});

test('business mutation failure results in zero audit records', function () {
    $tx = new AuditedTransaction;
    $writer = new AuditWriter;

    expect(fn () => $tx->run(function () {
        throw new RuntimeException('Business mutation failed before persist.');
    }))->toThrow(RuntimeException::class);

    expect(AuditEntry::query()->count())->toBe(0);
    expect(AuditHarnessRecord::query()->count())->toBe(0);

    // Also: mutate fails after insert attempt aborted by exception before audit
    expect(fn () => $tx->run(function () {
        AuditHarnessRecord::query()->create(['name' => 'WillRollback']);
        throw new RuntimeException('Failed after insert, before audit.');
    }))->toThrow(RuntimeException::class);

    expect(AuditHarnessRecord::query()->count())->toBe(0);
    expect(AuditEntry::query()->count())->toBe(0);
});

test('audit writer failure rolls back the business mutation', function () {
    $service = new AuditHarnessService;
    $record = $service->create(['name' => 'Stable']);
    $original = $record->name;

    expect(fn () => $service->update($record, ['name' => 'ShouldNotPersist'], ''))
        ->toThrow(AuditRemarkRequiredException::class);

    expect($record->refresh()->name)->toBe($original);
    expect(AuditEntry::query()->where('action', AuditAction::Updated->value)->count())->toBe(0);
});

test('explicit transaction abort after audit write rolls back mutation and audit', function () {
    $service = new AuditHarnessService;
    $record = $service->create(['name' => 'AbortMe']);

    expect(fn () => $service->updateThenFailAudit($record, 'Attempted update'))
        ->toThrow(RuntimeException::class, 'Forced audit-path failure after mutation.');

    expect($record->refresh()->name)->toBe('AbortMe');
    expect(AuditEntry::query()->where('action', AuditAction::Updated->value)->count())->toBe(0);
    expect(AuditEntry::query()->where('action', AuditAction::Created->value)->count())->toBe(1);
});

test('missing tenant context fails closed', function () {
    TenantContextHolder::clear();
    $service = new AuditHarnessService;

    expect(fn () => $service->create(['name' => 'NoTenant']))
        ->toThrow(RuntimeException::class);

    expect(AuditHarnessRecord::query()->count())->toBe(0);
    expect(AuditEntry::query()->count())->toBe(0);
});

test('audit records contain trusted tenant context not spoofed request identifiers', function () {
    $service = new AuditHarnessService;
    $writer = new AuditWriter;

    expect(fn () => $writer->assertNoClientTenantFields(['company_id' => 'spoofed']))
        ->toThrow(RuntimeException::class);

    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-trusted',
        branchId: 'br-trusted',
        ownerId: 'own-trusted',
        isInternal: true,
        isOwnerScope: true,
    ));

    $service->create(['name' => 'Trusted']);
    $entry = AuditEntry::query()->latest('id')->first();

    expect($entry->company_id)->toBe('co-trusted');
    expect($entry->branch_id)->toBe('br-trusted');
    expect($entry->owner_id)->toBe('own-trusted');
    expect($entry->is_internal)->toBeTrue();
    expect($entry->is_owner_scope)->toBeTrue();
});

test('internal team and owner context are represented distinctly', function () {
    $writer = new AuditWriter;

    TenantContextHolder::inject(new TenantContext(
        companyId: null,
        branchId: null,
        ownerId: null,
        isInternal: true,
        isOwnerScope: false,
    ));

    $writer->write(
        action: AuditAction::Created,
        subjectType: 'Harness/InternalNote',
        subjectId: '1',
        after: ['ok' => true],
    );

    TenantContextHolder::inject(new TenantContext(
        companyId: 'co-1',
        branchId: null,
        ownerId: 'own-1',
        isInternal: false,
        isOwnerScope: true,
    ));

    $writer->write(
        action: AuditAction::Created,
        subjectType: 'Harness/OwnerNote',
        subjectId: '2',
        after: ['ok' => true],
    );

    $internal = AuditEntry::query()->where('subject_type', 'Harness/InternalNote')->sole();
    $owner = AuditEntry::query()->where('subject_type', 'Harness/OwnerNote')->sole();

    expect($internal->is_internal)->toBeTrue();
    expect($internal->is_owner_scope)->toBeFalse();
    expect($owner->is_internal)->toBeFalse();
    expect($owner->is_owner_scope)->toBeTrue();
    expect($owner->owner_id)->toBe('own-1');
});

test('sensitive fields are redacted from audit payloads', function () {
    $service = new AuditHarnessService;
    $service->create([
        'name' => 'Secretive',
        'secret_token' => 'super-secret-value',
    ]);

    $entry = AuditEntry::query()->first();
    expect($entry->after['secret_token'])->toBe('[redacted]');
    expect($entry->after['name'])->toBe('Secretive');
});

test('two separate mutations produce separate audit records in order', function () {
    $service = new AuditHarnessService;
    $first = $service->create(['name' => 'First']);
    $second = $service->create(['name' => 'Second']);

    $entries = AuditEntry::query()->orderBy('id')->get();
    expect($entries)->toHaveCount(2);
    expect($entries[0]->subject_id)->toBe((string) $first->id);
    expect($entries[1]->subject_id)->toBe((string) $second->id);
    expect($entries[0]->id)->toBeLessThan($entries[1]->id);
});

test('authenticated user is recorded as actor when present', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    (new AuditHarnessService)->create(['name' => 'WithActor']);
    $entry = AuditEntry::query()->first();

    expect($entry->actor_type)->toBe('user');
    expect($entry->actor_id)->toBe((string) $user->id);
});

test('system actor is used when no authenticated user is present', function () {
    (new AuditHarnessService)->create(['name' => 'SystemActor']);
    $entry = AuditEntry::query()->first();

    expect($entry->actor_type)->toBe('system');
    expect($entry->actor_id)->toBe('phase1-system');
});
