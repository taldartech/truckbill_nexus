<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Isolated Phase 1 foundation harness for audit atomicity tests.
     * Not a product business module.
     */
    public function up(): void
    {
        Schema::create('audit_harness_records', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->string('secret_token')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_harness_records');
    }
};
