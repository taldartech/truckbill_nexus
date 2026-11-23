<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-WS-2: companies, branches, memberships, branch assignments, Owner designation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_user_id');
            $table->index('deactivated_at');
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index('company_id');
            $table->index('deactivated_at');
        });

        Schema::create('company_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'user_id']);
            $table->index('deactivated_at');
        });

        Schema::create('branch_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('company_membership_id');
            $table->uuid('branch_id');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'company_membership_id'])
                ->references(['company_id', 'id'])
                ->on('company_memberships')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();

            $table->index(['company_membership_id', 'branch_id']);
            $table->index('deactivated_at');
        });

        // Exactly one active membership per user/company.
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'company_memberships',
            'company_memberships_active_user_company_unique',
            ['company_id', 'user_id'],
            null,
            'deactivated_at IS NULL AND deleted_at IS NULL',
        );

        // Exactly one active assignment per membership/branch.
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'branch_assignments',
            'branch_assignments_active_membership_branch_unique',
            ['company_membership_id', 'branch_id'],
            null,
            'deactivated_at IS NULL AND deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_assignments');
        Schema::dropIfExists('company_memberships');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
    }
};
