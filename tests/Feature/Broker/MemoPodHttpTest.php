<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Document\DocumentParentTypes;
use App\Models\BrokerDelivery;
use App\Models\CustomerWebSession;
use App\Models\DocumentAttachment;
use App\Support\Files\Scanning\MalwareScanner;
use App\Support\Files\Scanning\PassThroughMalwareScanner;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    Storage::fake('private_files');
    config(['files.disk' => 'private_files', 'files.scanner' => 'pass', 'files.max_bytes' => 2 * 1024 * 1024]);
    app()->bind(MalwareScanner::class, fn () => new PassThroughMalwareScanner);
    brokerGrantLorryHirePermissions();
    brokerGrantDeliveryAdjustmentPermissions();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

function actAsMemoOperator(array $ctx): void
{
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $ctx['member']->id)->delete();
    test()->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('memo context lists the selected bilties for one customer', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    actAsMemoOperator($ctx);

    $this->getJson(route('branch.broker.deliveries.memo-context', ['lorry_receipt_ids' => [$hire->lorry_receipt_id]]))
        ->assertOk()
        ->assertJsonPath('customer_name', $customer->name)
        ->assertJsonPath('receipts.0.id', $hire->lorry_receipt_id);
});

test('memo / pod from the register delivers the bilty, attaches the optional pod and returns to the register', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    actAsMemoOperator($ctx);
    $register = route('branch.broker.lorry-hires.index', ['pending_memo' => 1]);

    $this->from($register)
        ->post(route('branch.broker.deliveries.memo'), [
            'lorry_receipt_ids' => [$hire->lorry_receipt_id],
            'memo_number' => 'MEMO-HTTP-1',
            'memo_date' => now()->toDateString(),
            'pod_documents' => [$hire->lorry_receipt_id => UploadedFile::fake()->create('pod.pdf', 100, 'application/pdf')],
        ])
        ->assertRedirect($register)
        ->assertSessionHas('success', 'Memo / POD MEMO-HTTP-1 assigned to 1 Bilty; marked delivered.');

    $delivery = BrokerDelivery::query()->where('lorry_receipt_id', $hire->lorry_receipt_id)->firstOrFail();
    expect($delivery->memo_number)->toBe('MEMO-HTTP-1')
        ->and(DocumentAttachment::query()
            ->where('parent_type', DocumentParentTypes::BROKER_DELIVERY)
            ->where('parent_id', $delivery->id)
            ->where('document_type', 'pod')
            ->exists())->toBeTrue();

    actAsMemoOperator($ctx);
    $this->get($register)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('broker/lorry-hires/index')
            ->where('filters.pending_memo', true)
            ->has('lorryHires.data', 0));
});

test('good hires register exposes memo eligibility and the pending memo filter', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    actAsMemoOperator($ctx);

    $this->get(route('branch.broker.lorry-hires.index', ['pending_memo' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permissions.can_assign_memo', true)
            ->where('urls.memo_store', route('branch.broker.deliveries.memo', absolute: false))
            ->where('lorryHires.data.0.id', $hire->id)
            ->where('lorryHires.data.0.memo_pending', true)
            ->where('lorryHires.data.0.memo_number', null));
});

test('memo / pod requires the memo number', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    actAsMemoOperator($ctx);

    $this->post(route('branch.broker.deliveries.memo'), [
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
        'memo_number' => '',
    ])->assertSessionHasErrors(['memo_number' => 'Enter the Memo / POD number.']);
});
