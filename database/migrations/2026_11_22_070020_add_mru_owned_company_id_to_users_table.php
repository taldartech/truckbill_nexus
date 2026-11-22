<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side MRU owned-company preference (PO-DEC-P2-REVIEW-006).
 * Never authorizes access by itself — always revalidated on use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('mru_owned_company_id')->nullable()->after('web_session_version');
            $table->foreign('mru_owned_company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['mru_owned_company_id']);
            $table->dropColumn('mru_owned_company_id');
        });
    }
};
