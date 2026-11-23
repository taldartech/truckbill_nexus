<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Broker foundation — Lorry Hire + related stub tables (PRD §13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lorry_hires', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('lorry_receipt_id');
            $table->string('hire_number', 64);
            $table->date('hire_date');
            $table->uuid('vehicle_id');
            $table->uuid('transporter_party_id');
            $table->string('declaration_type', 16);
            $table->string('status', 32);
            $table->decimal('freight_total', 18, 2)->default(0);
            $table->decimal('tds_percent', 8, 3)->default(0);
            $table->decimal('tds_amount', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('outstanding_amount', 18, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_remark')->nullable();
            $table->boolean('settle_outstanding')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'lorry_hires_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'], 'lorry_hires_company_lr_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_id'], 'lorry_hires_company_vehicle_fk')
                ->references(['company_id', 'id'])
                ->on('vehicles')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'transporter_party_id'], 'lorry_hires_company_transporter_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'branch_id', 'status'], 'lorry_hires_company_branch_status_idx');
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'lorry_hires',
            'lorry_hires_company_lr_active_unique',
            ['company_id', 'lorry_receipt_id'],
            extraWhere: "status != 'cancelled'",
        );

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'lorry_hires',
            'lorry_hires_company_number_active_unique',
            ['company_id', 'hire_number'],
        );

        Schema::create('lorry_hire_goods_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('lorry_hire_id');
            $table->uuid('lr_goods_row_id');
            $table->string('rate_type', 32);
            $table->decimal('rate', 18, 4);
            $table->boolean('is_fixed_amount');
            $table->decimal('freight_amount', 18, 2);
            $table->decimal('loading_weight', 18, 3);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_hire_id'], 'lh_goods_company_hire_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_hires')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'lorry_hire_id'], 'lh_goods_company_hire_idx');
        });

        Schema::create('lorry_hire_tds_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('lorry_hire_id');
            $table->decimal('freight_basis', 18, 2);
            $table->decimal('calculated_rate', 8, 3);
            $table->decimal('applicable_rate', 8, 3);
            $table->decimal('tds_amount', 18, 2);
            $table->uuid('matched_rule_id')->nullable();
            $table->string('rule_code', 64)->nullable();
            $table->unsignedInteger('rule_priority')->nullable();
            $table->string('pan_status', 32)->nullable();
            $table->string('declaration_status', 32);
            $table->unsignedInteger('goods_vehicle_count')->nullable();
            $table->string('declaration_type', 16)->nullable();
            $table->boolean('override_state')->default(false);
            $table->text('explanation');
            $table->timestampTz('snapshotted_at');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_hire_id'], 'lh_tds_company_hire_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_hires')
                ->restrictOnDelete();
            $table->unique(['lorry_hire_id'], 'lh_tds_hire_unique');
        });

        Schema::create('transporter_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('lorry_hire_id');
            $table->string('voucher_number', 64);
            $table->uuid('transporter_party_id');
            $table->uuid('vehicle_id');
            $table->decimal('amount', 18, 2);
            $table->string('payment_type', 32);
            $table->string('payment_mode', 32)->nullable();
            $table->string('status', 32);
            $table->uuid('bank_cash_account_id')->nullable();
            $table->string('cash_reference', 128)->nullable();
            $table->string('pump_reference', 128)->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'trans_vouchers_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_hire_id'], 'trans_vouchers_company_hire_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_hires')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'transporter_party_id'], 'trans_vouchers_company_transporter_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_id'], 'trans_vouchers_company_vehicle_fk')
                ->references(['company_id', 'id'])
                ->on('vehicles')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'lorry_hire_id', 'status'], 'trans_vouchers_company_hire_status_idx');
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'transporter_vouchers',
            'trans_vouchers_company_number_active_unique',
            ['company_id', 'voucher_number'],
        );

        Schema::create('transporter_book_balance_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('transporter_party_id');
            $table->uuid('lorry_hire_id')->nullable();
            $table->uuid('transporter_voucher_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('entry_type', 32);
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'tb_balance_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'transporter_party_id'], 'tb_balance_company_transporter_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_hire_id'], 'tb_balance_company_hire_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_hires')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'transporter_voucher_id'], 'tb_balance_company_voucher_fk')
                ->references(['company_id', 'id'])
                ->on('transporter_vouchers')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'transporter_party_id'], 'tb_balance_company_transporter_idx');
        });

        Schema::create('broker_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('lorry_receipt_id');
            $table->uuid('lorry_hire_id')->nullable();
            $table->string('memo_number', 64)->nullable();
            $table->string('pod_number', 64)->nullable();
            $table->date('delivery_date')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'broker_deliveries_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'], 'broker_deliveries_company_lr_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_hire_id'], 'broker_deliveries_company_hire_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_hires')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('broker_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('lorry_hire_id');
            $table->string('type', 32);
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('weight', 18, 3)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_hire_id'], 'broker_adjustments_company_hire_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_hires')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('broker_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('lorry_receipt_id')->nullable();
            $table->uuid('lorry_hire_id')->nullable();
            $table->uuid('billing_customer_party_id')->nullable();
            $table->string('invoice_number', 64);
            $table->string('status', 32);
            $table->date('invoice_date')->nullable();
            $table->decimal('invoice_total', 18, 2)->default(0);
            $table->decimal('amount_received', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'broker_invoices_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'broker_invoices',
            'broker_invoices_company_number_active_unique',
            ['company_id', 'invoice_number'],
        );

        Schema::create('broker_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('broker_invoice_id');
            $table->string('description');
            $table->decimal('amount', 18, 2);
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'broker_invoice_id'], 'broker_inv_lines_company_invoice_fk')
                ->references(['company_id', 'id'])
                ->on('broker_invoices')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('broker_invoice_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('broker_invoice_id');
            $table->decimal('amount_received', 18, 2);
            $table->date('payment_date')->nullable();
            $table->string('payment_mode', 32)->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'broker_invoice_id'], 'broker_inv_payments_company_invoice_fk')
                ->references(['company_id', 'id'])
                ->on('broker_invoices')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('linked_bilty_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('group_number', 64);
            $table->string('status', 32)->default('open');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'linked_bilty_groups_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'linked_bilty_groups',
            'linked_bilty_groups_company_number_active_unique',
            ['company_id', 'group_number'],
        );

        Schema::create('linked_bilty_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('linked_bilty_group_id');
            $table->uuid('lorry_receipt_id');
            $table->uuid('lorry_hire_id')->nullable();
            $table->decimal('share_amount', 18, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'linked_bilty_group_id'], 'linked_bilty_members_company_group_fk')
                ->references(['company_id', 'id'])
                ->on('linked_bilty_groups')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'], 'linked_bilty_members_company_lr_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_bilty_group_members');
        Schema::dropIfExists('linked_bilty_groups');
        Schema::dropIfExists('broker_invoice_payments');
        Schema::dropIfExists('broker_invoice_lines');
        Schema::dropIfExists('broker_invoices');
        Schema::dropIfExists('broker_adjustments');
        Schema::dropIfExists('broker_deliveries');
        Schema::dropIfExists('transporter_book_balance_entries');
        Schema::dropIfExists('transporter_vouchers');
        Schema::dropIfExists('lorry_hire_tds_snapshots');
        Schema::dropIfExists('lorry_hire_goods_lines');
        Schema::dropIfExists('lorry_hires');
    }
};
