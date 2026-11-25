<?php

use App\Domains\Broker\Enums\BookBalanceEntryType;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\LorryHire\LorryHireCancellationService;
use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Exceptions\LorryReceiptConflictException;
use App\Domains\Consignment\Exceptions\LorryReceiptDomainException;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Rbac\PermissionCodes;
use App\Models\LorryHire;
use App\Models\TransporterBookBalanceEntry;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    brokerGrantLorryHirePermissions();
    foreach ([PermissionCodes::LR_CANCEL, PermissionCodes::LR_DELETE] as $code) {
        tripGrant('customer.admin', $code);
    }
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('cancel with settle yes creates book balance entry and marks hire cancelled', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $service = app(LorryHireCancellationService::class);

    $outstanding = (string) $hire->fresh()->outstanding_amount;

    $cancelled = $service->cancel($hire, $ctx['member'], [
        'reason' => 'Transporter unavailable',
        'settle_outstanding' => true,
    ]);

    expect($cancelled->status)->toBe(LorryHireStatus::Cancelled)
        ->and($cancelled->settle_outstanding)->toBeTrue()
        ->and((string) $cancelled->outstanding_amount)->toBe('0.00');

    $entry = TransporterBookBalanceEntry::query()
        ->where('source_type', TransporterBookBalanceService::SOURCE_LORRY_HIRE_CANCEL)
        ->where('source_id', $hire->id)
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->entry_type)->toBe(BookBalanceEntryType::LorryHireCancellationSettlement)
        ->and((string) $entry->amount)->toBe($outstanding);
});

test('cancel with settle no retains outstanding and creates no book entry', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $service = app(LorryHireCancellationService::class);

    $outstanding = (string) $hire->fresh()->outstanding_amount;

    $cancelled = $service->cancel($hire, $ctx['member'], [
        'reason' => 'Load cancelled',
        'settle_outstanding' => false,
    ]);

    expect($cancelled->status)->toBe(LorryHireStatus::Cancelled)
        ->and($cancelled->settle_outstanding)->toBeFalse()
        ->and((string) $cancelled->outstanding_amount)->toBe($outstanding);

    expect(
        TransporterBookBalanceEntry::query()
            ->where('source_type', TransporterBookBalanceService::SOURCE_LORRY_HIRE_CANCEL)
            ->where('source_id', $hire->id)
            ->exists(),
    )->toBeFalse();
});

test('double cancel is rejected', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $service = app(LorryHireCancellationService::class);

    $service->cancel($hire, $ctx['member'], [
        'reason' => 'First cancel',
        'settle_outstanding' => false,
    ]);

    expect(fn () => $service->cancel($hire, $ctx['member'], [
        'reason' => 'Second cancel',
        'settle_outstanding' => false,
    ]))->toThrow(BrokerConflictException::class);
});

test('unauthorized role cannot cancel lorry hire', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $service = app(LorryHireCancellationService::class);

    expect(fn () => $service->cancel($hire, $ctx['viewer'], [
        'reason' => 'Attempt',
        'settle_outstanding' => false,
    ]))->toThrow(BrokerAuthorizationException::class);
});

test('active lorry hire blocks lr cancel', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $lr = $hire->lorryReceipt;

    expect(fn () => app(LorryReceiptService::class)->cancel($lr, $ctx['member'], 'LR cancel attempt'))
        ->toThrow(LorryReceiptConflictException::class);
});

test('active lorry hire blocks lr soft delete', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $lr = $hire->lorryReceipt;

    // Hire creation advances the LR to In-Progress; only Pending LRs may soft-delete.
    expect(fn () => app(LorryReceiptService::class)->softDelete($lr, $ctx['member'], 'Blocked delete'))
        ->toThrow(LorryReceiptDomainException::class, 'Only pending Lorry Receipts can be soft-deleted.');
});

test('after hire cancelled lr cancel is allowed when not delivered', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $lr = $hire->lorryReceipt;

    app(LorryHireCancellationService::class)->cancel($hire, $ctx['member'], [
        'reason' => 'Release LR',
        'settle_outstanding' => false,
    ]);

    $cancelled = app(LorryReceiptService::class)->cancel($lr->fresh(), $ctx['member'], 'LR no longer required');

    expect($cancelled->status)->toBe(LrStatus::Cancelled);
});

test('delivered lr cancel remains blocked after hire cancelled', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $lr = $hire->lorryReceipt;

    app(LorryHireCancellationService::class)->cancel($hire, $ctx['member'], [
        'reason' => 'Hire ended',
        'settle_outstanding' => false,
    ]);

    $lr->forceFill([
        'status' => LrStatus::Delivered,
        'delivered_at' => now(),
    ])->save();

    expect(fn () => app(LorryReceiptService::class)->cancel($lr->fresh(), $ctx['member'], 'Too late'))
        ->toThrow(LorryReceiptDomainException::class);
});

test('cancelled lorry hire soft-deletes when lr soft-deleted', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $lr = $hire->lorryReceipt;

    app(LorryHireCancellationService::class)->cancel($hire, $ctx['member'], [
        'reason' => 'Cleanup',
        'settle_outstanding' => false,
    ]);

    // Soft-delete requires Pending; hire creation advances LR to In-Progress.
    $lr->forceFill(['status' => LrStatus::Pending])->save();

    app(LorryReceiptService::class)->softDelete($lr->fresh(), $ctx['member'], 'Cleanup after hire cancel');

    $trashed = LorryHire::withTrashed()->findOrFail($hire->id);

    expect($trashed->trashed())->toBeTrue()
        ->and($lr->fresh()->trashed())->toBeTrue();
});
