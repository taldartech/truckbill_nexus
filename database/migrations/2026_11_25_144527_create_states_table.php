<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized State Master (legacy Platform → Locations).
 * Platform-governed; ordinary Owners cannot mutate GST state codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('code', 8);
            $table->string('gst_code', 2);
            $table->string('country_code', 2)->default('IN');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_code', 'code']);
            $table->unique(['country_code', 'gst_code']);
            $table->unique(['country_code', 'name']);
            $table->index(['country_code', 'is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
