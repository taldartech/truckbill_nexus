<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Batch C — voucher date + book-balance idempotency key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transporter_vouchers', function (Blueprint $table) {
            $table->date('voucher_date')->nullable()->after('voucher_number');
            $table->uuid('linked_bilty_group_id')->nullable()->after('lorry_hire_id');
            $table->string('pump_vendor_party_id')->nullable()->after('pump_reference');
        });

        Schema::table('transporter_book_balance_entries', function (Blueprint $table) {
            $table->date('entry_date')->nullable()->after('entry_type');
            $table->string('idempotency_key', 128)->nullable()->after('source_id');
            $table->uuid('reversal_of_entry_id')->nullable()->after('idempotency_key');
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'transporter_book_balance_entries',
            'tb_balance_company_idempotency_active_unique',
            ['company_id', 'idempotency_key'],
            extraWhere: 'idempotency_key IS NOT NULL',
        );
    }

    public function down(): void
    {
        SoftDeleteUniqueIndex::dropIndexIfExists(
            'transporter_book_balance_entries',
            'tb_balance_company_idempotency_active_unique',
        );

        Schema::table('transporter_book_balance_entries', function (Blueprint $table) {
            $table->dropColumn(['entry_date', 'idempotency_key', 'reversal_of_entry_id']);
        });

        Schema::table('transporter_vouchers', function (Blueprint $table) {
            $table->dropColumn(['voucher_date', 'linked_bilty_group_id', 'pump_vendor_party_id']);
        });
    }
};
