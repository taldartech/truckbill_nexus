<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Batch A (PO LR classification):
 * - Explicit Own/Market vehicle ownership (nullable — do not guess historical rows).
 * - Truck Type / Tyre Layout for Own vehicles; Transporter owner party for Market.
 * - Tenant-safe LR → Vehicle association (nullable for historical / Partload).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('ownership', 16)->nullable()->after('is_active');
            $table->uuid('truck_type_id')->nullable()->after('ownership');
            $table->uuid('tyre_layout_id')->nullable()->after('truck_type_id');
            $table->uuid('owner_party_id')->nullable()->after('tyre_layout_id');

            $table->foreign('truck_type_id', 'vehicles_truck_type_fk')
                ->references('id')
                ->on('owner_catalogue_items')
                ->nullOnDelete();
            $table->foreign('tyre_layout_id', 'vehicles_tyre_layout_fk')
                ->references('id')
                ->on('owner_catalogue_items')
                ->nullOnDelete();
            $table->foreign(['company_id', 'owner_party_id'], 'vehicles_company_owner_party_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();

            $table->index(['company_id', 'ownership', 'is_active'], 'vehicles_company_ownership_active_idx');
        });

        Schema::table('lorry_receipts', function (Blueprint $table) {
            $table->uuid('vehicle_id')->nullable()->after('consignee_party_id');
            $table->uuid('billing_customer_party_id')->nullable()->after('vehicle_id');

            $table->foreign(['company_id', 'vehicle_id'], 'lorry_receipts_company_vehicle_fk')
                ->references(['company_id', 'id'])
                ->on('vehicles')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'billing_customer_party_id'], 'lorry_receipts_company_billing_customer_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();

            $table->index(['company_id', 'vehicle_id'], 'lorry_receipts_company_vehicle_idx');
            $table->index(['company_id', 'billing_customer_party_id'], 'lorry_receipts_company_billing_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lorry_receipts', function (Blueprint $table) {
            $table->dropForeign('lorry_receipts_company_billing_customer_fk');
            $table->dropForeign('lorry_receipts_company_vehicle_fk');
            $table->dropIndex('lorry_receipts_company_billing_customer_idx');
            $table->dropIndex('lorry_receipts_company_vehicle_idx');
            $table->dropColumn(['vehicle_id', 'billing_customer_party_id']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign('vehicles_company_owner_party_fk');
            $table->dropForeign('vehicles_tyre_layout_fk');
            $table->dropForeign('vehicles_truck_type_fk');
            $table->dropIndex('vehicles_company_ownership_active_idx');
            $table->dropColumn(['ownership', 'truck_type_id', 'tyre_layout_id', 'owner_party_id']);
        });
    }
};
