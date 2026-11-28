<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Document\DocumentParentTypes;
use App\Domains\Document\DocumentTypeCatalog;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\CustomerWebSession;
use App\Models\DocumentAttachment;
use App\Models\Party;
use App\Support\Files\StoredFile;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

function actAsDriverOperator(array $ctx, bool $useOwner = true): void
{
    $user = $useOwner ? $ctx['owner'] : $ctx['member'];
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('broker-only subscriber gets driver master and party quick add while ownfleet vehicle-driver features remain blocked', function () {
    $ctx = tripWorkspace([SubscriptionModule::Broker->value]);
    tripActivate($ctx['company'], $ctx['branch']);

    $resolver = app(CompanyCapabilityResolver::class);
    expect($resolver->allowsRead($ctx['company'], CompanyCapabilities::MASTERS_PARTIES_DRIVER_ROLE))->toBeTrue()
        ->and($resolver->allowsMutate($ctx['company'], CompanyCapabilities::MASTERS_PARTIES_DRIVER_ROLE))->toBeTrue()
        ->and($resolver->allowsRead($ctx['company'], CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS))->toBeFalse()
        ->and($resolver->allowsMutate($ctx['company'], CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS))->toBeFalse();

    actAsDriverOperator($ctx);
    $this->get(route('branch.drivers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/drivers/index')
            ->where('workspace.navigation.masters_drivers', true)
            ->where('workspace.navigation.ownfleet_vehicle_drivers', false));

    actAsDriverOperator($ctx);
    $this->get(route('branch.drivers.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/form')
            ->where('defaultRoles', ['driver'])
            ->where('defaultDriverType', 'market')
            ->where('driverMasterContext', true)
            ->where('returnTo', 'drivers'));

    $geo = geographyIdsFor();

    // Create a driver via shared Party store with return_to=drivers
    actAsDriverOperator($ctx);
    $storeResponse = $this->post(route('branch.parties.store'), [
        'name' => 'Broker Highway Driver',
        'short_name' => 'BHD',
        'contact_number' => '9812300011',
        'state_id' => $geo['state_id'],
        'city_id' => $geo['city_id'],
        'return_to' => 'drivers',
        'roles' => [[
            'role' => PartyRoleType::Driver->value,
            'attributes' => [
                'driver_type' => 'market',
                'license_number' => 'MH14DL202699',
                'license_expiry' => '2030-12-31',
            ],
        ]],
    ]);

    $driver = Party::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Broker Highway Driver')
        ->firstOrFail();

    $storeResponse->assertRedirect(route('branch.drivers.show', $driver, absolute: false));

    actAsDriverOperator($ctx);
    $this->get(route('branch.drivers.show', $driver))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/show')
            ->where('driverMasterContext', true));

    actAsDriverOperator($ctx);
    $this->get(route('branch.drivers.edit', $driver))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/masters/parties/form')
            ->where('driverMasterContext', true)
            ->where('returnTo', 'drivers'));

    actAsDriverOperator($ctx);
    $this->get(route('branch.drivers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('drivers.data.0.name', 'Broker Highway Driver')
            ->where('drivers.data.0.license_number', 'MH14DL202699')
            ->where('drivers.data.0.license_expiry', '31-12-2030'));

    // Edit driver + redirect back to Driver Master
    actAsDriverOperator($ctx);
    $this->patch(route('branch.parties.update', $driver), [
        'name' => 'Broker Highway Driver Updated',
        'short_name' => 'BHDU',
        'contact_number' => '9812300011',
        'state_id' => $geo['state_id'],
        'city_id' => $geo['city_id'],
        'return_to' => 'drivers',
        'remark' => 'Updated licence expiry',
        'roles' => [[
            'role' => PartyRoleType::Driver->value,
            'attributes' => [
                'driver_type' => 'market',
                'license_number' => 'MH14DL202699',
                'license_expiry' => '2031-06-30',
            ],
        ]],
    ])->assertRedirect(route('branch.drivers.show', $driver, absolute: false));

    // Quick Add Driver JSON (used by Market Vehicle and Bilty selectors)
    actAsDriverOperator($ctx);
    $this->postJson(route('branch.parties.store'), [
        'name' => 'Quick Add Market Driver',
        'contact_number' => '9812300099',
        'state_id' => $geo['state_id'],
        'city_id' => $geo['city_id'],
        'roles' => [[
            'role' => PartyRoleType::Driver->value,
            'attributes' => [
                'driver_type' => 'market',
            ],
        ]],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Quick Add Market Driver');

    $quickDriver = Party::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Quick Add Market Driver')
        ->firstOrFail();

    $storedFile = StoredFile::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'owner_id' => $ctx['owner']->id,
        'disk' => 'private_files',
        'path' => 'tests/dl-sample.pdf',
        'original_name' => 'dl-sample.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 128,
        'checksum_sha256' => hash('sha256', 'dl-sample'),
        'scan_status' => 'available',
        'available_at' => now(),
    ]);
    DocumentAttachment::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'parent_type' => DocumentParentTypes::PARTY,
        'parent_id' => $quickDriver->id,
        'stored_file_id' => $storedFile->id,
        'document_type' => DocumentTypeCatalog::DRIVING_LICENCE,
        'label' => 'Driving Licence',
        'document_number' => 'MH01DOC9988',
        'expires_on' => '2032-05-15',
        'version' => 1,
        'is_current' => true,
    ]);

    actAsDriverOperator($ctx);
    $this->get(route('branch.drivers.index', ['q' => 'Quick Add Market Driver']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('drivers.data.0.license_number', 'MH01DOC9988')
            ->where('drivers.data.0.license_expiry', '15-05-2032'));

    // Market vehicle create exposes drivers + authorized partiesStoreUrl; OwnFleet Vehicle Drivers stays 403
    actAsDriverOperator($ctx);
    $this->get(route('branch.vehicles.create'))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $page->where('links.partiesStoreUrl', route('branch.parties.store', absolute: false));
            $drivers = collect($page->toArray()['props']['formOptions']['drivers'] ?? []);
            expect($drivers->pluck('name')->all())->toContain('Broker Highway Driver Updated', 'Quick Add Market Driver');
        });

    actAsDriverOperator($ctx);
    $this->get(route('branch.vehicle-drivers.index'))->assertForbidden();
    actAsDriverOperator($ctx);
    $this->post(route('branch.vehicle-drivers.attendance.mark'), [])->assertForbidden();

    // Soft-delete driver with mandatory audit remark returns to Driver Master
    actAsDriverOperator($ctx);
    $this->delete(route('branch.parties.destroy', $driver), [
        'remark' => 'Driver left agency',
        'return_to' => 'drivers',
    ])->assertRedirect(route('branch.drivers.index', absolute: false));
});

test('driver quick add urls are omitted when user lacks platform create permission', function () {
    $ctx = tripWorkspace([SubscriptionModule::Broker->value]);
    tripActivate($ctx['company'], $ctx['branch']);
    tripGrant('customer.viewer', PermissionCodes::PLATFORM_VIEW);
    tripGrant('customer.viewer', PermissionCodes::LR_CREATE);

    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $ctx['viewer']->id)->delete();
    test()->actingAs($ctx['viewer']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.drivers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.can_create', false));

    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $ctx['viewer']->id)->delete();
    test()->actingAs($ctx['viewer']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.lorry-receipts.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('urls.parties_store', null)
            ->where('urls.vehicles_store', null));
});
