<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 OwnFleet trip expenses including diesel fields (Rules §9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_cash_accounts', function (Blueprint $table) {
            $table->unique(['company_id', 'id'], 'bank_cash_accounts_company_id_unique');
        });

        Schema::create('trip_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('trip_id');
            $table->uuid('expense_category_id');
            $table->date('expense_date');
            $table->decimal('amount', 18, 2);
            $table->uuid('payment_mode_id')->nullable();
            $table->uuid('paid_from_bank_cash_account_id')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_diesel')->default(false);
            $table->decimal('diesel_litres', 18, 3)->nullable();
            $table->decimal('diesel_rate_per_litre', 18, 4)->nullable();
            $table->decimal('diesel_odometer', 12, 1)->nullable();
            $table->decimal('planned_diesel_quantity', 18, 3)->nullable();
            $table->decimal('planned_diesel_rate', 18, 4)->nullable();
            $table->decimal('planned_diesel_amount', 18, 2)->nullable();
            $table->decimal('planned_efficiency', 18, 3)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'trip_id'], 'trip_expenses_company_trip_fk')
                ->references(['company_id', 'id'])
                ->on('trips')
                ->restrictOnDelete();
            $table->foreign('expense_category_id')->references('id')->on('owner_catalogue_items')->restrictOnDelete();
            $table->foreign('payment_mode_id')->references('id')->on('payment_modes')->nullOnDelete();
            $table->foreign(['company_id', 'paid_from_bank_cash_account_id'], 'trip_expenses_company_bank_fk')
                ->references(['company_id', 'id'])
                ->on('bank_cash_accounts')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'trip_id'], 'trip_expenses_company_trip_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_expenses');

        Schema::table('bank_cash_accounts', function (Blueprint $table) {
            $table->dropUnique('bank_cash_accounts_company_id_unique');
        });
    }
};
