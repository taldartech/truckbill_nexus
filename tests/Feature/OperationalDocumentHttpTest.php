<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Broker\Delivery\BrokerDeliveryService;
use App\Domains\Document\DocumentParentTypes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerWebSession;
use App\Models\DocumentAttachment;
use App\Models\User;
use App\Support\Files\Scanning\MalwareScanner;
use App\Support\Files\Scanning\PassThroughMalwareScanner;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/../Support/BrokerLorryHireHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    Storage::fake('private_files');
    config([
        'files.disk' => 'private_files',
        'files.scanner' => 'pass',
        'files.max_bytes' => 2 * 1024 * 1024,
    ]);
    app()->bind(MalwareScanner::class, fn () => new PassThroughMalwareScanner);
});

afterEach(function () {
    TenantContextHolder::clear();
});

function operationalDocPdf(string $name = 'pod.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 100, 'application/pdf');
}

/**
 * @param  array{member: User, viewer: User, company: Company, branch: Branch}  $ctx
 */
function operationalDocClaim(array $ctx, string $as = 'member'): void
{
    $user = $as === 'viewer' ? $ctx['viewer'] : $ctx['member'];
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('trip document routes accept trip parent and enforce trip edit permission', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    operationalDocClaim($ctx);
    $this->get(route('branch.documents.index', [
        'parentType' => DocumentParentTypes::TRIP,
        'parentId' => $trip->id,
    ]))->assertOk();

    operationalDocClaim($ctx);
    $this->post(route('branch.documents.store', [
        'parentType' => DocumentParentTypes::TRIP,
        'parentId' => $trip->id,
    ]), [
        'file' => operationalDocPdf('trip-support.pdf'),
        'document_type' => 'supporting',
        'label' => 'Trip support',
    ])->assertRedirect();

    expect(DocumentAttachment::query()
        ->where('parent_type', DocumentParentTypes::TRIP)
        ->where('parent_id', $trip->id)
        ->where('is_current', true)
        ->exists())->toBeTrue();

    operationalDocClaim($ctx);
    $this->get(route('branch.trips.show', $trip))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/trips/show')
            ->has('documentDrawer')
            ->where('documentDrawer.parent.type', DocumentParentTypes::TRIP));
});

test('viewer cannot upload trip documents via forged HTTP', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    operationalDocClaim($ctx, 'viewer');
    $this->get(route('branch.documents.index', [
        'parentType' => DocumentParentTypes::TRIP,
        'parentId' => $trip->id,
    ]))->assertOk();

    operationalDocClaim($ctx, 'viewer');
    $this->post(route('branch.documents.store', [
        'parentType' => DocumentParentTypes::TRIP,
        'parentId' => $trip->id,
    ]), [
        'file' => operationalDocPdf('forged.pdf'),
        'document_type' => 'supporting',
    ])->assertForbidden();

    expect(DocumentAttachment::query()
        ->where('parent_type', DocumentParentTypes::TRIP)
        ->where('parent_id', $trip->id)
        ->count())->toBe(0);
});

test('lorry receipt document drawer is available on show and rejects cross company parent', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);

    operationalDocClaim($ctx);
    $this->get(route('branch.lorry-receipts.show', $lr))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/lorry-receipts/show')
            ->has('documentDrawer')
            ->where('documentDrawer.parent.type', DocumentParentTypes::LORRY_RECEIPT));

    $other = tripWorkspace();
    tripActivate($other['company'], $other['branch']);
    $foreignLr = tripCreateOwnFleetLr($other);

    operationalDocClaim($ctx);
    $this->post(route('branch.documents.store', [
        'parentType' => DocumentParentTypes::LORRY_RECEIPT,
        'parentId' => $foreignLr->id,
    ]), [
        'file' => operationalDocPdf('cross.pdf'),
    ])->assertForbidden();
});

test('broker POD upload via dedicated route and generic document route', function () {
    brokerGrantLorryHirePermissions();
    brokerGrantDeliveryAdjustmentPermissions();

    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hire->lorryReceipt, $ctx);

    app(BrokerDeliveryService::class)->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
        'memo_number' => 'MEMO-POD-HTTP',
    ]);

    $hire->lorryReceipt->load('delivery');
    $delivery = $hire->lorryReceipt->delivery;
    expect($delivery)->not->toBeNull();

    operationalDocClaim($ctx);
    $this->post(route('branch.broker.deliveries.pod', $delivery), [
        'file' => operationalDocPdf('broker-pod.pdf'),
        'label' => 'Signed POD',
    ])->assertRedirect();

    expect(DocumentAttachment::query()
        ->where('parent_type', DocumentParentTypes::BROKER_DELIVERY)
        ->where('parent_id', $delivery->id)
        ->where('document_type', 'pod')
        ->where('is_current', true)
        ->exists())->toBeTrue();

    operationalDocClaim($ctx);
    $this->get(route('branch.documents.index', [
        'parentType' => DocumentParentTypes::BROKER_DELIVERY,
        'parentId' => $delivery->id,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/documents/index')
            ->where('parent.type', DocumentParentTypes::BROKER_DELIVERY)
            ->has('attachments', 1));

    operationalDocClaim($ctx);
    $this->post(route('branch.documents.store', [
        'parentType' => DocumentParentTypes::BROKER_DELIVERY,
        'parentId' => $delivery->id,
    ]), [
        'file' => operationalDocPdf('broker-support.pdf'),
        'document_type' => 'supporting',
        'label' => 'Extra scan',
    ])->assertRedirect();
});

test('unsupported parent type remains 404', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    operationalDocClaim($ctx);
    $this->get(route('branch.documents.index', [
        'parentType' => 'invoice',
        'parentId' => $trip->id,
    ]))->assertNotFound();
});
