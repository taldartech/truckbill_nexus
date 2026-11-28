<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lorry_hire_goods_lines', function (Blueprint $table): void {
            $table->decimal('custom_amount', 18, 2)->nullable()->after('freight_amount');
        });

        Schema::create('lorry_hire_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('lorry_hire_id');
            $table->text('remark');
            $table->json('before');
            $table->json('after');
            $table->decimal('freight_delta', 18, 2);
            $table->decimal('tds_delta', 18, 2);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'lh_revisions_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_hire_id'], 'lh_revisions_company_hire_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_hires')
                ->restrictOnDelete();
            $table->index(['company_id', 'lorry_hire_id'], 'lh_revisions_company_hire_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lorry_hire_revisions');

        Schema::table('lorry_hire_goods_lines', function (Blueprint $table): void {
            $table->dropColumn('custom_amount');
        });
    }
};
