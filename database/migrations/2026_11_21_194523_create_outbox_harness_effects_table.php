<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Isolated harness for proving outbox consumer side effects (WS-6 tests only).
     */
    public function up(): void
    {
        Schema::create('outbox_harness_effects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->string('company_id');
            $table->string('effect_key');
            $table->unsignedInteger('apply_count')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_harness_effects');
    }
};
