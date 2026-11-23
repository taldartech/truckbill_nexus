<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §7 / PO-P1-P5-FULLSTACK-AUTH D1 — company profile fields.
 * Existing rows remain nullable; product create path requires complete profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('pan', 10)->nullable()->after('name');
            $table->text('registered_address')->nullable()->after('pan');
            $table->string('state', 100)->nullable()->after('registered_address');
            $table->string('city', 100)->nullable()->after('state');
            $table->string('pin', 10)->nullable()->after('city');
            $table->string('contact_person', 255)->nullable()->after('pin');
            $table->string('mobile', 20)->nullable()->after('contact_person');
            $table->string('email', 255)->nullable()->after('mobile');
            $table->string('gst_registration_type', 32)->nullable()->after('email');
            $table->string('tax_liability', 16)->nullable()->after('gst_registration_type');
            $table->string('gstin', 15)->nullable()->after('tax_liability');
            $table->uuid('gst_rate_id')->nullable()->after('gstin');
            $table->json('gst_rate_snapshot')->nullable()->after('gst_rate_id');
            $table->string('data_visibility', 32)->nullable()->after('gst_rate_snapshot');
            $table->string('timezone', 64)->nullable()->default('Asia/Kolkata')->after('data_visibility');
            $table->uuid('logo_file_id')->nullable()->after('timezone');
            $table->uuid('signature_file_id')->nullable()->after('logo_file_id');

            $table->foreign('gst_rate_id')->references('id')->on('gst_rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign(['gst_rate_id']);
            $table->dropColumn([
                'pan',
                'registered_address',
                'state',
                'city',
                'pin',
                'contact_person',
                'mobile',
                'email',
                'gst_registration_type',
                'tax_liability',
                'gstin',
                'gst_rate_id',
                'gst_rate_snapshot',
                'data_visibility',
                'timezone',
                'logo_file_id',
                'signature_file_id',
            ]);
        });
    }
};
