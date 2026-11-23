<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-WS-5/6 Batch E: lifecycle, capacity/quota reservations, simulated payments, invoices.
 * Live Razorpay remains gated (PO-P3-PAY / G-PAY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->date('paid_through_date')->nullable()->after('activated_at');
            $table->date('grace_ends_on')->nullable()->after('paid_through_date');
            $table->timestamp('cancellation_requested_at')->nullable()->after('grace_ends_on');
            $table->date('cancellation_effective_on')->nullable()->after('cancellation_requested_at');
            $table->foreignId('cancellation_requested_by')->nullable()->after('cancellation_effective_on')->constrained('users')->nullOnDelete();
            $table->string('cancellation_remark')->nullable()->after('cancellation_requested_by');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_remark');
        });

        Schema::create('company_subscription_trials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->foreignId('owner_user_id');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone')->default('UTC');
            $table->timestamp('activated_notified_at')->nullable();
            $table->json('reminder_sent_for_days')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();
            // One trial grant per owner identity (first company only).
            $table->unique('owner_user_id');
            $table->index(['ends_at']);
        });

        Schema::create('subscription_change_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('company_subscription_id')->nullable();
            $table->string('change_type'); // upgrade|downgrade|addon_add|addon_remove|capacity_increase|capacity_decrease|renewal
            $table->string('status')->default('pending'); // pending|scheduled|awaiting_payment|applied|rejected|withdrawn
            $table->date('effective_on')->nullable();
            $table->uuid('catalogue_version_id')->nullable();
            $table->json('payload');
            $table->json('pricing_breakdown')->nullable();
            $table->string('remark')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('company_subscription_id')->references('id')->on('company_subscriptions')->nullOnDelete();
            $table->foreign('catalogue_version_id')->references('id')->on('subscription_catalogue_versions')->nullOnDelete();
            $table->index(['company_id', 'status', 'change_type'], 'scp_company_status_type_idx');
        });

        Schema::create('subscription_credit_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('ledger_id');
            $table->decimal('amount', 14, 4);
            $table->string('status'); // reserved|committed|released
            $table->string('idempotency_key');
            $table->string('reason');
            $table->json('meta')->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('ledger_id')->references('id')->on('subscription_credit_ledgers')->restrictOnDelete();
            $table->unique(['ledger_id', 'idempotency_key'], 'scr_ledger_idempotency_uq');
            $table->index(['company_id', 'status']);
        });

        Schema::create('payment_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('purpose'); // subscription_activation|renewal|addon|capacity|credit_topup|other
            $table->string('currency', 3)->default('INR');
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('created'); // created|order_created|paid|failed|cancelled|quarantined
            $table->string('verification_status')->default('unverified'); // unverified|verified|rejected
            $table->string('idempotency_key')->unique();
            $table->uuid('company_subscription_id')->nullable();
            $table->uuid('change_proposal_id')->nullable();
            $table->string('gateway')->default('fake');
            $table->string('gateway_order_id')->nullable()->unique();
            $table->string('gateway_payment_id')->nullable()->unique();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('company_subscription_id')->references('id')->on('company_subscriptions')->nullOnDelete();
            $table->foreign('change_proposal_id')->references('id')->on('subscription_change_proposals')->nullOnDelete();
            $table->index(['company_id', 'status']);
        });

        Schema::create('payment_gateway_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('payment_intent_id')->nullable();
            $table->string('gateway')->default('fake');
            $table->string('gateway_event_id')->unique();
            $table->string('event_type');
            $table->string('processing_status')->default('received'); // received|processed|duplicate|rejected|quarantined|failed
            $table->string('signature_status'); // verified|invalid|skipped
            $table->json('payload');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->nullOnDelete();
            $table->index(['processing_status', 'received_at']);
        });

        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payment_intent_id')->nullable();
            $table->uuid('gateway_event_id')->nullable();
            $table->string('outcome'); // matched|mismatch|unmatched|delayed
            $table->json('details');
            $table->timestamp('created_at');

            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->nullOnDelete();
            $table->index(['outcome', 'created_at']);
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('payment_intent_id')->nullable();
            $table->string('status'); // draft|awaiting_number_approval|approved|issued|emailed
            $table->string('proposed_number')->nullable();
            $table->string('approved_number')->nullable();
            $table->string('financial_year')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('INR');
            $table->json('line_items')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emailed_at')->nullable();
            $table->string('email_status')->nullable(); // pending|sent|failed
            $table->string('email_failure_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->nullOnDelete();
            $table->unique(['company_id', 'approved_number']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('payment_reconciliations');
        Schema::dropIfExists('payment_gateway_events');
        Schema::dropIfExists('payment_intents');
        Schema::dropIfExists('subscription_credit_reservations');
        Schema::dropIfExists('subscription_change_proposals');
        Schema::dropIfExists('company_subscription_trials');

        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancellation_requested_by');
            $table->dropColumn([
                'paid_through_date',
                'grace_ends_on',
                'cancellation_requested_at',
                'cancellation_effective_on',
                'cancellation_remark',
                'cancelled_at',
            ]);
        });
    }
};
