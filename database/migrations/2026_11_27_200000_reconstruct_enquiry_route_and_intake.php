<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiry module reconstruction: route geography, party intake, webhook branch routing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table): void {
            $table->string('party_name')->nullable()->after('customer_party_id');
            $table->uuid('consignor_party_id')->nullable()->after('party_name');
            $table->uuid('consignee_party_id')->nullable()->after('consignor_party_id');
            $table->uuid('from_state_id')->nullable()->after('consignee_party_id');
            $table->uuid('from_city_id')->nullable()->after('from_state_id');
            $table->uuid('to_state_id')->nullable()->after('from_city_id');
            $table->uuid('to_city_id')->nullable()->after('to_state_id');
            $table->string('external_business_name')->nullable()->after('external_customer_email');
            $table->string('external_contact_person_name')->nullable()->after('external_business_name');
            $table->json('pending_master_resolution')->nullable()->after('instructions');

            $table->foreign('consignor_party_id')->references('id')->on('parties')->nullOnDelete();
            $table->foreign('consignee_party_id')->references('id')->on('parties')->nullOnDelete();
            $table->foreign('from_state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('from_city_id')->references('id')->on('cities')->nullOnDelete();
            $table->foreign('to_state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('to_city_id')->references('id')->on('cities')->nullOnDelete();
        });

        Schema::table('enquiries', function (Blueprint $table): void {
            $table->string('pickup_location')->nullable()->change();
            $table->string('delivery_location')->nullable()->change();
            $table->uuid('vehicle_type_id')->nullable()->change();
        });

        Schema::table('company_enquiry_webhook_credentials', function (Blueprint $table): void {
            $table->uuid('receiving_branch_id')->nullable()->after('label');
            $table->timestampTz('last_used_at')->nullable()->after('expires_at');

            $table->foreign('receiving_branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_enquiry_webhook_credentials', function (Blueprint $table): void {
            $table->dropForeign(['receiving_branch_id']);
            $table->dropColumn(['receiving_branch_id', 'last_used_at']);
        });

        Schema::table('enquiries', function (Blueprint $table): void {
            $table->dropForeign(['consignor_party_id']);
            $table->dropForeign(['consignee_party_id']);
            $table->dropForeign(['from_state_id']);
            $table->dropForeign(['from_city_id']);
            $table->dropForeign(['to_state_id']);
            $table->dropForeign(['to_city_id']);

            $table->dropColumn([
                'party_name',
                'consignor_party_id',
                'consignee_party_id',
                'from_state_id',
                'from_city_id',
                'to_state_id',
                'to_city_id',
                'external_business_name',
                'external_contact_person_name',
                'pending_master_resolution',
            ]);
        });
    }
};
