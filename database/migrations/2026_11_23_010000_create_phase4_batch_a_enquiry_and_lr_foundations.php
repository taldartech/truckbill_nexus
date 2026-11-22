<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Batch A: Enquiry + Unified LR domain foundations.
 *
 * Conversion linkage is cardinality-neutral (PO-P4-CNV-CARDINALITY Pending).
 * No public webhook routes. No product UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('document_type', 32);
            $table->string('scope_type', 16);
            $table->uuid('branch_id')->nullable();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
            $table->index(['company_id', 'document_type']);
        });

        // PostgreSQL treats NULLs as distinct in unique indexes; coalesce branch for company scope.
        DB::statement("
            CREATE UNIQUE INDEX document_number_sequences_scope_unique
            ON document_number_sequences (
                company_id,
                document_type,
                scope_type,
                (COALESCE(branch_id::text, ''))
            )
        ");

        Schema::create('document_number_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('document_type', 32);
            $table->string('scope_type', 16);
            $table->uuid('branch_id')->nullable();
            $table->string('number', 64);
            $table->string('allocation_mode', 16);
            $table->uuid('allocated_to_id')->nullable();
            $table->string('allocated_to_type', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
            $table->index(['company_id', 'document_type', 'number']);
            $table->index(['allocated_to_type', 'allocated_to_id']);
        });

        DB::statement("
            CREATE UNIQUE INDEX document_number_allocations_company_active_unique
            ON document_number_allocations (company_id, document_type, number)
            WHERE deleted_at IS NULL AND scope_type = 'company'
        ");
        DB::statement("
            CREATE UNIQUE INDEX document_number_allocations_branch_active_unique
            ON document_number_allocations (company_id, branch_id, document_type, number)
            WHERE deleted_at IS NULL AND scope_type = 'branch'
        ");

        Schema::create('document_number_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('sequence_id');
            $table->unsignedBigInteger('previous_next_value');
            $table->unsignedBigInteger('new_next_value');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('remark');
            $table->timestampTz('adjusted_at');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('sequence_id')->references('id')->on('document_number_sequences')->restrictOnDelete();
            $table->index(['company_id', 'sequence_id']);
        });

        Schema::create('enquiries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('source', 32);
            $table->string('enquiry_number', 64);
            $table->date('enquiry_date');
            $table->uuid('customer_party_id')->nullable();
            $table->string('external_customer_name')->nullable();
            $table->string('external_customer_phone', 32)->nullable();
            $table->string('external_customer_email')->nullable();
            $table->string('pickup_location');
            $table->string('delivery_location');
            $table->uuid('vehicle_type_id');
            $table->unsignedInteger('vehicle_quantity');
            $table->date('expected_loading_date')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_reference', 128)->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
            $table->foreign(['company_id', 'customer_party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->nullOnDelete();
            $table->foreign('vehicle_type_id')->references('id')->on('owner_catalogue_items')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'expected_loading_date']);
        });

        DB::statement('
            CREATE UNIQUE INDEX enquiries_company_number_active_unique
            ON enquiries (company_id, enquiry_number)
            WHERE deleted_at IS NULL
        ');
        DB::statement('
            CREATE INDEX enquiries_unassigned_queue_idx
            ON enquiries (company_id, created_at)
            WHERE branch_id IS NULL AND deleted_at IS NULL
        ');
        DB::statement('ALTER TABLE enquiries ADD CONSTRAINT enquiries_vehicle_quantity_positive CHECK (vehicle_quantity >= 1)');

        Schema::create('enquiry_goods_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('enquiry_id');
            $table->unsignedInteger('position');
            $table->uuid('goods_type_id')->nullable();
            $table->uuid('package_type_id')->nullable();
            $table->string('goods_description')->nullable();
            $table->decimal('package_count', 18, 3)->nullable();
            $table->decimal('actual_weight', 18, 3)->nullable();
            $table->decimal('charged_weight', 18, 3)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'enquiry_id'])
                ->references(['company_id', 'id'])
                ->on('enquiries')
                ->restrictOnDelete();
            $table->foreign('goods_type_id')->references('id')->on('owner_catalogue_items')->nullOnDelete();
            $table->foreign('package_type_id')->references('id')->on('owner_catalogue_items')->nullOnDelete();
            $table->unique(['enquiry_id', 'position']);
            $table->index(['company_id', 'enquiry_id']);
        });

        Schema::create('enquiry_assignment_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('enquiry_id');
            $table->uuid('from_branch_id')->nullable();
            $table->uuid('to_branch_id')->nullable();
            $table->foreignId('from_assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('remark');
            $table->timestampTz('assigned_at');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'enquiry_id'])
                ->references(['company_id', 'id'])
                ->on('enquiries')
                ->restrictOnDelete();
            $table->index(['company_id', 'enquiry_id', 'assigned_at']);
        });

        Schema::create('enquiry_follow_ups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('enquiry_id');
            $table->timestampTz('follow_up_at');
            $table->text('remarks');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'enquiry_id'])
                ->references(['company_id', 'id'])
                ->on('enquiries')
                ->restrictOnDelete();
            $table->index(['company_id', 'enquiry_id', 'follow_up_at']);
        });

        Schema::create('enquiry_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('enquiry_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remark')->nullable();
            $table->timestampTz('changed_at');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'enquiry_id'])
                ->references(['company_id', 'id'])
                ->on('enquiries')
                ->restrictOnDelete();
            $table->index(['company_id', 'enquiry_id', 'changed_at']);
        });

        Schema::create('enquiry_quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('enquiry_id');
            $table->uuid('transporter_party_id');
            $table->decimal('quoted_freight', 18, 2);
            $table->string('rate_basis', 64)->nullable();
            $table->uuid('vehicle_type_id')->nullable();
            $table->unsignedInteger('vehicle_quantity')->nullable();
            $table->decimal('advance_amount', 18, 2)->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('expected_delivery_duration')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->decimal('extra_charges_amount', 18, 2)->nullable();
            $table->text('extra_charges_description')->nullable();
            $table->text('remarks')->nullable();
            $table->string('selection_status', 32)->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'enquiry_id'])
                ->references(['company_id', 'id'])
                ->on('enquiries')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'transporter_party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->foreign('vehicle_type_id')->references('id')->on('owner_catalogue_items')->nullOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'enquiry_id']);
        });

        DB::statement('
            CREATE UNIQUE INDEX enquiry_quotations_transporter_active_unique
            ON enquiry_quotations (enquiry_id, transporter_party_id)
            WHERE deleted_at IS NULL
        ');

        Schema::create('enquiry_quotation_selection_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('enquiry_id');
            $table->uuid('quotation_id');
            $table->string('action', 32);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('remark')->nullable();
            $table->timestampTz('selected_at');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'enquiry_id'])
                ->references(['company_id', 'id'])
                ->on('enquiries')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'quotation_id'])
                ->references(['company_id', 'id'])
                ->on('enquiry_quotations')
                ->restrictOnDelete();
            $table->index(['company_id', 'enquiry_id', 'selected_at']);
        });

        Schema::create('enquiry_quotation_broadcast_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('enquiry_id');
            $table->uuid('batch_id');
            $table->jsonb('channels');
            $table->string('status', 32);
            $table->string('correlation_id', 64)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'enquiry_id'])
                ->references(['company_id', 'id'])
                ->on('enquiries')
                ->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'enquiry_id', 'requested_at']);
            $table->index(['company_id', 'batch_id']);
        });

        DB::statement('
            CREATE UNIQUE INDEX enquiry_broadcast_idempotency_unique
            ON enquiry_quotation_broadcast_requests (company_id, idempotency_key)
            WHERE idempotency_key IS NOT NULL
        ');

        Schema::create('enquiry_quotation_broadcast_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('broadcast_request_id');
            $table->uuid('transporter_party_id');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'broadcast_request_id'])
                ->references(['company_id', 'id'])
                ->on('enquiry_quotation_broadcast_requests')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'transporter_party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
            $table->unique(['broadcast_request_id', 'transporter_party_id'], 'enquiry_broadcast_target_unique');
        });

        Schema::create('company_enquiry_webhook_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('token_prefix', 16);
            $table->string('token_hash', 64);
            $table->string('label')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('replaces_credential_id')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['token_prefix']);
            $table->index(['company_id', 'revoked_at']);
        });

        Schema::create('enquiry_external_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('external_reference', 128);
            $table->uuid('enquiry_id')->nullable();
            $table->string('source', 32)->default('website');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'enquiry_id'])
                ->references(['company_id', 'id'])
                ->on('enquiries')
                ->nullOnDelete();
            $table->unique(['company_id', 'external_reference'], 'enquiry_external_references_company_ref_unique');
            $table->index(['company_id', 'enquiry_id']);
        });

        Schema::create('lorry_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('workflow_context', 32);
            $table->string('lr_number', 64);
            $table->date('lr_date');
            $table->string('from_text')->nullable();
            $table->string('to_text')->nullable();
            $table->uuid('from_station_id')->nullable();
            $table->uuid('to_station_id')->nullable();
            $table->uuid('consignor_party_id')->nullable();
            $table->uuid('consignee_party_id')->nullable();
            $table->string('status', 32);
            $table->decimal('goods_value', 18, 2)->nullable();
            $table->decimal('freight_total', 18, 2)->default(0);
            $table->decimal('additional_charges_total', 18, 2)->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_remark')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign('from_station_id')->references('id')->on('stations')->nullOnDelete();
            $table->foreign('to_station_id')->references('id')->on('stations')->nullOnDelete();
            $table->foreign(['company_id', 'consignor_party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->nullOnDelete();
            $table->foreign(['company_id', 'consignee_party_id'])
                ->references(['company_id', 'id'])
                ->on('parties')
                ->nullOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'workflow_context']);
        });

        Schema::create('lr_goods_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('lorry_receipt_id');
            $table->unsignedInteger('position');
            $table->uuid('goods_type_id')->nullable();
            $table->uuid('package_type_id')->nullable();
            $table->string('goods_description')->nullable();
            $table->decimal('package_count', 18, 3)->nullable();
            $table->decimal('actual_weight', 18, 3)->nullable();
            $table->decimal('charged_weight', 18, 3)->nullable();
            $table->string('freight_rate_type', 32);
            $table->decimal('freight_rate', 18, 4)->default(0);
            $table->decimal('freight_amount', 18, 2)->default(0);
            $table->decimal('goods_value', 18, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'])
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->foreign('goods_type_id')->references('id')->on('owner_catalogue_items')->nullOnDelete();
            $table->foreign('package_type_id')->references('id')->on('owner_catalogue_items')->nullOnDelete();
            $table->unique(['lorry_receipt_id', 'position']);
            $table->index(['company_id', 'lorry_receipt_id']);
        });

        Schema::create('lr_eway_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('lorry_receipt_id');
            $table->string('ewb_number', 64);
            $table->date('ewb_date')->nullable();
            $table->date('valid_until');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'])
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->index(['company_id', 'lorry_receipt_id']);
            $table->index(['company_id', 'ewb_number']);
        });

        Schema::create('lr_additional_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('lorry_receipt_id');
            $table->string('description');
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'])
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->index(['company_id', 'lorry_receipt_id']);
        });

        DB::statement('ALTER TABLE lr_additional_charges ADD CONSTRAINT lr_additional_charges_amount_positive CHECK (amount > 0)');

        // Cardinality-neutral conversion foundation (PO-P4-CNV-CARDINALITY Pending).
        Schema::create('enquiry_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('enquiry_id');
            $table->string('idempotency_key', 128)->nullable();
            $table->foreignId('converted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('converted_at');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'enquiry_id'])
                ->references(['company_id', 'id'])
                ->on('enquiries')
                ->restrictOnDelete();
            $table->unique(['enquiry_id']);
            $table->unique(['company_id', 'id']);
        });

        DB::statement('
            CREATE UNIQUE INDEX enquiry_conversions_idempotency_unique
            ON enquiry_conversions (company_id, idempotency_key)
            WHERE idempotency_key IS NOT NULL
        ');

        Schema::create('enquiry_conversion_lorry_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('enquiry_conversion_id');
            $table->uuid('lorry_receipt_id');
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'enquiry_conversion_id'])
                ->references(['company_id', 'id'])
                ->on('enquiry_conversions')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'lorry_receipt_id'])
                ->references(['company_id', 'id'])
                ->on('lorry_receipts')
                ->restrictOnDelete();
            $table->unique(['enquiry_conversion_id', 'lorry_receipt_id']);
            $table->unique(['lorry_receipt_id']);
            $table->index(['company_id', 'enquiry_conversion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_conversion_lorry_receipts');
        Schema::dropIfExists('enquiry_conversions');
        Schema::dropIfExists('lr_additional_charges');
        Schema::dropIfExists('lr_eway_bills');
        Schema::dropIfExists('lr_goods_rows');
        Schema::dropIfExists('lorry_receipts');
        Schema::dropIfExists('enquiry_external_references');
        Schema::dropIfExists('company_enquiry_webhook_credentials');
        Schema::dropIfExists('enquiry_quotation_broadcast_targets');
        Schema::dropIfExists('enquiry_quotation_broadcast_requests');
        Schema::dropIfExists('enquiry_quotation_selection_histories');
        Schema::dropIfExists('enquiry_quotations');
        Schema::dropIfExists('enquiry_status_histories');
        Schema::dropIfExists('enquiry_follow_ups');
        Schema::dropIfExists('enquiry_assignment_histories');
        Schema::dropIfExists('enquiry_goods_rows');
        Schema::dropIfExists('enquiries');
        Schema::dropIfExists('document_number_adjustments');
        Schema::dropIfExists('document_number_allocations');
        Schema::dropIfExists('document_number_sequences');
    }
};
