<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional Reference By Party Master relationship.
 * Preserves historical free-text `reference_by` values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lorry_receipts', function (Blueprint $table): void {
            $table->uuid('reference_by_party_id')->nullable()->after('reference_by');
            $table->foreign('reference_by_party_id')
                ->references('id')
                ->on('parties')
                ->nullOnDelete();
            $table->index('reference_by_party_id');
        });
    }

    public function down(): void
    {
        Schema::table('lorry_receipts', function (Blueprint $table): void {
            $table->dropForeign(['reference_by_party_id']);
            $table->dropIndex(['reference_by_party_id']);
            $table->dropColumn('reference_by_party_id');
        });
    }
};
