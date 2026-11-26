<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_branch_access_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invitation_id')->constrained('invitations')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['invitation_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_branch_access_intents');
    }
};
