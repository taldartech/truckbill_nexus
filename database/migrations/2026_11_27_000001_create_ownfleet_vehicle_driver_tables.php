<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OwnFleet Vehicle Driver Assignments & Attendance exception register.
 * Driver identity reuses Party Master with PartyRoleType::Driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_driver_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('vehicle_id');
            $table->uuid('driver_party_id');
            $table->date('assigned_from');
            $table->date('assigned_to')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'vda_assign_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_id'], 'vda_assign_company_vehicle_fk')
                ->references(['company_id', 'id'])
                ->on('vehicles')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'driver_party_id'], 'vda_assign_company_driver_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();

            $table->unique(['company_id', 'id'], 'vda_assign_company_id_unique');
            $table->index(['company_id', 'vehicle_id', 'assigned_from'], 'vda_assign_vehicle_from_idx');
            $table->index(['company_id', 'driver_party_id', 'assigned_from'], 'vda_assign_driver_from_idx');
        });

        Schema::create('vehicle_driver_attendances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('vehicle_id');
            $table->date('attendance_date');
            $table->uuid('primary_driver_party_id');
            $table->string('status', 32);
            $table->uuid('temporary_driver_party_id')->nullable();
            $table->text('remarks')->nullable();
            $table->string('marked_via', 32)->default('branch_panel');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'vda_att_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_id'], 'vda_att_company_vehicle_fk')
                ->references(['company_id', 'id'])
                ->on('vehicles')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'primary_driver_party_id'], 'vda_att_company_primary_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'temporary_driver_party_id'], 'vda_att_company_temp_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();

            $table->unique(['company_id', 'id'], 'vda_att_company_id_unique');
            $table->index(['company_id', 'attendance_date'], 'vda_att_company_date_idx');
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'vehicle_driver_attendances',
            'vda_company_vehicle_date_active_unique',
            ['company_id', 'vehicle_id', 'attendance_date'],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_driver_attendances');
        Schema::dropIfExists('vehicle_driver_assignments');
    }
};
