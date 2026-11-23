<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-WS-4: subscription catalogue, contracts, credits, quota foundations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_catalogue_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamp('effective_from');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'effective_from']);
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('catalogue_version_id');
            $table->string('module'); // broker|ownfleet|partload
            $table->string('code');
            $table->string('name');
            $table->decimal('annual_price', 12, 2);
            $table->unsignedInteger('capacity_units');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('catalogue_version_id')
                ->references('id')
                ->on('subscription_catalogue_versions')
                ->restrictOnDelete();
            $table->unique(['catalogue_version_id', 'module', 'code']);
            $table->index(['catalogue_version_id', 'module', 'is_active']);
        });

        Schema::create('subscription_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('catalogue_version_id');
            $table->string('code');
            $table->string('name');
            $table->decimal('annual_price', 12, 2);
            $table->string('billing_period')->default('yearly');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('catalogue_version_id')
                ->references('id')
                ->on('subscription_catalogue_versions')
                ->restrictOnDelete();
            $table->unique(['catalogue_version_id', 'code']);
        });

        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('catalogue_version_id');
            $table->string('status')->default('draft'); // draft|pending_payment|active|cancelled — activation requires verified payment (WS-6)
            $table->date('common_renewal_date')->nullable();
            $table->string('tenure')->nullable();
            $table->boolean('renewal_eligible')->default(false);
            $table->unsignedTinyInteger('core_module_count')->default(0);
            $table->decimal('list_annual_total', 14, 2)->nullable();
            $table->decimal('discounted_annual_total', 14, 2)->nullable();
            $table->json('pricing_breakdown')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('catalogue_version_id')
                ->references('id')
                ->on('subscription_catalogue_versions')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('company_subscription_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('company_subscription_id');
            $table->string('item_type'); // plan|addon
            $table->uuid('plan_id')->nullable();
            $table->uuid('addon_id')->nullable();
            $table->string('module')->nullable();
            $table->string('code');
            $table->string('name');
            $table->decimal('annual_price_snapshot', 12, 2);
            $table->unsignedInteger('capacity_units_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            // Explicit short names: MySQL identifier limit is 64 characters.
            $table->foreign(['company_id', 'company_subscription_id'], 'csi_company_subscription_fk')
                ->references(['company_id', 'id'])
                ->on('company_subscriptions')
                ->restrictOnDelete();
            $table->foreign('plan_id')->references('id')->on('subscription_plans')->nullOnDelete();
            $table->foreign('addon_id')->references('id')->on('subscription_addons')->nullOnDelete();
            $table->index(['company_subscription_id', 'item_type'], 'csi_subscription_item_type_idx');
        });

        Schema::create('company_subscription_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('company_subscription_id');
            $table->string('reason');
            $table->json('payload');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'company_subscription_id'], 'css_company_subscription_fk')
                ->references(['company_id', 'id'])
                ->on('company_subscriptions')
                ->restrictOnDelete();
            $table->index(['company_subscription_id', 'created_at'], 'css_subscription_created_idx');
        });

        Schema::create('subscription_credit_ledgers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('ledger_type'); // broker|partload|service|storage
            $table->string('service_key')->nullable();
            $table->decimal('balance', 14, 4)->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->unique(['company_id', 'ledger_type', 'service_key'], 'scl_company_type_service_uq');
        });

        Schema::create('subscription_credit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('ledger_id');
            $table->decimal('amount', 14, 4);
            $table->string('entry_type'); // grant|consume|release|adjust
            $table->string('reason');
            $table->string('idempotency_key')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at');

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('ledger_id')->references('id')->on('subscription_credit_ledgers')->restrictOnDelete();
            $table->unique(['ledger_id', 'idempotency_key']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('company_quota_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('quota_type'); // broker_lr|partload_bilty|storage_bytes|seats|branches|vehicles
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('included_units', 14, 4)->default(0);
            $table->decimal('used_units', 14, 4)->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->unique(['company_id', 'quota_type', 'period_start', 'period_end'], 'company_quota_counters_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_quota_counters');
        Schema::dropIfExists('subscription_credit_entries');
        Schema::dropIfExists('subscription_credit_ledgers');
        Schema::dropIfExists('company_subscription_snapshots');
        Schema::dropIfExists('company_subscription_items');
        Schema::dropIfExists('company_subscriptions');
        Schema::dropIfExists('subscription_addons');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('subscription_catalogue_versions');
    }
};
