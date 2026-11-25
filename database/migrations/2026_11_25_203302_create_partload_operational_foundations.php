<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partload operational spine — Challan consolidation, Inward register, Delivery Receipt (Rules §12).
 * Reuses unified lorry_receipts (workflow_context=partload); does not invent a second trip engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lorry_receipts', function (Blueprint $table): void {
            $table->string('freight_payment_term', 16)->nullable()->after('additional_charges_total');
            $table->index(['company_id', 'freight_payment_term'], 'lorry_receipts_company_freight_term_idx');
        });

        Schema::create('partload_challans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('challan_number', 64);
            $table->date('challan_date');
            $table->uuid('from_station_id');
            $table->uuid('to_station_id');
            $table->uuid('vehicle_id');
            $table->uuid('driver_party_id');
            $table->string('ownership_mode', 16);
            $table->string('status', 32)->default('draft');
            $table->decimal('truck_freight', 18, 2)->default(0);
            $table->decimal('labour_charge', 18, 2)->default(0);
            $table->decimal('kat', 18, 2)->default(0);
            $table->decimal('home_delivery_charge', 18, 2)->default(0);
            $table->decimal('cash_received', 18, 2)->default(0);
            $table->decimal('cash_payment', 18, 2)->default(0);
            $table->decimal('total_packages', 18, 3)->default(0);
            $table->decimal('total_weight_kg', 18, 3)->default(0);
            $table->decimal('to_pay_amount', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('crossing_freight', 18, 2)->default(0);
            $table->decimal('cartage_dalali_pf', 18, 2)->default(0);
            $table->decimal('gst_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->uuid('to_account_party_id')->nullable();
            $table->text('remark')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign('from_station_id')->references('id')->on('stations')->restrictOnDelete();
            $table->foreign('to_station_id')->references('id')->on('stations')->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_id'])
                ->references(['company_id', 'id'])
                ->on('vehicles')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'driver_party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'to_account_party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->nullOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'challan_date']);
        });
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_challans',
            'partload_challans_company_number_active_unique',
            ['company_id', 'challan_number'],
        );

        Schema::create('partload_challan_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('partload_challan_id');
            $table->uuid('lorry_receipt_id');
            $table->uuid('to_station_id');
            $table->unsignedInteger('position')->default(1);
            $table->decimal('packages', 18, 3)->default(0);
            $table->decimal('weight_kg', 18, 3)->default(0);
            $table->decimal('freight_amount', 18, 2)->default(0);
            $table->string('freight_payment_term', 16)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'partload_challan_id'])
                ->references(['company_id', 'id'])
                ->on('partload_challans')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'])
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->foreign('to_station_id')->references('id')->on('stations')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'partload_challan_id']);
            $table->index(['company_id', 'lorry_receipt_id']);
        });
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_challan_lines',
            'partload_challan_lines_active_lr_unique',
            ['company_id', 'lorry_receipt_id'],
        );

        Schema::create('partload_inward_registers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('inward_number', 64);
            $table->date('inward_date');
            $table->uuid('partload_challan_id');
            $table->uuid('from_station_id');
            $table->uuid('to_station_id');
            $table->uuid('vehicle_id')->nullable();
            $table->uuid('driver_party_id')->nullable();
            $table->string('status', 32)->default('received');
            $table->text('remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'partload_challan_id'])
                ->references(['company_id', 'id'])
                ->on('partload_challans')
                ->restrictOnDelete();
            $table->foreign('from_station_id')->references('id')->on('stations')->restrictOnDelete();
            $table->foreign('to_station_id')->references('id')->on('stations')->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_id'])
                ->references(['company_id', 'id'])
                ->on('vehicles')
                ->nullOnDelete();
            $table->foreign(['company_id', 'driver_party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->nullOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'branch_id', 'status']);
        });
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_inward_registers',
            'partload_inwards_company_number_active_unique',
            ['company_id', 'inward_number'],
        );
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_inward_registers',
            'partload_inwards_challan_active_unique',
            ['company_id', 'partload_challan_id'],
        );

        Schema::create('partload_inward_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('partload_inward_register_id');
            $table->uuid('lorry_receipt_id');
            $table->uuid('partload_challan_line_id')->nullable();
            $table->unsignedInteger('position')->default(1);
            $table->decimal('packages', 18, 3)->default(0);
            $table->decimal('weight_kg', 18, 3)->default(0);
            $table->string('disposition', 32)->default('delivery');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'partload_inward_register_id'])
                ->references(['company_id', 'id'])
                ->on('partload_inward_registers')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'])
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'partload_challan_line_id'])
                ->references(['company_id', 'id'])
                ->on('partload_challan_lines')
                ->nullOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'partload_inward_register_id']);
        });
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_inward_lines',
            'partload_inward_lines_active_lr_unique',
            ['company_id', 'lorry_receipt_id', 'partload_inward_register_id'],
        );

        Schema::create('partload_delivery_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('dr_number', 64);
            $table->date('dr_date');
            $table->uuid('lorry_receipt_id');
            $table->uuid('partload_inward_line_id')->nullable();
            $table->uuid('consignee_party_id')->nullable();
            $table->string('payment_mode', 16);
            $table->uuid('payment_due_from_party_id')->nullable();
            $table->uuid('bank_cash_account_id')->nullable();
            $table->decimal('freight', 18, 2)->default(0);
            $table->decimal('handling_charge', 18, 2)->default(0);
            $table->decimal('delivery_charge', 18, 2)->default(0);
            $table->decimal('stationery_charge', 18, 2)->default(0);
            $table->decimal('demurrage', 18, 2)->default(0);
            $table->decimal('cartage_charge', 18, 2)->default(0);
            $table->decimal('other_charge', 18, 2)->default(0);
            $table->decimal('gst_amount', 18, 2)->default(0);
            $table->decimal('less_amount', 18, 2)->default(0);
            $table->decimal('final_amount', 18, 2)->default(0);
            $table->string('status', 32)->default('posted');
            $table->text('remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'])
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'partload_inward_line_id'])
                ->references(['company_id', 'id'])
                ->on('partload_inward_lines')
                ->nullOnDelete();
            $table->foreign(['company_id', 'consignee_party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->nullOnDelete();
            $table->foreign(['company_id', 'payment_due_from_party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->nullOnDelete();
            $table->foreign('bank_cash_account_id')->references('id')->on('bank_cash_accounts')->nullOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'branch_id', 'status']);
        });
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_delivery_receipts',
            'partload_dr_company_number_active_unique',
            ['company_id', 'dr_number'],
        );
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_delivery_receipts',
            'partload_dr_lr_active_unique',
            ['company_id', 'lorry_receipt_id'],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('partload_delivery_receipts');
        Schema::dropIfExists('partload_inward_lines');
        Schema::dropIfExists('partload_inward_registers');
        Schema::dropIfExists('partload_challan_lines');
        Schema::dropIfExists('partload_challans');

        Schema::table('lorry_receipts', function (Blueprint $table): void {
            $table->dropIndex('lorry_receipts_company_freight_term_idx');
            $table->dropColumn('freight_payment_term');
        });
    }
};
