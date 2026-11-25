<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lorry_receipts', function (Blueprint $table): void {
            $table->string('external_gr_number', 100)->nullable()->after('lr_number');
            $table->string('client_invoice_number', 100)->nullable()->after('external_gr_number');
            $table->string('reference_by', 255)->nullable()->after('client_invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('lorry_receipts', function (Blueprint $table): void {
            $table->dropColumn([
                'external_gr_number',
                'client_invoice_number',
                'reference_by',
            ]);
        });
    }
};
