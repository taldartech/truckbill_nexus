<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting foundation — Tally-style COA, periods, vouchers, posting links (Architecture §7 / ADR-010).
 * Distinct from Broker transporter_vouchers (operational payment documents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name', 128);
            $table->string('nature', 32);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->index(['company_id', 'nature']);
            $table->index('parent_id');
        });
        Schema::table('account_groups', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('account_groups')->nullOnDelete();
        });
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'account_groups',
            'account_groups_company_name_active_unique',
            ['company_id', 'name'],
        );

        Schema::create('gl_ledgers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('account_group_id');
            $table->string('name', 160);
            $table->string('nature', 32);
            $table->uuid('party_id')->nullable();
            $table->uuid('bank_cash_account_id')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('account_group_id')->references('id')->on('account_groups')->restrictOnDelete();
            $table->foreign('party_id')->references('id')->on('parties')->nullOnDelete();
            $table->foreign('bank_cash_account_id')->references('id')->on('bank_cash_accounts')->nullOnDelete();
            $table->index(['company_id', 'account_group_id']);
            $table->index(['company_id', 'party_id']);
        });
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'gl_ledgers',
            'gl_ledgers_company_name_active_unique',
            ['company_id', 'name'],
        );

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('label', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->unique(['company_id', 'label']);
            $table->index(['company_id', 'starts_on', 'ends_on']);
        });

        Schema::create('accounting_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('accounting_period_id');
            $table->string('voucher_type', 32);
            $table->string('voucher_number', 64);
            $table->date('voucher_date');
            $table->string('status', 16)->default('draft');
            $table->string('narration', 2000)->nullable();
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->uuid('reversal_of_voucher_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'accounting_vouchers_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
            $table->foreign('accounting_period_id')->references('id')->on('accounting_periods')->restrictOnDelete();
            $table->index(['company_id', 'voucher_date']);
            $table->index(['company_id', 'status']);
            $table->index('reversal_of_voucher_id');
        });
        Schema::table('accounting_vouchers', function (Blueprint $table): void {
            $table->foreign('reversal_of_voucher_id')
                ->references('id')
                ->on('accounting_vouchers')
                ->nullOnDelete();
        });
        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'accounting_vouchers',
            'accounting_vouchers_number_active_unique',
            ['company_id', 'voucher_type', 'voucher_number'],
        );

        Schema::create('accounting_voucher_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('accounting_voucher_id');
            $table->uuid('gl_ledger_id');
            $table->uuid('branch_id')->nullable();
            $table->unsignedSmallInteger('line_no');
            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->decimal('credit_amount', 18, 2)->default(0);
            $table->string('narration', 500)->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('accounting_voucher_id')->references('id')->on('accounting_vouchers')->cascadeOnDelete();
            $table->foreign('gl_ledger_id')->references('id')->on('gl_ledgers')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'accounting_voucher_lines_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
            $table->unique(['accounting_voucher_id', 'line_no']);
            $table->index(['company_id', 'gl_ledger_id']);
        });

        Schema::create('ledger_opening_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('accounting_period_id');
            $table->uuid('gl_ledger_id');
            $table->uuid('branch_id')->nullable();
            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->decimal('credit_amount', 18, 2)->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('accounting_period_id')->references('id')->on('accounting_periods')->restrictOnDelete();
            $table->foreign('gl_ledger_id')->references('id')->on('gl_ledgers')->restrictOnDelete();
            $table->unique(
                ['company_id', 'accounting_period_id', 'gl_ledger_id', 'branch_id'],
                'ledger_opening_balances_unique',
            );
        });

        Schema::create('accounting_posting_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('source_type', 64);
            $table->string('source_id', 64);
            $table->string('posting_purpose', 64);
            $table->uuid('accounting_voucher_id')->nullable();
            $table->string('status', 16)->default('posted');
            $table->string('failure_message', 1000)->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('accounting_voucher_id')->references('id')->on('accounting_vouchers')->nullOnDelete();
            $table->unique(
                ['company_id', 'source_type', 'source_id', 'posting_purpose'],
                'accounting_posting_links_source_unique',
            );
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_posting_links');
        Schema::dropIfExists('ledger_opening_balances');
        Schema::dropIfExists('accounting_voucher_lines');
        Schema::dropIfExists('accounting_vouchers');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('gl_ledgers');
        Schema::dropIfExists('account_groups');
    }
};
