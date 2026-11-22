<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->string('status', 32);
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'email', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("
            ALTER TABLE invitations
            ADD CONSTRAINT invitations_status_check
            CHECK (status IN ('pending', 'accepted', 'revoked', 'expired'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
