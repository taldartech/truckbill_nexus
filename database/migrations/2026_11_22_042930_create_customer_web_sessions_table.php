<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-WS-4: Customer single active web-session registry (ADR-006).
 *
 * Stores only the Laravel session id reference — not cookies, CSRF tokens or passwords.
 * Internal Team multi-session does not use this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_web_sessions', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('session_id');
            $table->timestamps();

            $table->unique('session_id');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_web_sessions');
    }
};
