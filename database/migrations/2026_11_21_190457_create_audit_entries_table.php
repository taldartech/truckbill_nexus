<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit log (ADR-003 / WS-4).
     */
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('actor_type', 32);
            $table->string('actor_id')->nullable();

            $table->string('company_id')->nullable()->index();
            $table->string('branch_id')->nullable();
            $table->string('owner_id')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->boolean('is_owner_scope')->default(false);

            $table->string('subject_type');
            $table->string('subject_id');
            $table->string('action', 32);

            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            $table->text('remark')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('cancellation_at')->nullable();

            $table->string('correlation_id')->nullable()->index();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
