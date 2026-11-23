<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-LR Broker customer invoices: line-level LR linkage + tax/TDS money columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_invoices', function (Blueprint $table) {
            $table->decimal('taxable_total', 18, 2)->default(0)->after('invoice_date');
            $table->decimal('gst_amount', 18, 2)->default(0)->after('taxable_total');
            $table->decimal('customer_tds_amount', 18, 2)->default(0)->after('amount_received');
            $table->json('gst_rate_snapshot')->nullable()->after('customer_tds_amount');
            $table->string('payment_status', 32)->default('unpaid')->after('status');
        });

        Schema::table('broker_invoice_lines', function (Blueprint $table) {
            $table->uuid('lorry_receipt_id')->nullable()->after('broker_invoice_id');
            $table->uuid('lorry_hire_id')->nullable()->after('lorry_receipt_id');
            $table->string('memo_number', 64)->nullable()->after('lorry_hire_id');
            $table->string('pod_number', 64)->nullable()->after('memo_number');
            $table->decimal('freight_amount', 18, 2)->default(0)->after('description');
            $table->decimal('charges_amount', 18, 2)->default(0)->after('freight_amount');
            $table->decimal('shortage_damage_amount', 18, 2)->default(0)->after('charges_amount');
            $table->decimal('line_total', 18, 2)->default(0)->after('shortage_damage_amount');

            $table->foreign(['company_id', 'lorry_receipt_id'], 'broker_inv_lines_company_lr_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_hire_id'], 'broker_inv_lines_company_hire_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_hires')
                ->restrictOnDelete();
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'broker_invoice_lines',
            'broker_inv_lines_company_lr_active_unique',
            ['company_id', 'lorry_receipt_id'],
            'lorry_receipt_id IS NOT NULL',
        );

        Schema::table('broker_invoice_payments', function (Blueprint $table) {
            $table->decimal('tds_amount', 18, 2)->default(0)->after('amount_received');
            $table->decimal('deduction_amount', 18, 2)->default(0)->after('tds_amount');
            $table->string('reference_number', 128)->nullable()->after('payment_mode');
            $table->uuid('bank_cash_account_id')->nullable()->after('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('broker_invoice_payments', function (Blueprint $table) {
            $table->dropColumn(['tds_amount', 'deduction_amount', 'reference_number', 'bank_cash_account_id']);
        });

        SoftDeleteUniqueIndex::dropIndexIfExists(
            'broker_invoice_lines',
            'broker_inv_lines_company_lr_active_unique',
        );

        Schema::table('broker_invoice_lines', function (Blueprint $table) {
            $table->dropForeign('broker_inv_lines_company_lr_fk');
            $table->dropForeign('broker_inv_lines_company_hire_fk');
            $table->dropColumn([
                'lorry_receipt_id',
                'lorry_hire_id',
                'memo_number',
                'pod_number',
                'freight_amount',
                'charges_amount',
                'shortage_damage_amount',
                'line_total',
            ]);
        });

        Schema::table('broker_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'taxable_total',
                'gst_amount',
                'customer_tds_amount',
                'gst_rate_snapshot',
                'payment_status',
            ]);
        });
    }
};
