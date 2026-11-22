<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable ownership transfer history (P2-WS-7).
 * Never hard-deleted or restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ownership_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('previous_owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('new_owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('remark');
            $table->timestamp('transferred_at');
            $table->timestamps();

            $table->index(['company_id', 'transferred_at']);
            $table->index('previous_owner_user_id');
            $table->index('new_owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ownership_transfers');
    }
};
