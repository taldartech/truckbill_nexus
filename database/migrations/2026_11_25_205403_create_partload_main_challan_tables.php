<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partload Main Challan — consolidates Draft Challans onto one onward vehicle movement (legacy Main Challan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partload_main_challans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('main_challan_number', 64);
            $table->date('main_challan_date');
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
            $table->decimal('other_charge', 18, 2)->default(0);
            $table->decimal('cash_received', 18, 2)->default(0);
            $table->decimal('cash_paid', 18, 2)->default(0);
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
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'main_challan_date']);
        });
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_main_challans',
            'partload_main_challans_company_number_active_unique',
            ['company_id', 'main_challan_number'],
        );

        Schema::create('partload_main_challan_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('partload_main_challan_id');
            $table->uuid('partload_challan_id');
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'partload_main_challan_id'], 'pl_main_lines_main_fk')
                ->references(['company_id', 'id'])
                ->on('partload_main_challans')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'partload_challan_id'], 'pl_main_lines_challan_fk')
                ->references(['company_id', 'id'])
                ->on('partload_challans')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'partload_main_challan_id'], 'pl_main_lines_main_idx');
            $table->index(['company_id', 'partload_challan_id'], 'pl_main_lines_challan_idx');
        });
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_main_challan_lines',
            'pl_main_lines_active_challan_unique',
            ['company_id', 'partload_challan_id'],
        );

        Schema::table('partload_challans', function (Blueprint $table): void {
            $table->uuid('partload_main_challan_id')->nullable()->after('to_account_party_id');
            $table->foreign(['company_id', 'partload_main_challan_id'], 'partload_challans_main_challan_fk')
                ->references(['company_id', 'id'])
                ->on('partload_main_challans')
                ->restrictOnDelete();
            $table->index(['company_id', 'partload_main_challan_id'], 'partload_challans_main_challan_idx');
        });
    }

    public function down(): void
    {
        Schema::table('partload_challans', function (Blueprint $table): void {
            $table->dropForeign('partload_challans_main_challan_fk');
            $table->dropIndex('partload_challans_main_challan_idx');
            $table->dropColumn('partload_main_challan_id');
        });

        SoftDeleteUniqueIndex::dropIndexIfExists('partload_main_challan_lines', 'partload_main_challan_lines_active_challan_unique');
        Schema::dropIfExists('partload_main_challan_lines');
        SoftDeleteUniqueIndex::dropIndexIfExists('partload_main_challans', 'partload_main_challans_company_number_active_unique');
        Schema::dropIfExists('partload_main_challans');
    }
};
