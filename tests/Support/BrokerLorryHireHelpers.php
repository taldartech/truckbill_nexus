<?php

use App\Domains\Broker\Delivery\BrokerDeliveryService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\Party;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;

require_once __DIR__.'/OwnFleetTripHelpers.php';

if (! function_exists('brokerGrantLorryHirePermissions')) {
    function brokerGrantLorryHirePermissions(): void
    {
        foreach ([
            PermissionCodes::LR_VIEW,
            PermissionCodes::LR_CREATE,
            PermissionCodes::BROKER_LORRY_HIRE_VIEW,
            PermissionCodes::BROKER_LORRY_HIRE_CREATE,
            PermissionCodes::BROKER_LORRY_HIRE_EDIT,
            PermissionCodes::BROKER_LORRY_HIRE_CANCEL,
        ] as $code) {
            tripGrant('customer.admin', $code);
        }

        tripGrant('customer.viewer', PermissionCodes::BROKER_LORRY_HIRE_VIEW);
    }
}

if (! function_exists('brokerGrantDeliveryAdjustmentPermissions')) {
    function brokerGrantDeliveryAdjustmentPermissions(): void
    {
        foreach ([
            PermissionCodes::BROKER_DELIVERY_VIEW,
            PermissionCodes::BROKER_DELIVERY_MANAGE,
            PermissionCodes::BROKER_ADJUSTMENT_VIEW,
            PermissionCodes::BROKER_ADJUSTMENT_CREATE,
        ] as $code) {
            tripGrant('customer.admin', $code);
        }
    }
}

if (! function_exists('brokerGrantInvoicePermissions')) {
    function brokerGrantInvoicePermissions(): void
    {
        foreach ([
            PermissionCodes::BROKER_INVOICE_VIEW,
            PermissionCodes::BROKER_INVOICE_CREATE,
            PermissionCodes::BROKER_INVOICE_CANCEL,
            PermissionCodes::BROKER_INVOICE_PRINT,
        ] as $code) {
            tripGrant('customer.admin', $code);
        }
    }
}

if (! function_exists('brokerDeliverLr')) {
    /**
     * @param  array<string, mixed>  $ctx
     */
    function brokerDeliverLr(LorryReceipt $lr, array $ctx, ?string $memoNumber = null): LorryReceipt
    {
        brokerGrantDeliveryAdjustmentPermissions();

        $delivery = app(BrokerDeliveryService::class);

        if ($memoNumber !== null) {
            $delivery->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
                'lorry_receipt_ids' => [$lr->id],
                'memo_number' => $memoNumber,
            ]);
        }

        $delivery->recordDelivery($lr->fresh(), $ctx['member'], [
            'delivery_date' => now()->toDateString(),
        ]);

        return $lr->fresh(['delivery', 'lorryHire']);
    }
}

if (! function_exists('brokerGrantLinkedBiltyPermissions')) {
    function brokerGrantLinkedBiltyPermissions(): void
    {
        foreach ([
            PermissionCodes::BROKER_LINKED_BILTY_VIEW,
            PermissionCodes::BROKER_LINKED_BILTY_CREATE,
            PermissionCodes::BROKER_LINKED_BILTY_PAY,
        ] as $code) {
            tripGrant('customer.admin', $code);
        }
    }
}

if (! function_exists('brokerCreateBrokerLrOnVehicle')) {
    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>|null  $goodsOverride
     */
    function brokerCreateBrokerLrOnVehicle(array $ctx, Vehicle $vehicle, ?array $goodsOverride = null): LorryReceipt
    {
        brokerGrantLorryHirePermissions();

        $goods = $goodsOverride ?? [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'goods_description' => 'Broker cartons',
            'package_count' => 10,
            'actual_weight' => 100,
            'charged_weight' => 100,
            'freight_rate_type' => FreightRateType::FixedAmount->value,
            'freight_rate' => 7500,
        ]];

        $consignor = app(PartyService::class)->create(
            actor: $ctx['owner'],
            company: $ctx['company'],
            branch: null,
            attributes: [
                'name' => 'Broker Consignor '.Str::uuid(),
                'contact_number' => '9800055002',
                'state' => 'MH',
                'city' => 'Mumbai',
                'is_gst_applicable' => false,
            ],
            roles: [['role' => PartyRoleType::Consignor->value]],
            remark: 'Broker consignor',
        );

        $consignee = app(PartyService::class)->create(
            actor: $ctx['owner'],
            company: $ctx['company'],
            branch: null,
            attributes: [
                'name' => 'Broker Consignee '.Str::uuid(),
                'contact_number' => '9800055003',
                'state' => 'MH',
                'city' => 'Pune',
                'is_gst_applicable' => false,
            ],
            roles: [['role' => PartyRoleType::Consignee->value]],
            remark: 'Broker consignee',
        );

        return app(LorryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
            'workflow_context' => LrWorkflowContext::Broker->value,
            'vehicle_id' => $vehicle->id,
            'number_mode' => 'auto',
            'lr_date' => now()->toDateString(),
            'from_text' => 'Mumbai',
            'to_text' => 'Pune',
            'consignor_party_id' => $consignor->id,
            'consignee_party_id' => $consignee->id,
            'goods' => $goods,
        ]);
    }
}

if (! function_exists('brokerGrantVoucherPermissions')) {
    function brokerGrantVoucherPermissions(): void
    {
        foreach ([
            PermissionCodes::BROKER_VOUCHER_VIEW,
            PermissionCodes::BROKER_VOUCHER_CREATE,
            PermissionCodes::BROKER_VOUCHER_EDIT,
            PermissionCodes::BROKER_VOUCHER_STATUS,
            PermissionCodes::BROKER_VOUCHER_BULK,
            PermissionCodes::BROKER_BOOK_BALANCE_VIEW,
        ] as $code) {
            tripGrant('customer.admin', $code);
        }
    }
}

if (! function_exists('brokerCreateLorryHire')) {
    /**
     * @param  array<string, mixed>  $ctx
     */
    function brokerCreateLorryHire(array $ctx): LorryHire
    {
        brokerGrantLorryHirePermissions();
        $lr = brokerCreateBrokerLr($ctx);

        return app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
            'goods_lines' => brokerGoodsPayloadFromLr($lr),
        ]);
    }
}

if (! function_exists('brokerWorkspace')) {
    /**
     * @return array<string, mixed>
     */
    function brokerWorkspace(): array
    {
        brokerGrantLorryHirePermissions();

        return tripWorkspace([SubscriptionModule::Broker->value]);
    }
}

if (! function_exists('brokerCreateTransporterAndMarketVehicle')) {
    /**
     * @param  array<string, mixed>  $ctx
     * @return array{transporter: Party, vehicle: Vehicle}
     */
    function brokerCreateTransporterAndMarketVehicle(array $ctx): array
    {
        $parties = app(PartyService::class);
        TenantContextHolder::inject(new TenantContext(
            companyId: $ctx['company']->id,
            branchId: $ctx['branch']->id,
            ownerId: (string) $ctx['company']->owner_user_id,
        ));

        $transporter = $parties->create(
            actor: $ctx['owner'],
            company: $ctx['company'],
            branch: null,
            attributes: [
                'name' => 'Broker Transporter',
                'contact_number' => '9800055001',
                'pan' => 'BRKTR5678B',
                'state' => 'MH',
                'city' => 'Thane',
                'is_gst_applicable' => false,
            ],
            roles: [[
                'role' => PartyRoleType::Transporter->value,
                'attributes' => [
                    'pan' => 'BRKTR5678B',
                    'pan_status' => 'active',
                    'declaration' => true,
                    'declaration_type' => 'owner',
                    'goods_vehicles_count' => 3,
                ],
            ]],
            remark: 'Broker transporter',
        );

        $vehicle = Vehicle::query()->create([
            'company_id' => $ctx['company']->id,
            'registration_number' => 'MH12BRK'.strtoupper(substr(md5((string) microtime(true)), 0, 4)),
            'is_active' => true,
            'ownership' => VehicleOwnership::Market->value,
            'owner_party_id' => $transporter->id,
        ]);

        return ['transporter' => $transporter, 'vehicle' => $vehicle];
    }
}

if (! function_exists('brokerCreateBrokerLr')) {
    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array<string, mixed>>|null  $goodsOverride
     */
    function brokerCreateBrokerLr(array $ctx, ?array $goodsOverride = null): LorryReceipt
    {
        $market = brokerCreateTransporterAndMarketVehicle($ctx);

        $goods = $goodsOverride ?? [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'goods_description' => 'Broker cartons',
            'package_count' => 10,
            'actual_weight' => 100,
            'charged_weight' => 100,
            'freight_rate_type' => FreightRateType::FixedAmount->value,
            'freight_rate' => 7500,
        ]];

        $consignor = app(PartyService::class)->create(
            actor: $ctx['owner'],
            company: $ctx['company'],
            branch: null,
            attributes: [
                'name' => 'Broker Consignor',
                'contact_number' => '9800055002',
                'state' => 'MH',
                'city' => 'Mumbai',
                'is_gst_applicable' => false,
            ],
            roles: [['role' => PartyRoleType::Consignor->value]],
            remark: 'Broker consignor',
        );

        $consignee = app(PartyService::class)->create(
            actor: $ctx['owner'],
            company: $ctx['company'],
            branch: null,
            attributes: [
                'name' => 'Broker Consignee',
                'contact_number' => '9800055003',
                'state' => 'MH',
                'city' => 'Pune',
                'is_gst_applicable' => false,
            ],
            roles: [['role' => PartyRoleType::Consignee->value]],
            remark: 'Broker consignee',
        );

        return app(LorryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
            'workflow_context' => LrWorkflowContext::Broker->value,
            'vehicle_id' => $market['vehicle']->id,
            'number_mode' => 'auto',
            'lr_date' => now()->toDateString(),
            'from_text' => 'Mumbai',
            'to_text' => 'Pune',
            'consignor_party_id' => $consignor->id,
            'consignee_party_id' => $consignee->id,
            'goods' => $goods,
        ]);
    }
}

if (! function_exists('brokerSetBillingCustomer')) {
    /**
     * @param  array<string, mixed>  $ctx
     */
    function brokerSetBillingCustomer(LorryReceipt $lr, array $ctx, ?Party $customer = null): Party
    {
        if ($customer === null) {
            $customer = app(PartyService::class)->create(
                actor: $ctx['owner'],
                company: $ctx['company'],
                branch: null,
                attributes: [
                    'name' => 'Broker Billing Customer',
                    'contact_number' => '9800055010',
                    'state' => 'MH',
                    'city' => 'Mumbai',
                    'is_gst_applicable' => false,
                ],
                roles: [['role' => PartyRoleType::Customer->value]],
                remark: 'Broker billing customer',
            );
        }

        $lr->forceFill(['billing_customer_party_id' => $customer->id])->save();

        return $customer;
    }
}

if (! function_exists('brokerGoodsPayloadFromLr')) {
    /**
     * @return list<array<string, mixed>>
     */
    function brokerGoodsPayloadFromLr(LorryReceipt $lr, ?array $overrides = null): array
    {
        $lr->loadMissing('goodsRows');
        $payload = [];

        foreach ($lr->goodsRows as $index => $row) {
            $payload[] = array_merge([
                'lr_goods_row_id' => $row->id,
                'rate_type' => $row->freight_rate_type->value,
                'rate' => $row->freight_rate,
                'is_fixed_amount' => $row->freight_rate_type === FreightRateType::FixedAmount,
            ], $overrides[$index] ?? []);
        }

        return $payload;
    }
}
