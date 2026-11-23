<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 OwnFleet trip invoices, charges, LR links, and payments (Rules §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_number_allocations', function (Blueprint $table): void {
            if (! Schema::hasIndex('document_number_allocations', 'document_number_allocations_company_id_unique')) {
                $table->unique(['company_id', 'id'], 'document_number_allocations_company_id_unique');
            }
        });

        Schema::create('trip_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('invoice_number', 64);
            $table->uuid('document_number_allocation_id')->nullable();
            $table->uuid('billing_customer_party_id');
            $table->string('invoice_type', 32);
            $table->string('particulars_mode', 32);
            $table->string('status', 32);
            $table->string('payment_status', 32);
            $table->date('invoice_date');
            $table->string('workflow_state', 64)->default('pending_sample');
            $table->decimal('base_freight_total', 18, 2);
            $table->decimal('additional_charges_total', 18, 2)->default(0);
            $table->decimal('taxable_total', 18, 2);
            $table->decimal('gst_amount', 18, 2)->default(0);
            $table->decimal('invoice_total', 18, 2);
            $table->decimal('amount_received_total', 18, 2)->default(0);
            $table->decimal('tds_received_total', 18, 2)->default(0);
            $table->decimal('deductions_total', 18, 2)->default(0);
            $table->decimal('settlement_total', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2);
            $table->decimal('invoice_tds_rate_percent', 5, 2);
            $table->decimal('tds_basis_freight', 18, 2);
            $table->json('gst_rate_snapshot')->nullable();
            $table->json('billing_customer_snapshot');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_remark')->nullable();
            $table->timestampTz('charges_locked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'trip_invoices_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'billing_customer_party_id'], 'trip_invoices_company_customer_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'document_number_allocation_id'], 'trip_invoices_company_doc_alloc_fk')
                ->references(['company_id', 'id'])
                ->on('document_number_allocations')
                ->nullOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'branch_id', 'status'], 'trip_invoices_company_branch_status_idx');
            $table->index(['company_id', 'billing_customer_party_id'], 'trip_invoices_company_customer_idx');
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'trip_invoices',
            'trip_invoices_company_number_active_unique',
            ['company_id', 'invoice_number'],
        );

        Schema::create('trip_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('trip_invoice_id');
            $table->uuid('lorry_receipt_id');
            $table->uuid('trip_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('base_freight', 18, 2);
            $table->string('lr_number', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'trip_invoice_id'], 'til_company_invoice_fk')
                ->references(['company_id', 'id'])
                ->on('trip_invoices')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'], 'til_company_lr_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'trip_id'], 'til_company_trip_fk')
                ->references(['company_id', 'id'])
                ->on('trips')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'trip_invoice_id'], 'til_company_invoice_idx');
        });

        Schema::create('trip_invoice_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('trip_invoice_id');
            $table->string('charge_type', 32);
            $table->string('description', 255);
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'trip_invoice_id'], 'tic_company_invoice_fk')
                ->references(['company_id', 'id'])
                ->on('trip_invoices')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'trip_invoice_id'], 'tic_company_invoice_idx');
        });

        Schema::create('trip_invoice_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('trip_invoice_id');
            $table->date('payment_date');
            $table->uuid('payment_mode_id')->nullable();
            $table->string('reference', 128)->nullable();
            $table->decimal('amount_received', 18, 2)->default(0);
            $table->decimal('tds_amount', 18, 2)->default(0);
            $table->decimal('shortage_damage', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->text('discount_reason')->nullable();
            $table->decimal('settlement_amount', 18, 2);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'trip_invoice_id'], 'tip_company_invoice_fk')
                ->references(['company_id', 'id'])
                ->on('trip_invoices')
                ->restrictOnDelete();
            $table->foreign('payment_mode_id')->references('id')->on('payment_modes')->nullOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'trip_invoice_id'], 'tip_company_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_invoice_payments');
        Schema::dropIfExists('trip_invoice_charges');
        Schema::dropIfExists('trip_invoice_lines');
        SoftDeleteUniqueIndex::dropIndexIfExists('trip_invoices', 'trip_invoices_company_number_active_unique');
        Schema::dropIfExists('trip_invoices');

        Schema::table('document_number_allocations', function (Blueprint $table): void {
            if (Schema::hasIndex('document_number_allocations', 'document_number_allocations_company_id_unique')) {
                $table->dropUnique('document_number_allocations_company_id_unique');
            }
        });
    }
};
