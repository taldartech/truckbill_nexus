<?php

use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\LorryReceipt;
use App\Models\Party;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;

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
            'registration_number' => 'MH12BRK0001',
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
