<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized City Master — cities belong to a State; same name allowed across States.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('state_id');
            $table->string('name', 120);
            $table->string('name_normalized', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('state_id')->references('id')->on('states')->restrictOnDelete();
            $table->unique(['state_id', 'name_normalized']);
            $table->index(['state_id', 'is_active', 'name']);
            $table->index(['name_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
