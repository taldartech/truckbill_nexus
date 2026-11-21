<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency store (ADR-009 / WS-6).
     *
     * Uniqueness is scoped by (scope, company_id, idempotency_key).
     */
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope');
            $table->string('idempotency_key');
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('owner_id')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->string('status', 32);
            $table->jsonb('result_payload')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'company_id', 'idempotency_key'], 'idempotency_scope_company_key_unique');
            $table->index(['expires_at']);
            $table->index(['company_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
