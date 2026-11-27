<?php

use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\LorryReceipt;
use Illuminate\Support\Str;

require_once __DIR__.'/OwnFleetTripHelpers.php';

if (! function_exists('partloadGrantPermissions')) {
    function partloadGrantPermissions(): void
    {
        foreach ([
            PermissionCodes::LR_VIEW,
            PermissionCodes::LR_CREATE,
            PermissionCodes::PARTLOAD_CHALLAN_VIEW,
            PermissionCodes::PARTLOAD_CHALLAN_CREATE,
            PermissionCodes::PARTLOAD_CHALLAN_DISPATCH,
            PermissionCodes::PARTLOAD_CHALLAN_CANCEL,
            PermissionCodes::PARTLOAD_INWARD_VIEW,
            PermissionCodes::PARTLOAD_INWARD_CREATE,
            PermissionCodes::PARTLOAD_DR_VIEW,
            PermissionCodes::PARTLOAD_DR_CREATE,
        ] as $code) {
            tripGrant('customer.admin', $code);
        }
    }
}

if (! function_exists('partloadWorkspace')) {
    /**
     * @param  list<string>  $addonCodes
     * @return array<string, mixed>
     */
    function partloadWorkspace(array $addonCodes = []): array
    {
        $ctx = tripWorkspace([SubscriptionModule::Partload->value], $addonCodes);
        partloadGrantPermissions();

        $customer = app(PartyService::class)->create(
            actor: $ctx['owner'],
            company: $ctx['company'],
            branch: null,
            attributes: [
                'name' => 'Partload Customer '.Str::lower(Str::random(4)),
                'contact_number' => '9800099001',
                'state' => 'MH',
                'city' => 'Mumbai',
                'is_gst_applicable' => false,
            ],
            roles: [
                ['role' => PartyRoleType::Customer->value],
                ['role' => PartyRoleType::Consignor->value],
                ['role' => PartyRoleType::Consignee->value],
            ],
            remark: 'Partload customer',
        );

        $transporter = app(PartyService::class)->create(
            actor: $ctx['owner'],
            company: $ctx['company'],
            branch: null,
            attributes: [
                'name' => 'Partload Transporter '.Str::lower(Str::random(4)),
                'contact_number' => '9800099002',
                'pan' => 'AABCT9002E',
                'state' => 'MH',
                'city' => 'Mumbai',
                'is_gst_applicable' => false,
            ],
            roles: [[
                'role' => PartyRoleType::Transporter->value,
                'attributes' => [
                    'pan' => 'AABCT9002E',
                    'pan_status' => 'active',
                    'declaration' => true,
                    'declaration_type' => 'owner',
                    'tds_percentage' => '1.00',
                    'goods_vehicles_count' => 2,
                ],
            ]],
            remark: 'Partload transporter',
        );

        $ctx['vehicle']->forceFill([
            'ownership' => VehicleOwnership::Market,
            'owner_party_id' => $transporter->id,
            'truck_type_id' => null,
            'tyre_layout_id' => null,
            'primary_driver_party_id' => null,
        ])->save();

        $ctx['customer'] = $customer;
        $ctx['transporter'] = $transporter;

        return $ctx;
    }
}

if (! function_exists('partloadCreateBilty')) {
    function partloadCreateBilty(array $ctx, PartloadFreightPaymentTerm $term = PartloadFreightPaymentTerm::ToPay): LorryReceipt
    {
        return app(LorryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
            'workflow_context' => LrWorkflowContext::Partload->value,
            'lr_date' => now()->toDateString(),
            'from_station_id' => $ctx['fromStation']->id,
            'to_station_id' => $ctx['toStation']->id,
            'billing_customer_party_id' => $ctx['customer']->id,
            'consignor_party_id' => $ctx['customer']->id,
            'consignee_party_id' => $ctx['customer']->id,
            'freight_payment_term' => $term->value,
            'goods' => [[
                'goods_type_id' => $ctx['goodsType']->id,
                'package_type_id' => $ctx['packageType']->id,
                'goods_description' => 'Partload cartons',
                'package_count' => 5,
                'actual_weight' => 1000,
                'charged_weight' => 1000,
                'freight_rate_type' => FreightRateType::FixedAmount->value,
                'freight_rate' => 1500,
                'freight_amount' => 1500,
            ]],
        ]);
    }
}
