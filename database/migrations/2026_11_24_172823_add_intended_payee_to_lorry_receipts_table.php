<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator Pay To intent captured on Bilty (legacy transporter_type / transporter_id).
 * Does not create a Lorry Hire or financial obligation — carried into hire create for confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lorry_receipts', function (Blueprint $table): void {
            $table->string('intended_payee_type', 16)->nullable()->after('vehicle_id');
            $table->foreignUuid('intended_payee_party_id')
                ->nullable()
                ->after('intended_payee_type')
                ->constrained('parties')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lorry_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('intended_payee_party_id');
            $table->dropColumn('intended_payee_type');
        });
    }
};
