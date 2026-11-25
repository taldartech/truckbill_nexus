<?php

use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Company\Enums\BranchGstMode;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Tax\EffectiveGstConfigurationResolver;
use App\Models\GstRate;
use App\Models\LorryReceipt;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/BrokerLorryHireHelpers.php';

beforeEach(function (): void {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
});

afterEach(function (): void {
    TenantContextHolder::clear();
});

test('effective gst resolver inherits company then switches to independent branch gst', function (): void {
    $ctx = brokerWorkspace();
    $company = $ctx['company'];
    $branch = $ctx['branch'];

    $rate = GstRate::query()->create([
        'id' => (string) Str::uuid(),
        'code' => 'GST18-TEST',
        'name' => 'GST 18% test',
        'rate_percent' => '18.0000',
        'version' => 1,
        'is_active' => true,
        'effective_from' => now()->toDateString(),
    ]);

    $company->forceFill([
        'gst_registration_type' => GstRegistrationType::Regular,
        'tax_liability' => TaxLiability::Fcm,
        'gstin' => '27COMPANY0001Z1',
        'gst_rate_id' => $rate->id,
        'gst_rate_snapshot' => $rate->toSnapshot(),
        'state' => 'Maharashtra',
    ])->save();

    $branch->forceFill([
        'gst_mode' => BranchGstMode::InheritCompany,
        'state' => 'Maharashtra',
    ])->save();

    $resolver = new EffectiveGstConfigurationResolver;
    $inherited = $resolver->resolve($company->fresh(), $branch->fresh());
    expect($inherited->inheritsCompany)->toBeTrue()
        ->and($inherited->gstin)->toBe('27COMPANY0001Z1');

    $branch->forceFill([
        'gst_mode' => BranchGstMode::Independent,
        'gst_registration_type' => GstRegistrationType::Regular,
        'tax_liability' => TaxLiability::Fcm,
        'gstin' => '27BRANCH00001Z5',
        'gst_rate_id' => $rate->id,
        'gst_rate_snapshot' => $rate->toSnapshot(),
        'state' => 'Maharashtra',
    ])->save();

    $independent = $resolver->resolve($company->fresh(), $branch->fresh());
    expect($independent->inheritsCompany)->toBeFalse()
        ->and($independent->gstin)->toBe('27BRANCH00001Z5');
});

test('issued broker invoice retains supplier gst snapshot after branch gst change', function (): void {
    brokerGrantInvoicePermissions();
    $ctx = brokerWorkspace();
    $company = $ctx['company'];
    $branch = $ctx['branch'];

    $rate = GstRate::query()->create([
        'id' => (string) Str::uuid(),
        'code' => 'GST18-INV',
        'name' => 'GST 18% invoice',
        'rate_percent' => '18.0000',
        'version' => 1,
        'is_active' => true,
        'effective_from' => now()->toDateString(),
    ]);

    $company->forceFill([
        'gst_registration_type' => GstRegistrationType::Regular,
        'tax_liability' => TaxLiability::Fcm,
        'gstin' => '27COMPANY0002Z2',
        'gst_rate_id' => $rate->id,
        'gst_rate_snapshot' => $rate->toSnapshot(),
        'state' => 'Maharashtra',
    ])->save();

    $branch->forceFill([
        'gst_mode' => BranchGstMode::InheritCompany,
        'state' => 'Maharashtra',
    ])->save();

    $hire = brokerCreateLorryHire($ctx);
    $lr = $hire->lorryReceipt ?? LorryReceipt::query()->whereKey($hire->lorry_receipt_id)->firstOrFail();
    $lr = brokerDeliverLr($lr, $ctx, 'MEMO-GST-1');

    $invoice = app(BrokerInvoiceService::class)->createInvoice($company, $branch, $ctx['member'], [
        'billing_customer_party_id' => $lr->billing_customer_party_id,
        'lorry_receipt_ids' => [$lr->id],
        'remark' => 'GST snapshot invoice',
    ]);

    $originalSnapshot = $invoice->gst_rate_snapshot;
    expect(data_get($originalSnapshot, 'supplier_gstin'))->toBe('27COMPANY0002Z2');

    $branch->forceFill([
        'gst_mode' => BranchGstMode::Independent,
        'gst_registration_type' => GstRegistrationType::Regular,
        'tax_liability' => TaxLiability::Fcm,
        'gstin' => '27BRANCH00002Z9',
        'gst_rate_id' => $rate->id,
        'gst_rate_snapshot' => $rate->toSnapshot(),
    ])->save();

    $invoice->refresh();
    expect($invoice->gst_rate_snapshot)->toEqual($originalSnapshot)
        ->and(data_get($invoice->gst_rate_snapshot, 'supplier_gstin'))->toBe('27COMPANY0002Z2');
});
