<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Broker\Delivery\BrokerDeliveryService;
use App\Domains\Broker\Enums\BrokerDeliveryStatus;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Document\DocumentParentTypes;
use App\Domains\Document\DocumentTypeCatalog;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Models\CustomerWebSession;
use App\Models\DocumentAttachment;
use App\Models\Party;
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

function contextualDocPdf(string $name = 'doc.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 100, 'application/pdf');
}

/**
 * @param  array<string, mixed>  $ctx
 */
function contextualDocClaim(array $ctx, User $user): void
{
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

/**
 * @param  array<string, mixed>  $ctx
 */
function contextualCustomerParty(array $ctx): Party
{
    return app(PartyService::class)->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: $ctx['branch'],
        attributes: [
            'name' => 'Docs Customer',
            'contact_number' => '9111111199',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        roles: [['role' => PartyRoleType::Customer->value, 'attributes' => []]],
    );
}

test('station show page exposes no document drawer or documents link', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    contextualDocClaim($ctx, $ctx['owner']);
    $this->get(route('branch.stations.show', $ctx['fromStation']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/stations/show')
            ->missing('documentDrawer')
            ->missing('links.documents'));
});

test('new station uploads are rejected while historical station attachments stay listable and downloadable', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $station = $ctx['fromStation'];

    contextualDocClaim($ctx, $ctx['owner']);
    $legacy = app(DocumentAttachmentService::class)->uploadAndAttach(
        $ctx['owner'],
        $ctx['company'],
        $ctx['branch'],
        DocumentParentTypes::COMPANY_RECORD,
        $ctx['company']->id,
        contextualDocPdf('legacy-station.pdf'),
        label: 'Legacy station proof',
    );
    // Simulates an attachment created before stations were retired as a document parent.
    $legacy->forceFill(['parent_type' => DocumentParentTypes::STATION, 'parent_id' => $station->id])->save();

    contextualDocClaim($ctx, $ctx['owner']);
    $this->post(route('branch.documents.store', [
        'parentType' => DocumentParentTypes::STATION,
        'parentId' => $station->id,
    ]), [
        'file' => contextualDocPdf('new-station.pdf'),
        'document_type' => 'supporting',
    ])->assertForbidden();

    expect(DocumentAttachment::query()
        ->where('parent_type', DocumentParentTypes::STATION)
        ->where('parent_id', $station->id)
        ->count())->toBe(1);

    contextualDocClaim($ctx, $ctx['owner']);
    $this->get(route('branch.documents.index', [
        'parentType' => DocumentParentTypes::STATION,
        'parentId' => $station->id,
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/documents/index')
            ->where('retired', true)
            ->where('document_types', [])
            ->where('permissions.can_create', false)
            ->where('permissions.can_edit', false)
            ->where('permissions.can_delete', false)
            ->where('attachments.0.id', $legacy->id)
            ->where('attachments.0.replace_url', null)
            ->where('attachments.0.destroy_url', null));

    contextualDocClaim($ctx, $ctx['owner']);
    $this->get(route('branch.documents.download', $legacy))->assertOk();

    contextualDocClaim($ctx, $ctx['owner']);
    $this->post(route('branch.documents.replace', $legacy), [
        'file' => contextualDocPdf('replacement.pdf'),
    ])->assertForbidden();

    contextualDocClaim($ctx, $ctx['owner']);
    $this->delete(route('branch.documents.destroy', $legacy), ['remark' => 'Try delete'])->assertForbidden();

    expect($legacy->fresh())->not->toBeNull()
        ->and($legacy->fresh()->is_current)->toBeTrue();

    $this->artisan('documents:retired-report')
        ->expectsOutputToContain(DocumentParentTypes::STATION)
        ->assertSuccessful();
});

test('vehicle insurance requires an expiry date while registration certificate does not', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $vehicle = $ctx['vehicle'];
    $storeUrl = route('branch.documents.store', [
        'parentType' => DocumentParentTypes::VEHICLE,
        'parentId' => $vehicle->id,
    ]);

    contextualDocClaim($ctx, $ctx['owner']);
    $this->post($storeUrl, [
        'file' => contextualDocPdf('insurance.pdf'),
        'document_type' => DocumentTypeCatalog::INSURANCE,
        'document_number' => 'POL-1',
    ])->assertSessionHasErrors('expires_on');

    contextualDocClaim($ctx, $ctx['owner']);
    $this->post($storeUrl, [
        'file' => contextualDocPdf('rc.pdf'),
        'document_type' => DocumentTypeCatalog::REGISTRATION_CERTIFICATE,
        'document_number' => 'RC-1',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $expiresOn = now()->addDays(10)->toDateString();
    contextualDocClaim($ctx, $ctx['owner']);
    $this->post($storeUrl, [
        'file' => contextualDocPdf('insurance.pdf'),
        'document_type' => DocumentTypeCatalog::INSURANCE,
        'document_number' => 'POL-2',
        'issued_on' => now()->subYear()->toDateString(),
        'expires_on' => $expiresOn,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $insurance = DocumentAttachment::query()
        ->where('parent_id', $vehicle->id)
        ->where('document_type', DocumentTypeCatalog::INSURANCE)
        ->sole();
    expect($insurance->document_number)->toBe('POL-2')
        ->and($insurance->expires_on?->toDateString())->toBe($expiresOn);

    contextualDocClaim($ctx, $ctx['owner']);
    $this->get(route('branch.vehicles.show', $vehicle))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documentDrawer.title', 'Vehicle Documents')
            ->where('documentDrawer.attachments', fn ($attachments) => collect($attachments)
                ->contains(fn (array $row): bool => $row['document_type'] === DocumentTypeCatalog::INSURANCE
                    && $row['expiry_status']['value'] === 'expiring_soon'
                    && $row['expiry_status']['label'] === 'Expiring soon')));
});

test('party document types follow the active party roles', function () {
    $catalog = new DocumentTypeCatalog;

    $customer = $catalog->partyContext([PartyRoleType::Customer]);
    expect($customer->allowedTypes)->toBe([DocumentTypeCatalog::PAN, DocumentTypeCatalog::GST_REGISTRATION, DocumentTypeCatalog::OTHER_BUSINESS])
        ->and($customer->title)->toBe('Compliance Documents');

    $transporter = $catalog->partyContext([PartyRoleType::Transporter]);
    expect($transporter->allowedTypes)->toContain(DocumentTypeCatalog::TDS_DECLARATION)
        ->and($transporter->allowedTypes)->toContain(DocumentTypeCatalog::GST_REGISTRATION);

    $driver = $catalog->partyContext([PartyRoleType::Driver]);
    expect($driver->allowedTypes)->toBe([DocumentTypeCatalog::PAN, DocumentTypeCatalog::DRIVING_LICENCE, DocumentTypeCatalog::OTHER_BUSINESS])
        ->and($driver->title)->toBe('Driver Documents');

    $vendor = $catalog->partyContext([PartyRoleType::GeneralVendor]);
    expect($vendor->allowedTypes)->toBe([DocumentTypeCatalog::PAN, DocumentTypeCatalog::OTHER_BUSINESS]);
});

test('party upload rejects document types outside the party role catalog', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $customer = contextualCustomerParty($ctx);
    $storeUrl = route('branch.documents.store', [
        'parentType' => DocumentParentTypes::PARTY,
        'parentId' => $customer->id,
    ]);

    contextualDocClaim($ctx, $ctx['owner']);
    $this->post($storeUrl, [
        'file' => contextualDocPdf('dl.pdf'),
        'document_type' => DocumentTypeCatalog::DRIVING_LICENCE,
    ])->assertSessionHasErrors('document_type');

    contextualDocClaim($ctx, $ctx['owner']);
    $this->post($storeUrl, [
        'file' => contextualDocPdf('unknown.pdf'),
        'document_type' => 'made_up_type',
    ])->assertSessionHasErrors('document_type');

    contextualDocClaim($ctx, $ctx['owner']);
    $this->post($storeUrl, [
        'file' => contextualDocPdf('untyped.pdf'),
    ])->assertSessionHasErrors('document_type');

    contextualDocClaim($ctx, $ctx['owner']);
    $this->post($storeUrl, [
        'file' => UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        'document_type' => DocumentTypeCatalog::PAN,
    ])->assertSessionHasErrors('file');

    expect(DocumentAttachment::query()->where('parent_id', $customer->id)->count())->toBe(0);

    contextualDocClaim($ctx, $ctx['owner']);
    $this->get(route('branch.parties.show', $ctx['primaryDriver']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documentDrawer.title', 'Driver Documents')
            ->where('documentDrawer.document_types', fn ($types) => collect($types)->pluck('value')->contains(DocumentTypeCatalog::DRIVING_LICENCE)
                && ! collect($types)->pluck('value')->contains(DocumentTypeCatalog::GST_REGISTRATION)));
});

test('supporting document does not mark broker POD received and POD uploads stay separate', function () {
    brokerGrantLorryHirePermissions();
    brokerGrantDeliveryAdjustmentPermissions();

    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hire->lorryReceipt, $ctx);

    app(BrokerDeliveryService::class)->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
        'memo_number' => 'MEMO-SUPPORT-1',
    ]);
    $delivery = $hire->lorryReceipt->fresh()->delivery;
    expect($delivery)->not->toBeNull();
    $statusBefore = $delivery->status;

    contextualDocClaim($ctx, $ctx['member']);
    $this->post(route('branch.documents.store', [
        'parentType' => DocumentParentTypes::BROKER_DELIVERY,
        'parentId' => $delivery->id,
    ]), [
        'file' => contextualDocPdf('support.pdf'),
        'document_type' => DocumentTypeCatalog::SUPPORTING,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $delivery->refresh();
    expect($delivery->status)->toBe($statusBefore)
        ->and($delivery->status)->toBe(BrokerDeliveryStatus::MemoAssigned)
        ->and($delivery->pod_number)->toBeNull()
        ->and(DocumentAttachment::query()
            ->where('parent_id', $delivery->id)
            ->where('document_type', DocumentTypeCatalog::POD)
            ->exists())->toBeFalse();

    contextualDocClaim($ctx, $ctx['member']);
    $this->post(route('branch.broker.deliveries.pod', $delivery), [
        'file' => contextualDocPdf('pod.pdf'),
    ])->assertRedirect();

    contextualDocClaim($ctx, $ctx['member']);
    $this->get(route('branch.broker.deliveries.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('recentDeliveries.0.documentDrawer.sections', [
                ['key' => 'pod', 'title' => 'POD'],
                ['key' => 'supporting', 'title' => 'Supporting Documents'],
            ])
            ->where('recentDeliveries.0.documentDrawer.attachments', fn ($attachments) => collect($attachments)
                ->mapWithKeys(fn (array $row): array => [$row['document_type'] => $row['section']])
                ->sortKeys()
                ->all() === [DocumentTypeCatalog::POD => 'pod', DocumentTypeCatalog::SUPPORTING => 'supporting']));
});

test('document routes keep company isolation for uploads and downloads', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $customer = contextualCustomerParty($ctx);

    contextualDocClaim($ctx, $ctx['owner']);
    $this->post(route('branch.documents.store', [
        'parentType' => DocumentParentTypes::PARTY,
        'parentId' => $customer->id,
    ]), [
        'file' => contextualDocPdf('pan.pdf'),
        'document_type' => DocumentTypeCatalog::PAN,
    ])->assertSessionHasNoErrors()->assertRedirect();
    $attachment = DocumentAttachment::query()->where('parent_id', $customer->id)->sole();

    $other = tripWorkspace();
    tripActivate($other['company'], $other['branch']);

    contextualDocClaim($other, $other['owner']);
    $this->post(route('branch.documents.store', [
        'parentType' => DocumentParentTypes::PARTY,
        'parentId' => $customer->id,
    ]), [
        'file' => contextualDocPdf('cross.pdf'),
        'document_type' => DocumentTypeCatalog::PAN,
    ])->assertForbidden();

    contextualDocClaim($other, $other['owner']);
    $this->get(route('branch.documents.download', $attachment))->assertNotFound();

    expect(DocumentAttachment::query()->where('parent_id', $customer->id)->count())->toBe(1);
});
