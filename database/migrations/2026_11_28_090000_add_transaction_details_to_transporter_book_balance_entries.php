<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transporter_book_balance_entries', function (Blueprint $table) {
            $table->string('payment_mode', 32)->nullable()->after('entry_date');
            $table->uuid('bank_cash_account_id')->nullable()->after('payment_mode');
            $table->string('reference_number', 64)->nullable()->after('bank_cash_account_id');

            $table->foreign(['company_id', 'bank_cash_account_id'], 'tb_balance_company_bank_fk')
                ->references(['company_id', 'id'])
                ->on('bank_cash_accounts')
                ->restrictOnDelete();
            $table->index(
                ['company_id', 'branch_id', 'transporter_party_id', 'entry_date'],
                'tb_balance_statement_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('transporter_book_balance_entries', function (Blueprint $table) {
            $table->dropForeign('tb_balance_company_bank_fk');
            $table->dropIndex('tb_balance_statement_idx');
            $table->dropColumn(['payment_mode', 'bank_cash_account_id', 'reference_number']);
        });
    }
};
