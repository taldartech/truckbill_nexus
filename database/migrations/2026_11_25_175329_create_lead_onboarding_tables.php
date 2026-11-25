<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead onboarding invitations, sessions, and email OTPs (legacy Admin parity).
 * Additive only — does not modify existing CRM lead rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_onboarding_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('lead_id');
            $table->string('email', 255);
            $table->string('email_normalized', 255);
            $table->string('token_hash', 128);
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('delivery_status', 32)->default('pending');
            $table->string('delivery_error', 500)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('completed_user_id')->nullable();
            $table->timestampsTz();

            $table->foreign('lead_id')->references('id')->on('crm_leads')->restrictOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('completed_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique('token_hash');
            $table->index(['lead_id', 'expires_at']);
            $table->index(['email_normalized', 'expires_at']);
        });

        Schema::create('lead_onboarding_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('invitation_id');
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('policy_accepted_at')->nullable();
            $table->json('accepted_policies')->nullable();
            $table->string('password_hash', 255)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('invitation_id')->references('id')->on('lead_onboarding_invitations')->cascadeOnDelete();
            $table->unique('invitation_id');
        });

        Schema::create('email_otps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('purpose', 64);
            $table->string('subject_type', 64);
            $table->uuid('subject_id');
            $table->string('email_normalized', 255);
            $table->string('otp_hash', 128);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->index(['purpose', 'subject_id', 'email_normalized']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otps');
        Schema::dropIfExists('lead_onboarding_sessions');
        Schema::dropIfExists('lead_onboarding_invitations');
    }
};
