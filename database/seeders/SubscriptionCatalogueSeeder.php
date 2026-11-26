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
            ['code' => 'lorry_hire_voucher', 'name' => 'Lorry Hire/Voucher', 'annual_price' => '3999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 10],
            ['code' => 'invoice', 'name' => 'Invoice', 'annual_price' => '3999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 20],
            ['code' => 'advanced_expense', 'name' => 'Advanced Expense', 'annual_price' => '2499.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 30],
            ['code' => 'vehicle_documents', 'name' => 'Vehicle Documents', 'annual_price' => '1999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 40],
            ['code' => 'tyre', 'name' => 'Tyre', 'annual_price' => '3999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 50],
            ['code' => 'battery', 'name' => 'Battery', 'annual_price' => '1999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 60],
            ['code' => 'vehicle_maintenance', 'name' => 'Vehicle Maintenance', 'annual_price' => '2999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 70],
            ['code' => 'spare_parts_store', 'name' => 'Spare Parts/Store', 'annual_price' => '3999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 80],
            ['code' => 'workshop_job_cards', 'name' => 'Workshop/Job Cards', 'annual_price' => '3999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 90],
            ['code' => 'challan_main_challan', 'name' => 'Challan/Main Challan', 'annual_price' => '1999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 100],
            ['code' => 'inward_dr', 'name' => 'Inward/DR', 'annual_price' => '1999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 110],
            ['code' => 'accounting', 'name' => 'Accounting', 'annual_price' => '5999.00', 'unit_label' => null, 'is_quantifiable' => false, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 120],
            ['code' => 'extra_branch', 'name' => 'Additional Branches', 'annual_price' => '6500.00', 'unit_label' => 'branch / year', 'is_quantifiable' => true, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 130],
            ['code' => 'extra_user', 'name' => 'Additional Users', 'annual_price' => '500.00', 'unit_label' => 'user / year', 'is_quantifiable' => true, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 0, 'sort_order' => 140],
            ['code' => 'lr_credits_broker', 'name' => 'Broker LR/Bilty Credits (500 pack)', 'annual_price' => '1000.00', 'unit_label' => 'pack (500 LRs)', 'is_quantifiable' => true, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 500, 'sort_order' => 150],
            ['code' => 'lr_credits_partload', 'name' => 'Partload LR/Bilty Credits (500 pack)', 'annual_price' => '800.00', 'unit_label' => 'pack (500 LRs)', 'is_quantifiable' => true, 'storage_bytes_per_unit' => 0, 'credit_units_per_unit' => 500, 'sort_order' => 160],
            ['code' => 'extra_storage', 'name' => 'Additional Document Storage (5 GB)', 'annual_price' => '1200.00', 'unit_label' => '5 GB / year', 'is_quantifiable' => true, 'storage_bytes_per_unit' => 5368709120, 'credit_units_per_unit' => 0, 'sort_order' => 170],
        ];

        foreach ($addons as $addon) {
            $record = SubscriptionAddon::query()->firstOrCreate(
                [
                    'catalogue_version_id' => $version->id,
                    'code' => $addon['code'],
                ],
                [
                    'id' => (string) Str::uuid(),
                    'name' => $addon['name'],
                    'annual_price' => $addon['annual_price'],
                    'billing_period' => 'yearly',
                    'unit_label' => $addon['unit_label'],
                    'is_quantifiable' => $addon['is_quantifiable'],
                    'storage_bytes_per_unit' => $addon['storage_bytes_per_unit'],
                    'credit_units_per_unit' => $addon['credit_units_per_unit'],
                    'sort_order' => $addon['sort_order'],
                    'is_active' => true,
                ],
            );

            $record->forceFill([
                'unit_label' => $addon['unit_label'],
                'is_quantifiable' => $addon['is_quantifiable'],
                'storage_bytes_per_unit' => $addon['storage_bytes_per_unit'],
                'credit_units_per_unit' => $addon['credit_units_per_unit'],
                'sort_order' => $addon['sort_order'],
            ])->save();
        }
    }
}
