<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lorry_hire_custom_amounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('lorry_hire_id')->constrained('lorry_hires')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index(['lorry_hire_id', 'company_id'], 'lorry_hire_custom_amounts_hire_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lorry_hire_custom_amounts');
    }
};
