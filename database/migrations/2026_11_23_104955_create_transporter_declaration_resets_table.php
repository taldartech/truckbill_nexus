<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency markers for annual transporter declaration resets (India FY).
     */
    public function up(): void
    {
        Schema::create('transporter_declaration_resets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('financial_year');
            $table->timestamp('reset_at');
            $table->timestamps();

            $table->unique('financial_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transporter_declaration_resets');
    }
};
