<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Batch B: durable request fingerprint + webhook nonce replay store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiry_external_references', function (Blueprint $table) {
            $table->string('request_fingerprint', 64)->nullable()->after('source');
        });

        Schema::create('enquiry_webhook_nonces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('nonce', 128);
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->unique(['company_id', 'nonce']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_webhook_nonces');

        Schema::table('enquiry_external_references', function (Blueprint $table) {
            $table->dropColumn('request_fingerprint');
        });
    }
};
