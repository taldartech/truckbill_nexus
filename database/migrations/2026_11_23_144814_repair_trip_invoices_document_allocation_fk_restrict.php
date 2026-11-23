<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align trip_invoices → document_number_allocations FK with MySQL-safe ON DELETE RESTRICT.
 *
 * Fresh installs already create restrictOnDelete() in the create migration. This forward
 * repair replaces legacy ON DELETE SET NULL on databases that applied the earlier definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trip_invoices')) {
            return;
        }

        Schema::table('trip_invoices', function (Blueprint $table): void {
            $table->dropForeign('trip_invoices_company_doc_alloc_fk');
        });

        Schema::table('trip_invoices', function (Blueprint $table): void {
            $table->foreign(['company_id', 'document_number_allocation_id'], 'trip_invoices_company_doc_alloc_fk')
                ->references(['company_id', 'id'])
                ->on('document_number_allocations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Irreversible on MySQL: ON DELETE SET NULL is rejected when company_id is NOT NULL.
        // Keep restrictOnDelete() as the supported cross-engine contract.
    }
};
