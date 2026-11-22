<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-WS-1: Party master with multi-role support.
 *
 * Company Data Visibility setting UI remains Deferred (Phase 2 exception).
 * Parties always carry company_id; branch_id null = company-wide shared record;
 * branch_id set = branch-scoped record (Branch-wise Restricted usage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('email')->nullable();
            $table->string('gstin')->nullable();
            $table->string('pan')->nullable();
            $table->boolean('is_gst_applicable')->default(true);
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('party_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('party_id');
            $table->string('role');
            $table->json('role_attributes')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->unique(['party_id', 'role']);
            $table->index(['company_id', 'role']);
            $table->index('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_roles');
        Schema::dropIfExists('parties');
    }
};
