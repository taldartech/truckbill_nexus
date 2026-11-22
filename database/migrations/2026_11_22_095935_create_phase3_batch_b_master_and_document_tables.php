<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('registration_number', 32);
            $table->string('vehicle_type', 64)->nullable();
            $table->string('make', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedSmallInteger('manufacture_year')->nullable();
            $table->string('chassis_number', 64)->nullable();
            $table->string('engine_number', 64)->nullable();
            $table->decimal('capacity_mt', 12, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'registration_number']);
            $table->index(['company_id', 'branch_id', 'is_active']);
            $table->index(['company_id', 'vehicle_type']);
        });

        Schema::create('bank_cash_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('account_kind', 16); // bank | cash
            $table->string('name', 128);
            $table->string('bank_name', 128)->nullable();
            $table->string('account_number', 64)->nullable();
            $table->string('ifsc', 16)->nullable();
            $table->string('account_holder_name', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'branch_id', 'account_kind', 'is_active']);
            $table->unique(['company_id', 'account_kind', 'name']);
        });

        Schema::create('stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('state', 64)->nullable();
            $table->string('city', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['owner_user_id', 'code']);
            $table->index(['owner_user_id', 'name']);
        });

        Schema::create('station_default_transporters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('station_id')->constrained('stations')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignUuid('default_transporter_party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->timestamps();

            $table->unique(['station_id', 'company_id', 'branch_id'], 'station_defaults_scope_unique');
        });

        Schema::create('owner_catalogue_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('catalogue_type', 64);
            $table->string('code', 64);
            $table->string('name', 128);
            $table->jsonb('attributes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['owner_user_id', 'catalogue_type', 'code'], 'owner_catalogue_unique');
            $table->index(['owner_user_id', 'catalogue_type', 'is_active']);
        });

        Schema::create('payment_modes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name', 64);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('gst_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->decimal('rate_percent', 8, 3);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['code', 'version']);
            $table->index(['is_active', 'effective_from', 'effective_to']);
        });

        Schema::create('tds_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64);
            $table->string('name', 128);
            $table->unsignedInteger('priority');
            $table->boolean('is_active')->default(true);
            $table->string('condition_logic', 8)->default('AND'); // AND | OR
            $table->jsonb('conditions');
            $table->decimal('rate_percent', 8, 3);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['code']);
            $table->index(['is_active', 'priority']);
        });

        Schema::create('document_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('parent_type', 128);
            $table->uuid('parent_id');
            $table->foreignUuid('stored_file_id')->constrained('stored_files')->restrictOnDelete();
            $table->string('document_type', 64)->nullable();
            $table->string('label', 128)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('supersedes_attachment_id')->nullable();
            $table->boolean('counts_toward_storage')->default(true);
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'parent_type', 'parent_id']);
            $table->index(['stored_file_id']);
            $table->index(['company_id', 'counts_toward_storage']);
        });

        Schema::table('document_attachments', function (Blueprint $table) {
            $table->foreign('supersedes_attachment_id')
                ->references('id')
                ->on('document_attachments')
                ->nullOnDelete();
        });

        $modes = [
            ['Cash', 'cash', 10],
            ['Bank Transfer', 'bank_transfer', 20],
            ['UPI', 'upi', 30],
            ['Cheque', 'cheque', 40],
            ['NEFT', 'neft', 50],
            ['RTGS', 'rtgs', 60],
            ['IMPS', 'imps', 70],
            ['Other', 'other', 80],
        ];

        $now = now();
        foreach ($modes as [$name, $code, $sort]) {
            DB::table('payment_modes')->insert([
                'id' => (string) Str::uuid(),
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_attachments');
        Schema::dropIfExists('tds_rules');
        Schema::dropIfExists('gst_rates');
        Schema::dropIfExists('payment_modes');
        Schema::dropIfExists('owner_catalogue_items');
        Schema::dropIfExists('station_default_transporters');
        Schema::dropIfExists('stations');
        Schema::dropIfExists('bank_cash_accounts');
        Schema::dropIfExists('vehicles');
    }
};
