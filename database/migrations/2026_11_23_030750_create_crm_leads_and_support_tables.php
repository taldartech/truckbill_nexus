<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A Internal CRM foundations (PO-P2A-AUTH).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('contact_name');
            $table->string('email')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('company_name')->nullable();
            $table->string('source', 64);
            $table->string('interested_product', 64);
            $table->string('status', 32);
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->text('remarks')->nullable();
            $table->string('email_normalized', 255)->nullable();
            $table->boolean('duplicate_override')->default(false);
            $table->text('duplicate_override_remark')->nullable();
            $table->uuid('converted_company_id')->nullable();
            $table->unsignedBigInteger('converted_user_id')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->timestampTz('disqualified_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('assigned_to_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('converted_company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('converted_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['status', 'assigned_to_user_id']);
            $table->index(['email_normalized', 'interested_product']);
            $table->index('created_at');
        });

        Schema::create('crm_lead_follow_ups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('lead_id');
            $table->unsignedBigInteger('assigned_to_user_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestampTz('due_at');
            $table->text('notes')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('lead_id')->references('id')->on('crm_leads')->cascadeOnDelete();
            $table->foreign('assigned_to_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['assigned_to_user_id', 'due_at']);
        });

        Schema::create('crm_lead_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('lead_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('activity_type', 64);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestampsTz();

            $table->foreign('lead_id')->references('id')->on('crm_leads')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('crm_support_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject');
            $table->text('description');
            $table->string('category', 64);
            $table->string('priority', 32);
            $table->string('status', 32);
            $table->unsignedBigInteger('requester_user_id')->nullable();
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->uuid('related_company_id')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('requester_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_to_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('related_company_id')->references('id')->on('companies')->nullOnDelete();
            $table->index(['status', 'priority', 'assigned_to_user_id']);
            $table->index('due_at');
        });

        Schema::create('crm_support_ticket_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('activity_type', 64);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestampsTz();

            $table->foreign('ticket_id')->references('id')->on('crm_support_tickets')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_support_ticket_activities');
        Schema::dropIfExists('crm_support_tickets');
        Schema::dropIfExists('crm_lead_activities');
        Schema::dropIfExists('crm_lead_follow_ups');
        Schema::dropIfExists('crm_leads');
    }
};
