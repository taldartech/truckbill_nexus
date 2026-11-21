<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transactional outbox (ADR-009 / WS-6).
     */
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->string('event_type');
            $table->jsonb('payload');

            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('owner_id')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->boolean('is_owner_scope')->default(false);

            $table->string('correlation_id')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestampTz('available_at');
            $table->timestampTz('locked_at')->nullable();
            $table->string('locked_by')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['company_id', 'status']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
