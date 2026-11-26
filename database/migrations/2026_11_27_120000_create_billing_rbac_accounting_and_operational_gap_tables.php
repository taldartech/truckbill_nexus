<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_billing_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('legal_name', 255);
            $table->string('trade_name', 255)->nullable();
            $table->text('registered_address');
            $table->uuid('state_id')->nullable();
            $table->string('state_name', 120);
            $table->string('state_code', 10)->nullable();
            $table->string('city', 120);
            $table->string('pin_code', 16);
            $table->string('gstin', 20)->nullable();
            $table->string('pan', 16)->nullable();
            $table->string('contact_number', 32);
            $table->string('email', 255);
            $table->string('logo_path', 255)->nullable();
            $table->string('bank_name', 160)->nullable();
            $table->string('bank_account_name', 160)->nullable();
            $table->string('bank_account_number', 64)->nullable();
            $table->string('bank_ifsc', 24)->nullable();
            $table->string('bank_branch', 160)->nullable();
            $table->decimal('gst_rate_percent', 5, 2)->default(18.00);
            $table->string('sac_code', 16)->default('998314');
            $table->string('hsn_code', 16)->nullable();
            $table->string('invoice_prefix', 24)->default('TB');
            $table->unsignedInteger('invoice_next_sequence')->default(1);
            $table->string('credit_note_prefix', 24)->default('TBCN');
            $table->unsignedInteger('credit_note_next_sequence')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->index(['is_active']);
        });

        Schema::table('subscription_addons', function (Blueprint $table): void {
            $table->string('unit_label', 40)->nullable()->after('billing_period');
            $table->boolean('is_quantifiable')->default(false)->after('unit_label');
            $table->unsignedBigInteger('storage_bytes_per_unit')->default(0)->after('is_quantifiable');
            $table->unsignedInteger('credit_units_per_unit')->default(0)->after('storage_bytes_per_unit');
            $table->unsignedInteger('sort_order')->default(100)->after('credit_units_per_unit');
        });

        Schema::table('company_subscription_items', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1)->after('capacity_units_snapshot');
        });

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->uuid('company_subscription_id')->nullable()->after('payment_intent_id');
            $table->string('document_type', 24)->default('tax_invoice')->after('status');
            $table->uuid('original_invoice_id')->nullable()->after('document_type');
            $table->string('invoice_number', 64)->nullable()->after('approved_number');
            $table->timestampTz('issued_at')->nullable()->after('approved_at');
            $table->date('service_period_start')->nullable()->after('financial_year');
            $table->date('service_period_end')->nullable()->after('service_period_start');
            $table->string('purchase_type', 32)->nullable()->after('service_period_end');
            $table->string('sac_code', 16)->nullable()->after('purchase_type');
            $table->decimal('subtotal_amount', 14, 2)->default(0)->after('sac_code');
            $table->decimal('discount_amount', 14, 2)->default(0)->after('subtotal_amount');
            $table->decimal('taxable_amount', 14, 2)->default(0)->after('discount_amount');
            $table->decimal('gst_rate_percent', 5, 2)->default(0)->after('taxable_amount');
            $table->decimal('cgst_amount', 14, 2)->default(0)->after('gst_rate_percent');
            $table->decimal('sgst_amount', 14, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 14, 2)->default(0)->after('sgst_amount');
            $table->decimal('total_tax_amount', 14, 2)->default(0)->after('igst_amount');
            $table->string('place_of_supply_state', 120)->nullable()->after('total_tax_amount');
            $table->string('place_of_supply_code', 10)->nullable()->after('place_of_supply_state');
            $table->boolean('is_inter_state')->default(false)->after('place_of_supply_code');
            $table->json('supplier_snapshot')->nullable()->after('line_items');
            $table->json('customer_snapshot')->nullable()->after('supplier_snapshot');
            $table->json('pricing_snapshot')->nullable()->after('customer_snapshot');
            $table->string('payment_reference', 128)->nullable()->after('pricing_snapshot');
            $table->text('credit_note_reason')->nullable()->after('payment_reference');

            $table->foreign('company_subscription_id')->references('id')->on('company_subscriptions')->nullOnDelete();
            $table->unique('invoice_number');
            $table->index(['company_id', 'document_type', 'created_at'], 'sub_inv_company_doc_created_idx');
        });

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->foreign('original_invoice_id')->references('id')->on('subscription_invoices')->nullOnDelete();
        });

        Schema::create('accounting_activation_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->timestampTz('activated_at');
            $table->date('cutover_date');
            $table->timestampTz('setup_completed_at')->nullable();
            $table->uuid('opening_voucher_id')->nullable();
            $table->timestampTz('last_expired_at')->nullable();
            $table->date('last_catchup_from')->nullable();
            $table->date('last_catchup_to')->nullable();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('opening_voucher_id')->references('id')->on('accounting_vouchers')->nullOnDelete();
        });

        Schema::create('driver_advance_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('driver_party_id');
            $table->uuid('trip_id')->nullable();
            $table->date('entry_date');
            $table->string('entry_type', 24); // advance|settlement|recovery
            $table->decimal('amount', 14, 2);
            $table->string('payment_mode', 24)->default('cash');
            $table->uuid('bank_cash_account_id')->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'driver_adv_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'driver_party_id'], 'driver_adv_company_driver_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'trip_id'], 'driver_adv_company_trip_fk')
                ->references(['company_id', 'id'])
                ->on('trips')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'bank_cash_account_id'], 'driver_adv_company_bank_fk')
                ->references(['company_id', 'id'])
                ->on('bank_cash_accounts')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'driver_party_id', 'entry_date'], 'driver_adv_company_driver_date_idx');
        });

        Schema::table('trip_expenses', function (Blueprint $table): void {
            $table->uuid('vendor_party_id')->nullable()->after('paid_from_bank_cash_account_id');
            $table->boolean('is_credit_payable')->default(false)->after('vendor_party_id');
            $table->timestampTz('settled_at')->nullable()->after('is_credit_payable');

            $table->foreign(['company_id', 'vendor_party_id'], 'trip_expenses_company_vendor_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
        });

        Schema::create('vendor_expense_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('vendor_party_id');
            $table->uuid('trip_expense_id')->nullable();
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->string('payment_mode', 24)->default('cash');
            $table->uuid('bank_cash_account_id')->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'vendor_exp_pay_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'vendor_party_id'], 'vendor_exp_pay_company_vendor_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->foreign('trip_expense_id')->references('id')->on('trip_expenses')->nullOnDelete();
            $table->foreign(['company_id', 'bank_cash_account_id'], 'vendor_exp_pay_company_bank_fk')
                ->references(['company_id', 'id'])
                ->on('bank_cash_accounts')
                ->restrictOnDelete();
            $table->index(['company_id', 'vendor_party_id', 'payment_date'], 'vendor_exp_pay_company_vendor_date_idx');
        });

        Schema::create('partload_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('billing_customer_party_id');
            $table->string('invoice_number', 64);
            $table->string('status', 32)->default('issued');
            $table->string('payment_status', 32)->default('unpaid');
            $table->date('invoice_date');
            $table->decimal('taxable_total', 18, 2)->default(0);
            $table->decimal('gst_amount', 18, 2)->default(0);
            $table->decimal('invoice_total', 18, 2)->default(0);
            $table->decimal('amount_received', 18, 2)->default(0);
            $table->decimal('customer_tds_amount', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->json('gst_rate_snapshot')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'], 'partload_inv_company_branch_fk')
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'billing_customer_party_id'], 'partload_inv_company_customer_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_invoices',
            'partload_invoices_company_number_active_unique',
            ['company_id', 'invoice_number'],
        );

        Schema::create('partload_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('partload_invoice_id');
            $table->uuid('lorry_receipt_id')->nullable();
            $table->uuid('partload_delivery_receipt_id')->nullable();
            $table->string('description', 255);
            $table->decimal('freight_amount', 18, 2)->default(0);
            $table->decimal('charges_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'partload_invoice_id'], 'partload_inv_lines_company_inv_fk')
                ->references(['company_id', 'id'])
                ->on('partload_invoices')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'], 'partload_inv_lines_company_lr_fk')
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->foreign('partload_delivery_receipt_id', 'partload_inv_lines_dr_fk')
                ->references('id')
                ->on('partload_delivery_receipts')
                ->nullOnDelete();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('partload_invoice_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('partload_invoice_id');
            $table->decimal('amount_received', 18, 2);
            $table->decimal('tds_amount', 18, 2)->default(0);
            $table->decimal('deduction_amount', 18, 2)->default(0);
            $table->date('payment_date');
            $table->string('payment_mode', 32)->default('cash');
            $table->uuid('bank_cash_account_id')->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'partload_invoice_id'], 'partload_inv_pay_company_inv_fk')
                ->references(['company_id', 'id'])
                ->on('partload_invoices')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'bank_cash_account_id'], 'partload_inv_pay_company_bank_fk')
                ->references(['company_id', 'id'])
                ->on('bank_cash_accounts')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partload_invoice_payments');
        Schema::dropIfExists('partload_invoice_lines');
        Schema::dropIfExists('partload_invoices');
        Schema::dropIfExists('vendor_expense_payments');

        Schema::table('trip_expenses', function (Blueprint $table): void {
            $table->dropForeign('trip_expenses_company_vendor_fk');
            $table->dropColumn(['vendor_party_id', 'is_credit_payable', 'settled_at']);
        });

        Schema::dropIfExists('driver_advance_entries');
        Schema::dropIfExists('accounting_activation_states');

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropForeign(['original_invoice_id']);
            $table->dropForeign(['company_subscription_id']);
            $table->dropIndex('sub_inv_company_doc_created_idx');
            $table->dropUnique(['invoice_number']);
            $table->dropColumn([
                'company_subscription_id',
                'document_type',
                'original_invoice_id',
                'invoice_number',
                'issued_at',
                'service_period_start',
                'service_period_end',
                'purchase_type',
                'sac_code',
                'subtotal_amount',
                'discount_amount',
                'taxable_amount',
                'gst_rate_percent',
                'cgst_amount',
                'sgst_amount',
                'igst_amount',
                'total_tax_amount',
                'place_of_supply_state',
                'place_of_supply_code',
                'is_inter_state',
                'supplier_snapshot',
                'customer_snapshot',
                'pricing_snapshot',
                'payment_reference',
                'credit_note_reason',
            ]);
        });

        Schema::table('company_subscription_items', function (Blueprint $table): void {
            $table->dropColumn('quantity');
        });

        Schema::table('subscription_addons', function (Blueprint $table): void {
            $table->dropColumn([
                'unit_label',
                'is_quantifiable',
                'storage_bytes_per_unit',
                'credit_units_per_unit',
                'sort_order',
            ]);
        });

        Schema::dropIfExists('platform_billing_profiles');
    }
};
