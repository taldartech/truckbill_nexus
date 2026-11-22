<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver-agnostic web session revocation stamp (P2-WS-4 follow-up).
 *
 * Enumerating Laravel session rows only works for the database session driver.
 * Production uses Redis (SESSION_DRIVER=redis). Bumping web_session_version
 * invalidates all sessions that do not carry the new stamp, regardless of store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('web_session_version')->default(0)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('web_session_version');
        });
    }
};
