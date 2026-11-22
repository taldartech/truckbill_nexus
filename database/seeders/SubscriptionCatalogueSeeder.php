<?php

namespace Database\Seeders;

use App\Models\SubscriptionAddon;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Initial configurable catalogue values from Rules §15 (not hardcoded forever).
 */
class SubscriptionCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $version = SubscriptionCatalogueVersion::query()->firstOrCreate(
            ['code' => 'v2026-initial'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Initial Phase 3 catalogue',
                'effective_from' => now(),
                'is_active' => true,
            ],
        );

        $plans = [
            ['module' => 'broker', 'code' => 'micro', 'name' => 'Broker Micro', 'annual_price' => '5000.00', 'capacity_units' => 600, 'sort_order' => 1],
            ['module' => 'broker', 'code' => 'starter', 'name' => 'Broker Starter', 'annual_price' => '9000.00', 'capacity_units' => 1200, 'sort_order' => 2],
            ['module' => 'broker', 'code' => 'growth', 'name' => 'Broker Growth', 'annual_price' => '12500.00', 'capacity_units' => 3600, 'sort_order' => 3],
            ['module' => 'broker', 'code' => 'business', 'name' => 'Broker Business', 'annual_price' => '30000.00', 'capacity_units' => 10000, 'sort_order' => 4],
            ['module' => 'ownfleet', 'code' => 'lite', 'name' => 'OwnFleet Lite', 'annual_price' => '5000.00', 'capacity_units' => 5, 'sort_order' => 1],
            ['module' => 'ownfleet', 'code' => 'starter', 'name' => 'OwnFleet Starter', 'annual_price' => '10000.00', 'capacity_units' => 15, 'sort_order' => 2],
            ['module' => 'ownfleet', 'code' => 'growth', 'name' => 'OwnFleet Growth', 'annual_price' => '20000.00', 'capacity_units' => 50, 'sort_order' => 3],
            ['module' => 'ownfleet', 'code' => 'business', 'name' => 'OwnFleet Business', 'annual_price' => '36000.00', 'capacity_units' => 100, 'sort_order' => 4],
            ['module' => 'partload', 'code' => 'starter', 'name' => 'Partload Starter', 'annual_price' => '4000.00', 'capacity_units' => 2000, 'sort_order' => 1],
            ['module' => 'partload', 'code' => 'growth', 'name' => 'Partload Growth', 'annual_price' => '9000.00', 'capacity_units' => 6000, 'sort_order' => 2],
            ['module' => 'partload', 'code' => 'business', 'name' => 'Partload Business', 'annual_price' => '25000.00', 'capacity_units' => 18000, 'sort_order' => 3],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::query()->firstOrCreate(
                [
                    'catalogue_version_id' => $version->id,
                    'module' => $plan['module'],
                    'code' => $plan['code'],
                ],
                [
                    'id' => (string) Str::uuid(),
                    'name' => $plan['name'],
                    'annual_price' => $plan['annual_price'],
                    'capacity_units' => $plan['capacity_units'],
                    'sort_order' => $plan['sort_order'],
                    'is_active' => true,
                ],
            );
        }

        $addons = [
            ['code' => 'lorry_hire_voucher', 'name' => 'Lorry Hire/Voucher', 'annual_price' => '3999.00'],
            ['code' => 'invoice', 'name' => 'Invoice', 'annual_price' => '3999.00'],
            ['code' => 'advanced_expense', 'name' => 'Advanced Expense', 'annual_price' => '2499.00'],
            ['code' => 'vehicle_documents', 'name' => 'Vehicle Documents', 'annual_price' => '1999.00'],
            ['code' => 'tyre', 'name' => 'Tyre', 'annual_price' => '3999.00'],
            ['code' => 'battery', 'name' => 'Battery', 'annual_price' => '1999.00'],
            ['code' => 'vehicle_maintenance', 'name' => 'Vehicle Maintenance', 'annual_price' => '2999.00'],
            ['code' => 'spare_parts_store', 'name' => 'Spare Parts/Store', 'annual_price' => '3999.00'],
            ['code' => 'workshop_job_cards', 'name' => 'Workshop/Job Cards', 'annual_price' => '3999.00'],
            ['code' => 'challan_main_challan', 'name' => 'Challan/Main Challan', 'annual_price' => '1999.00'],
            ['code' => 'inward_dr', 'name' => 'Inward/DR', 'annual_price' => '1999.00'],
            ['code' => 'accounting', 'name' => 'Accounting', 'annual_price' => '5999.00'],
        ];

        foreach ($addons as $addon) {
            SubscriptionAddon::query()->firstOrCreate(
                [
                    'catalogue_version_id' => $version->id,
                    'code' => $addon['code'],
                ],
                [
                    'id' => (string) Str::uuid(),
                    'name' => $addon['name'],
                    'annual_price' => $addon['annual_price'],
                    'billing_period' => 'yearly',
                    'is_active' => true,
                ],
            );
        }
    }
}
