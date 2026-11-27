<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_billing_profiles', function (Blueprint $table): void {
            $table->uuid('city_id')->nullable()->after('state_code');
            $table->uuid('gst_rate_id')->nullable()->after('gstin');
            $table->uuid('logo_stored_file_id')->nullable()->after('logo_path');
            $table->string('website', 255)->nullable()->after('bank_ifsc');
            $table->text('notes_terms')->nullable()->after('website');

            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            $table->foreign('gst_rate_id')->references('id')->on('gst_rates')->nullOnDelete();
            $table->foreign('logo_stored_file_id')->references('id')->on('stored_files')->nullOnDelete();
        });

        Schema::table('platform_billing_profiles', function (Blueprint $table): void {
            $dropColumns = array_values(array_filter([
                'trade_name',
                'bank_branch',
                'sac_code',
                'hsn_code',
                'invoice_prefix',
                'invoice_next_sequence',
                'credit_note_prefix',
                'credit_note_next_sequence',
            ], static fn (string $column): bool => Schema::hasColumn('platform_billing_profiles', $column)));

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });

        SoftDeleteUniqueIndex::dropUniqueConstraintOrIndexIfExists(
            'subscription_invoices',
            'subscription_invoices_invoice_number_unique',
        );
        SoftDeleteUniqueIndex::dropUniqueConstraintOrIndexIfExists(
            'subscription_invoices',
            'subscription_invoices_company_id_approved_number_unique',
        );

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->uuid('billing_profile_id')->nullable()->after('company_subscription_id');
            $table->string('supplier_registration_key', 120)->nullable()->after('billing_profile_id');
            $table->date('invoice_date')->nullable()->after('financial_year');
            $table->timestampTz('payment_at')->nullable()->after('payment_reference');

            $table->foreign('billing_profile_id')->references('id')->on('platform_billing_profiles')->nullOnDelete();
            $table->index(['status', 'created_at'], 'sub_inv_status_created_idx');
            $table->index(['billing_profile_id'], 'sub_inv_billing_profile_idx');
            $table->index(['invoice_date'], 'sub_inv_invoice_date_idx');
        });

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            table: 'subscription_invoices',
            indexName: 'sub_inv_supplier_fy_number_unique',
            columns: ['supplier_registration_key', 'financial_year', 'invoice_number'],
            extraWhere: 'invoice_number IS NOT NULL AND supplier_registration_key IS NOT NULL',
        );
    }

    public function down(): void
    {
        SoftDeleteUniqueIndex::dropIndexIfExists(
            'subscription_invoices',
            'sub_inv_supplier_fy_number_unique',
        );

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropForeign(['billing_profile_id']);
            $table->dropIndex('sub_inv_status_created_idx');
            $table->dropIndex('sub_inv_billing_profile_idx');
            $table->dropIndex('sub_inv_invoice_date_idx');
            $table->dropColumn([
                'billing_profile_id',
                'supplier_registration_key',
                'invoice_date',
                'payment_at',
            ]);
            $table->unique('invoice_number');
            $table->unique(['company_id', 'approved_number']);
        });

        Schema::table('platform_billing_profiles', function (Blueprint $table): void {
            $table->string('trade_name', 255)->nullable()->after('legal_name');
            $table->string('bank_branch', 160)->nullable()->after('bank_ifsc');
            $table->string('sac_code', 16)->default('998314')->after('gst_rate_percent');
            $table->string('hsn_code', 16)->nullable()->after('sac_code');
            $table->string('invoice_prefix', 24)->default('TB')->after('hsn_code');
            $table->unsignedInteger('invoice_next_sequence')->default(1)->after('invoice_prefix');
            $table->string('credit_note_prefix', 24)->default('TBCN')->after('invoice_next_sequence');
            $table->unsignedInteger('credit_note_next_sequence')->default(1)->after('credit_note_prefix');

            $table->dropForeign(['city_id']);
            $table->dropForeign(['gst_rate_id']);
            $table->dropForeign(['logo_stored_file_id']);
            $table->dropColumn([
                'city_id',
                'gst_rate_id',
                'logo_stored_file_id',
                'website',
                'notes_terms',
            ]);
        });
    }
};
