<?php

use App\Models\Party;
use App\Models\TripInvoice;
use App\Support\Database\CaseInsensitiveLike;
use Database\Seeders\RbacSeeder;

require_once __DIR__.'/InvoiceFacadeTestHelpers.php';

test('CaseInsensitiveLike whereContains matches invoice numbers regardless of case', function () {
    (new RbacSeeder)->run();

    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = invoiceFacadeWorkspace();

    $customer = Party::factory()->create([
        'company_id' => $company->id,
        'name' => 'Like Test Customer',
    ]);

    seedLeanOwnFleetTripInvoice($company->id, $branch->id, $customer->id, $owner->id, 'Alpha-Unique-9001');
    seedLeanOwnFleetTripInvoice($company->id, $branch->id, $customer->id, $owner->id, 'beta-unique-9002');

    $lowerMatch = TripInvoice::query()
        ->where('company_id', $company->id)
        ->whereNull('deleted_at');
    CaseInsensitiveLike::whereContains($lowerMatch, 'invoice_number', 'alpha-unique');

    $upperMatch = TripInvoice::query()
        ->where('company_id', $company->id)
        ->whereNull('deleted_at');
    CaseInsensitiveLike::whereContains($upperMatch, 'invoice_number', 'BETA-UNIQUE');

    expect($lowerMatch->pluck('invoice_number')->all())->toBe(['Alpha-Unique-9001'])
        ->and($upperMatch->pluck('invoice_number')->all())->toBe(['beta-unique-9002']);
});
