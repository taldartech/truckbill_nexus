<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 OwnFleet Trip foundation (1 OwnFleet LR = 1 Trip) + multi-point tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Tenant-safe composite uniqueness required for composite FKs (PostgreSQL).
            $table->unique(['company_id', 'id'], 'vehicles_company_id_unique');
            $table->uuid('primary_driver_party_id')->nullable()->after('is_active');
            $table->foreign(['company_id', 'primary_driver_party_id'], 'vehicles_company_primary_driver_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('lorry_receipt_id');
            $table->string('status', 32);
            $table->uuid('vehicle_id');
            $table->uuid('driver_party_id');
            $table->uuid('primary_driver_party_id')->nullable();
            $table->uuid('substitute_driver_party_id')->nullable();
            $table->decimal('starting_odometer', 12, 1)->nullable();
            $table->timestampTz('departure_at')->nullable();
            $table->decimal('ending_odometer', 12, 1)->nullable();
            $table->timestampTz('actual_arrival_at')->nullable();
            $table->decimal('trip_distance', 12, 1)->nullable();
            $table->timestampTz('expected_end_at')->nullable();
            $table->string('timing_performance', 32)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'trips_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'], 'trips_company_lr_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_id'], 'trips_company_vehicle_fk')
                ->references(['company_id', 'id'])
                ->on('vehicles')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'driver_party_id'], 'trips_company_driver_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'primary_driver_party_id'], 'trips_company_primary_driver_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'substitute_driver_party_id'], 'trips_company_sub_driver_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'branch_id', 'status'], 'trips_company_branch_status_idx');
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'trips',
            'trips_company_lr_active_unique',
            ['company_id', 'lorry_receipt_id'],
        );

        Schema::create('trip_pickups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('trip_id');
            $table->unsignedInteger('position');
            $table->uuid('station_id');
            $table->uuid('consignor_party_id')->nullable();
            $table->string('goods_description')->nullable();
            $table->uuid('lr_goods_row_id')->nullable();
            $table->decimal('weight', 18, 3);
            $table->date('pickup_date');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'trip_id'], 'trip_pickups_company_trip_fk')
                ->references(['company_id', 'id'])
                ->on('trips')
                ->restrictOnDelete();
            $table->foreign('station_id')->references('id')->on('stations')->restrictOnDelete();
            $table->foreign(['company_id', 'consignor_party_id'], 'trip_pickups_company_consignor_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'trip_id'], 'trip_pickups_company_trip_idx');
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'trip_pickups',
            'trip_pickups_trip_position_active_unique',
            ['trip_id', 'position'],
        );

        Schema::create('trip_drops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('trip_id');
            $table->unsignedInteger('position');
            $table->uuid('station_id');
            $table->uuid('consignee_party_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'trip_id'], 'trip_drops_company_trip_fk')
                ->references(['company_id', 'id'])
                ->on('trips')
                ->restrictOnDelete();
            $table->foreign('station_id')->references('id')->on('stations')->restrictOnDelete();
            $table->foreign(['company_id', 'consignee_party_id'], 'trip_drops_company_consignee_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'trip_id'], 'trip_drops_company_trip_idx');
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'trip_drops',
            'trip_drops_trip_position_active_unique',
            ['trip_id', 'position'],
        );

        Schema::create('trip_drop_pickup_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('trip_id');
            $table->uuid('trip_drop_id');
            $table->uuid('trip_pickup_id');
            $table->decimal('allocation_weight', 18, 3);
            $table->decimal('rate', 18, 4)->default(0);
            $table->decimal('freight', 18, 2)->default(0);
            $table->decimal('distance', 12, 1)->nullable();
            $table->decimal('package_count', 18, 3)->nullable();
            $table->string('ewb_number', 64)->nullable();
            $table->decimal('goods_value', 18, 2)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'trip_id'], 'tdpa_company_trip_fk')
                ->references(['company_id', 'id'])
                ->on('trips')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'trip_drop_id'], 'tdpa_company_drop_fk')
                ->references(['company_id', 'id'])
                ->on('trip_drops')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'trip_pickup_id'], 'tdpa_company_pickup_fk')
                ->references(['company_id', 'id'])
                ->on('trip_pickups')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'trip_pickup_id'], 'tdpa_company_pickup_idx');
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'trip_drop_pickup_allocations',
            'tdpa_drop_pickup_active_unique',
            ['trip_drop_id', 'trip_pickup_id'],
        );
    }

    public function down(): void
    {
        SoftDeleteUniqueIndex::dropIndexIfExists('trip_drop_pickup_allocations', 'tdpa_drop_pickup_active_unique');
        Schema::dropIfExists('trip_drop_pickup_allocations');

        SoftDeleteUniqueIndex::dropIndexIfExists('trip_drops', 'trip_drops_trip_position_active_unique');
        Schema::dropIfExists('trip_drops');

        SoftDeleteUniqueIndex::dropIndexIfExists('trip_pickups', 'trip_pickups_trip_position_active_unique');
        Schema::dropIfExists('trip_pickups');

        SoftDeleteUniqueIndex::dropIndexIfExists('trips', 'trips_company_lr_active_unique');
        Schema::dropIfExists('trips');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign('vehicles_company_primary_driver_fk');
            $table->dropColumn('primary_driver_party_id');
            $table->dropUnique('vehicles_company_id_unique');
        });
    }
};
