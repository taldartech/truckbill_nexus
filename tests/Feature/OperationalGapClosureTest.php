<?php

use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Enquiry\EnquiryConversionService;
use App\Domains\Enquiry\EnquiryQuotationService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\OwnFleet\Driver\DriverAdvanceService;
use App\Domains\OwnFleet\Invoice\TripInvoicePaymentService;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Domains\OwnFleet\Trip\OwnFleetBiltyTripOrchestrator;
use App\Domains\OwnFleet\Trip\TripExpenseService;
use App\Domains\OwnFleet\Trip\TripMovementService;
use App\Domains\OwnFleet\Trip\TripService;
use App\Domains\OwnFleet\Trip\VendorExpensePaymentService;
use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Delivery\PartloadDeliveryReceiptService;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Enums\PartloadInwardDisposition;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\Invoice\PartloadInvoiceService;
use App\Domains\Partload\Inward\PartloadInwardService;
use App\Domains\Partload\MainChallan\PartloadMainChallanService;
use App\Domains\Partload\Reports\PartloadPendingLoadingService;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\AccountingVoucher;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\OwnerCatalogueItem;
use App\Models\PaymentMode;
use App\Models\Role;
use App\Models\Station;
use App\Models\TripExpense;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/../Support/PartloadHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

// Scenario 9: Enquiry -> Quotations -> Selection -> Conversion without duplicate re-entry or premature GL postings.
test('enquiry lifecycle converts cleanly into broker bilty without duplicate re-entry or premature gl postings', function () {
    $ctx = brokerWorkspace(['accounting']);
    foreach ([
        PermissionCodes::ENQUIRY_VIEW,
        PermissionCodes::ENQUIRY_CREATE,
        PermissionCodes::ENQUIRY_EDIT,
        PermissionCodes::ENQUIRY_CONVERT,
    ] as $code) {
        tripGrant('customer.admin', $code);
    }

    TenantContextHolder::inject(new TenantContext(
        companyId: $ctx['company']->id,
        branchId: $ctx['branch']->id,
        ownerId: (string) $ctx['company']->owner_user_id,
    ));

    $truckType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'name' => '32ft MXL',
        'code' => '32MXL',
        'is_active' => true,
    ]);

    $customer = app(PartyService::class)->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: [
            'name' => 'Enquiry Customer',
            'contact_number' => '9800010099',
            'state' => 'MH',
            'city' => 'Mumbai',
            'is_gst_applicable' => false,
        ],
        roles: [
            ['role' => PartyRoleType::Customer->value],
            ['role' => PartyRoleType::Consignor->value],
            ['role' => PartyRoleType::Consignee->value],
        ],
        remark: 'Customer',
    );
    ['transporter' => $transporter, 'vehicle' => $marketVehicle] = brokerCreateTransporterAndMarketVehicle($ctx);

    $origin = geographyIdsFor('Maharashtra', 'Mumbai City');
    $destination = geographyIdsFor('Maharashtra', 'Pune');
    $enquiry = app(EnquiryService::class)->createManual($ctx['company'], $ctx['branch'], $ctx['member'], [
        'customer_party_id' => $customer->id,
        'from_state_id' => $origin['state_id'],
        'from_city_id' => $origin['city_id'],
        'to_state_id' => $destination['state_id'],
        'to_city_id' => $destination['city_id'],
        'vehicle_type_id' => $truckType->id,
        'vehicle_quantity' => 1,
        'goods' => [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'goods_description' => 'Electronics Crates',
            'package_count' => 12,
            'actual_weight' => 4500,
        ]],
    ]);

    $quotations = app(EnquiryQuotationService::class);
    $quote = $quotations->create($ctx['company'], $ctx['branch'], $enquiry, $ctx['member'], [
        'transporter_party_id' => $transporter->id,
        'quoted_freight' => 18500,
    ]);
    $quotations->selectPreferred($ctx['company'], $ctx['branch'], $enquiry->fresh(), $quote, $ctx['member']);

    // Enquiries and quotations are strictly non-financial: zero GL vouchers!
    expect(AccountingVoucher::query()->where('company_id', $ctx['company']->id)->count())->toBe(0);

    $conversion = app(EnquiryConversionService::class);
    $preview = $conversion->preview($ctx['company'], $ctx['branch'], $enquiry->fresh());
    $converted = $conversion->convert($ctx['company'], $ctx['branch'], $enquiry->fresh(), $ctx['member'], [
        'workflow_context' => LrWorkflowContext::Broker->value,
        'vehicle_id' => $marketVehicle->id,
        'idempotency_key' => 'enq-conv-'.$enquiry->id,
        'vehicles' => collect($preview['suggested_vehicles'])->map(fn (array $v): array => ['goods' => $v['goods']])->all(),
    ]);

    expect($converted['lorry_receipts'])->toHaveCount(1)
        ->and($converted['lorry_receipts'][0]->consignor_party_id)->toBe($customer->id)
        ->and(AccountingVoucher::query()->where('company_id', $ctx['company']->id)->count())->toBe(0);
});

// Scenario 10: Complete Broker workflow including negative adjustment, voucher settlement, delivery/POD, invoice & receipt.
test('complete broker journey handles negative freight adjustments, vouchers, delivery, pod, invoice and receipt', function () {
    $ctx = brokerWorkspace(['accounting']);
    brokerGrantVoucherPermissions();
    brokerGrantDeliveryAdjustmentPermissions();
    brokerGrantInvoicePermissions();

    $hire = brokerCreateLorryHire($ctx);
    expect($hire->transporter_party_id)->toBe($hire->lorryReceipt->intended_payee_party_id);

    // Negative freight adjustment (shortage deduction reducing payable)
    $shortage = app(BrokerAdjustmentService::class)->createShortage($hire, $ctx['member'], [
        'quantity' => '2',
        'rate' => '250.00',
        'occurred_at' => now()->toDateString(),
        'reason' => 'Shortage 2 bags',
    ]);
    expect((float) $shortage->amount)->toBe(500.0)
        ->and((float) $hire->fresh()->outstanding_amount)->toBe((float) $hire->freight_total - 500.0);

    $voucher = app(TransporterVoucherService::class)->createForLorryHire($hire->fresh(), $ctx['member'], [
        'amount' => '3000.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
        'voucher_date' => now()->toDateString(),
    ]);
    app(TransporterVoucherService::class)->transitionStatus($voucher, $ctx['member'], VoucherStatus::Paid);

    $lr = brokerDeliverLr($hire->lorryReceipt()->firstOrFail(), $ctx, 'MEMO-900');
    expect($lr->status)->toBe(LrStatus::Delivered);

    $invoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$lr->id],
        'billing_customer_party_id' => $lr->billing_customer_party_id,
        'invoice_date' => now()->toDateString(),
    ]);

    $payment = app(BrokerInvoiceService::class)->recordPayment($invoice, $ctx['member'], [
        'amount_received' => $invoice->invoice_total,
        'payment_mode' => 'cash',
        'payment_date' => now()->toDateString(),
    ]);

    expect((string) $payment->amount_received)->toBe((string) $invoice->invoice_total)
        ->and((string) $invoice->fresh()->balance_due)->toBe('0.00');
});

// Scenario 11: Complete OwnFleet workflow including driver advance ledger, credit vendor payable & settlement, trip completion, invoice & receipt.
test('complete ownfleet journey handles driver advances, credit vendor expenses, settlements, trip invoice and receipt', function () {
    $ctx = tripWorkspace([SubscriptionModule::OwnFleet->value], ['accounting']);

    TenantContextHolder::inject(new TenantContext(
        companyId: $ctx['company']->id,
        branchId: $ctx['branch']->id,
        ownerId: (string) $ctx['company']->owner_user_id,
    ));

    $customer = app(PartyService::class)->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: [
            'name' => 'OwnFleet Client',
            'contact_number' => '9811112222',
            'state' => 'MH',
            'city' => 'Mumbai',
            'is_gst_applicable' => false,
        ],
        roles: [
            ['role' => PartyRoleType::Customer->value],
            ['role' => PartyRoleType::Consignor->value],
            ['role' => PartyRoleType::Consignee->value],
        ],
        remark: 'Customer',
    );

    $fuelVendor = app(PartyService::class)->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: [
            'name' => 'Highway Diesel Pump',
            'contact_person' => 'Pump Manager',
            'contact_number' => '9822223333',
            'state' => 'MH',
            'city' => 'Pune',
            'is_gst_applicable' => false,
        ],
        roles: [['role' => PartyRoleType::PumpVendor->value]],
        remark: 'Pump vendor',
    );

    $bankAcc = BankCashAccount::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'name' => 'OwnFleet Bank',
        'account_kind' => 'bank',
        'is_active' => true,
    ]);

    $cashMode = PaymentMode::query()->firstOrCreate(
        ['code' => 'CASH'],
        [
            'id' => (string) Str::uuid(),
            'name' => 'Cash',
            'is_active' => true,
        ],
    );

    $dieselCat = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'catalogue_type' => OwnerCatalogueType::ExpenseCategory->value,
        'name' => 'Diesel Fuel',
        'code' => 'DIESEL',
        'is_active' => true,
    ]);

    // Auto-create Trip via OwnFleet Bilty orchestrator
    $orch = app(OwnFleetBiltyTripOrchestrator::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'workflow_context' => LrWorkflowContext::OwnFleet->value,
        'lr_date' => now()->toDateString(),
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'consignor_party_id' => $customer->id,
        'consignee_party_id' => $customer->id,
        'billing_customer_party_id' => $customer->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'freight_rate_type' => 'fixed_amount',
        'freight_rate' => 25000,
        'goods' => [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'goods_description' => 'Steel Coils',
            'package_count' => 8,
            'actual_weight' => 8000,
            'charged_weight' => 8000,
            'freight_rate_type' => 'fixed_amount',
            'freight_rate' => 25000,
            'freight_amount' => 25000,
        ]],
    ]);

    $trip = $orch['trip'];
    $lr = $orch['lorry_receipt'];

    // Multi-point pickup/drop + full allocation + dispatch + complete
    $movements = app(TripMovementService::class);
    $pickup = $movements->addPickup($trip, $ctx['member'], [
        'station_id' => $ctx['fromStation']->id,
        'consignor_party_id' => $customer->id,
        'goods_description' => 'Steel Coils',
        'weight' => '8000',
        'pickup_date' => now()->toDateString(),
    ]);
    $drop = $movements->addDrop($trip, $ctx['member'], [
        'station_id' => $ctx['toStation']->id,
        'consignee_party_id' => $customer->id,
    ]);
    $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickup->id,
        'trip_drop_id' => $drop->id,
        'allocation_weight' => '8000',
        'freight' => '25000',
    ]);
    app(TripService::class)->dispatch($trip->fresh(), $ctx['member'], ['starting_odometer' => '10000']);

    // Credit Vendor Diesel Expense + Immediate Vendor Payment Settlement
    /** @var TripExpense $dieselExp */
    $dieselExp = app(TripExpenseService::class)->add($trip->fresh(), $ctx['member'], [
        'expense_category_id' => $dieselCat->id,
        'expense_date' => now()->toDateString(),
        'is_diesel' => true,
        'diesel_litres' => '100',
        'diesel_rate_per_litre' => '95.00',
        'diesel_odometer' => '10200',
        'vendor_party_id' => $fuelVendor->id,
        'is_credit_payable' => true,
        'remarks' => 'Pump credit fill',
    ]);

    app(VendorExpensePaymentService::class)->recordPayment($ctx['company'], $ctx['branch'], $ctx['member'], [
        'vendor_party_id' => $fuelVendor->id,
        'trip_expense_id' => $dieselExp->id,
        'amount' => '9500.00',
        'payment_mode' => 'bank',
        'bank_cash_account_id' => $bankAcc->id,
        'payment_date' => now()->toDateString(),
    ]);

    expect($dieselExp->fresh()->settled_at)->not->toBeNull();

    $cashAcc = BankCashAccount::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'branch_id' => null,
        'name' => 'OwnFleet Cash Till',
        'account_kind' => 'cash',
        'is_active' => true,
    ]);

    // Driver Advance -> Expense Settlement -> Cash Recovery (net: 5000 - 3000 - 1000 = 1000)
    $driverLedger = app(DriverAdvanceService::class);
    $driverLedger->record($ctx['company'], $ctx['branch'], $ctx['member'], [
        'driver_party_id' => $ctx['primaryDriver']->id,
        'trip_id' => $trip->id,
        'entry_type' => 'advance',
        'amount' => '5000.00',
        'payment_mode' => 'cash',
        'bank_cash_account_id' => $cashAcc->id,
        'entry_date' => now()->toDateString(),
    ]);
    $driverLedger->record($ctx['company'], $ctx['branch'], $ctx['member'], [
        'driver_party_id' => $ctx['primaryDriver']->id,
        'trip_id' => $trip->id,
        'entry_type' => 'settlement',
        'amount' => '3000.00',
        'entry_date' => now()->toDateString(),
    ]);
    $driverLedger->record($ctx['company'], $ctx['branch'], $ctx['member'], [
        'driver_party_id' => $ctx['primaryDriver']->id,
        'trip_id' => $trip->id,
        'entry_type' => 'recovery',
        'amount' => '1000.00',
        'payment_mode' => 'cash',
        'bank_cash_account_id' => $cashAcc->id,
        'entry_date' => now()->toDateString(),
    ]);

    $driverSummary = $driverLedger->summaryForBranch($ctx['company'], $ctx['branch'], $ctx['member']);
    expect($driverSummary['total_outstanding'])->toBe('1000.00');

    app(TripService::class)->complete($trip->fresh(), $ctx['member'], [
        'ending_odometer' => '10420',
        'delivered_at' => now()->toDateTimeString(),
    ]);

    $invoice = app(TripInvoiceService::class)->createIndividual(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        $lr->id,
        [
            'invoice_date' => now()->toDateString(),
        ],
    );

    app(TripInvoicePaymentService::class)->record($invoice, $ctx['member'], [
        'amount_received' => $invoice->invoice_total,
        'payment_mode_id' => $cashMode->id,
        'payment_date' => now()->toDateString(),
    ]);

    expect((string) $invoice->fresh()->balance_due)->toBe('0.00');
});

// Scenario 12: Complete Partload workflow with Pending Loading -> Challan -> Main Challan -> Hub Inward Shortage/Damage & Forward Transshipment -> Leg 2 Challan -> Destination Inward -> DR -> Partload Invoice & Receipt + Duplicate Guards.
test('complete partload journey supports transshipment forwarding, shortage/damage, main challan, delivery receipt and partload invoicing', function () {
    $ctx = partloadWorkspace(['accounting']);
    foreach ([
        PermissionCodes::INVOICE_VIEW,
        PermissionCodes::INVOICE_CREATE,
        PermissionCodes::INVOICE_EDIT,
        PermissionCodes::INVOICE_CANCEL,
    ] as $code) {
        tripGrant('customer.admin', $code);
    }

    $hubStation = Station::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'name' => 'Hub Surat',
    ]);

    $branch2 = Branch::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'name' => 'Hub Branch',
        'code' => 'HUB1',
        'city' => 'Surat',
        'state' => 'GJ',
        'is_active' => true,
    ]);
    $workspace = app(CompanyWorkspaceService::class);
    $memberMembership = $ctx['company']->memberships()->where('user_id', $ctx['member']->id)->firstOrFail();
    $b2Assign = $workspace->assignBranch($memberMembership, $branch2, 'Hub access');
    app(RoleAssignmentService::class)->assignCustomerBranchRole(
        $ctx['owner'],
        $memberMembership,
        $branch2,
        $b2Assign,
        Role::query()->where('slug', 'customer.admin')->firstOrFail(),
        'Hub admin',
    );

    $bankAcc = BankCashAccount::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'branch_id' => null,
        'name' => 'Partload Main Bank',
        'account_kind' => 'bank',
        'is_active' => true,
    ]);

    // Bookings: Paid + ToPay + TBB
    $paidLr = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::Paid);
    $toPayLr = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::ToPay);
    $tbbLr = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::ToBeBilled);

    $pending = app(PartloadPendingLoadingService::class)->snapshot($ctx['company'], $ctx['branch'], $ctx['member']);
    expect($pending['totals']['count'])->toBe(3);

    // Leg 1 Challan (Branch 1 -> Hub) as Draft for Main Challan consolidation + Duplicate loading guard
    $challan1 = app(PartloadChallanService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $hubStation->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$paidLr->id, $toPayLr->id, $tbbLr->id],
        'truck_freight' => '2200.00',
        'dispatch' => false,
    ]);

    // Duplicate loading must fail!
    expect(fn () => app(PartloadChallanService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $hubStation->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$toPayLr->id],
    ]))->toThrow(PartloadConflictException::class);

    // Consolidate Challan 1 into a Main Challan & Dispatch
    $mainChallan = app(PartloadMainChallanService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'ownership_mode' => VehicleOwnership::Market->value,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $hubStation->id,
        'partload_challan_ids' => [$challan1->id],
        'truck_freight' => '500.00',
    ]);
    app(PartloadMainChallanService::class)->dispatch($mainChallan, $ctx['member']);

    // Hub Inward at Branch 2: mark ToPay LR with shortage + damage & Forward all 3 for Leg 2 transshipment!
    $hubInward = app(PartloadInwardService::class)->createFromChallan($ctx['company'], $branch2, $ctx['member'], [
        'partload_challan_id' => $challan1->id,
        'to_station_id' => $hubStation->id,
        'lines' => [
            [
                'lorry_receipt_id' => $paidLr->id,
                'packages' => '5',
                'weight_kg' => '1000',
                'disposition' => PartloadInwardDisposition::Forward->value,
            ],
            [
                'lorry_receipt_id' => $toPayLr->id,
                'packages' => '4',
                'weight_kg' => '800',
                'is_damaged' => true,
                'remark' => '1 carton wet',
                'disposition' => PartloadInwardDisposition::Forward->value,
            ],
            [
                'lorry_receipt_id' => $tbbLr->id,
                'packages' => '5',
                'weight_kg' => '1000',
                'disposition' => PartloadInwardDisposition::Forward->value,
            ],
        ],
    ]);

    $damagedLine = $hubInward->lines->firstWhere('lorry_receipt_id', $toPayLr->id);
    expect((float) $damagedLine->shortage_packages)->toBe(1.0)
        ->and((bool) $damagedLine->is_damaged)->toBeTrue();

    // Leg 2 Transshipment Challan at Hub Branch (Branch 2 -> Destination)
    $challan2 = app(PartloadChallanService::class)->create($ctx['company'], $branch2, $ctx['member'], [
        'from_station_id' => $hubStation->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$paidLr->id, $toPayLr->id, $tbbLr->id],
        'truck_freight' => '1800.00',
        'dispatch' => true,
    ]);

    // Destination Inward at Branch 1 (Delivery disposition)
    $destInward = app(PartloadInwardService::class)->createFromChallan($ctx['company'], $ctx['branch'], $ctx['member'], [
        'partload_challan_id' => $challan2->id,
        'to_station_id' => $ctx['toStation']->id,
    ]);

    $drService = app(PartloadDeliveryReceiptService::class);
    $toPayLine = $destInward->lines->firstWhere('lorry_receipt_id', $toPayLr->id);
    $paidLine = $destInward->lines->firstWhere('lorry_receipt_id', $paidLr->id);
    $tbbLine = $destInward->lines->firstWhere('lorry_receipt_id', $tbbLr->id);

    $drService->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_id' => $toPayLr->id,
        'partload_inward_line_id' => $toPayLine->id,
        'payment_mode' => PartloadDeliveryPaymentMode::Due->value,
        'payment_due_from_party_id' => $ctx['customer']->id,
    ]);

    $cashAcc = BankCashAccount::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'branch_id' => null,
        'name' => 'Partload Cash Desk',
        'account_kind' => 'cash',
        'is_active' => true,
    ]);

    $drService->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_id' => $paidLr->id,
        'partload_inward_line_id' => $paidLine->id,
        'payment_mode' => PartloadDeliveryPaymentMode::Cash->value,
        'bank_cash_account_id' => $cashAcc->id,
        'freight' => '0.00',
        'handling_charge' => '150.00',
    ]);

    // Duplicate DR must fail!
    expect(fn () => $drService->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_id' => $paidLr->id,
        'partload_inward_line_id' => $paidLine->id,
        'payment_mode' => PartloadDeliveryPaymentMode::Cash->value,
    ]))->toThrow(PartloadConflictException::class);

    // Partload Invoice for Due DR + TBB Bilty + Payment Settlement + Duplicate Invoicing Guard
    $partloadInvoices = app(PartloadInvoiceService::class);
    $pInvoice = $partloadInvoices->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$toPayLr->id, $tbbLr->id],
        'invoice_date' => now()->toDateString(),
    ]);

    expect(fn () => $partloadInvoices->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$toPayLr->id],
    ]))->toThrow(PartloadConflictException::class);

    $partloadInvoices->recordPayment($pInvoice, $ctx['member'], [
        'amount_received' => $pInvoice->invoice_total,
        'payment_mode' => 'bank',
        'bank_cash_account_id' => $bankAcc->id,
        'payment_date' => now()->toDateString(),
    ]);

    expect($pInvoice->fresh()->payment_status)->toBe('paid')
        ->and((string) $pInvoice->fresh()->balance_due)->toBe('0.00');
});
