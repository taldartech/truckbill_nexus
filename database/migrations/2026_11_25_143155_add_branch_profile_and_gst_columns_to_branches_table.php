<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Branch profile + GST configuration (PO Owner Panel reconstruction).
 * Existing branches default to inherit_company GST; no fabricated addresses/GSTINs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('contact_number', 20)->nullable()->after('name');
            $table->boolean('is_head_office')->default(false)->after('contact_number');
            $table->text('address')->nullable()->after('is_head_office');
            $table->string('state', 100)->nullable()->after('address');
            $table->string('city', 100)->nullable()->after('state');
            $table->string('pin', 10)->nullable()->after('city');
            $table->string('gst_mode', 32)->default('inherit_company')->after('pin');
            $table->string('gst_registration_type', 32)->nullable()->after('gst_mode');
            $table->string('tax_liability', 16)->nullable()->after('gst_registration_type');
            $table->string('gstin', 15)->nullable()->after('tax_liability');
            $table->uuid('gst_rate_id')->nullable()->after('gstin');
            $table->json('gst_rate_snapshot')->nullable()->after('gst_rate_id');

            $table->foreign('gst_rate_id')->references('id')->on('gst_rates')->nullOnDelete();
            $table->index(['company_id', 'is_head_office']);
            $table->index(['company_id', 'gst_mode']);
        });

        // One Head Office per company among non-deleted branches (PostgreSQL + MySQL 8.0.13+).
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX branches_one_head_office_per_company
                 ON branches (company_id)
                 WHERE is_head_office = true AND deleted_at IS NULL'
            );
        } else {
            // MySQL: functional/partial unique indexes are limited — enforce in application
            // with lockForUpdate; keep a non-unique supporting index only.
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS branches_one_head_office_per_company');
        }

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropForeign(['gst_rate_id']);
            $table->dropIndex(['company_id', 'is_head_office']);
            $table->dropIndex(['company_id', 'gst_mode']);
            $table->dropColumn([
                'contact_number',
                'is_head_office',
                'address',
                'state',
                'city',
                'pin',
                'gst_mode',
                'gst_registration_type',
                'tax_liability',
                'gstin',
                'gst_rate_id',
                'gst_rate_snapshot',
            ]);
        });
    }
};
