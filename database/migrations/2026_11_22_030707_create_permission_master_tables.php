<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-WS-3: Permission Master, database-managed roles, branch role assignments.
 *
 * Super Admin remains users.is_super_admin — never a roles row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 32);
            $table->string('module');
            $table->string('submodule')->nullable();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code');
            $table->index(['domain', 'module']);
            $table->index('deactivated_at');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 32);
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['domain', 'slug']);
            $table->index(['domain', 'is_default']);
            $table->index('deactivated_at');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $table->index(['role_id', 'permission_id']);
            $table->index('deactivated_at');
        });

        Schema::create('internal_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('role_id');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->index(['user_id', 'role_id']);
            $table->index('deactivated_at');
        });

        Schema::create('branch_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('company_membership_id');
            $table->uuid('branch_id');
            $table->uuid('branch_assignment_id');
            $table->uuid('role_id');
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
            $table->foreign('branch_assignment_id')->references('id')->on('branch_assignments')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();

            $table->index(['company_membership_id', 'branch_id']);
            $table->index('deactivated_at');
        });

        DB::statement("
            ALTER TABLE permissions
            ADD CONSTRAINT permissions_domain_check
            CHECK (domain IN ('internal', 'customer'))
        ");

        DB::statement("
            ALTER TABLE roles
            ADD CONSTRAINT roles_domain_check
            CHECK (domain IN ('internal', 'customer'))
        ");

        DB::statement('
            CREATE UNIQUE INDEX role_permissions_active_unique
            ON role_permissions (role_id, permission_id)
            WHERE deactivated_at IS NULL AND deleted_at IS NULL
        ');

        // One active ordinary Internal role per Internal identity.
        DB::statement('
            CREATE UNIQUE INDEX internal_role_assignments_active_user_unique
            ON internal_role_assignments (user_id)
            WHERE deactivated_at IS NULL AND deleted_at IS NULL
        ');

        // One active Customer role per membership+branch.
        DB::statement('
            CREATE UNIQUE INDEX branch_role_assignments_active_membership_branch_unique
            ON branch_role_assignments (company_membership_id, branch_id)
            WHERE deactivated_at IS NULL AND deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_role_assignments');
        Schema::dropIfExists('internal_role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
