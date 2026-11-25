<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Lead geography as text + campaign attribution for public website capture.
 * created_by_user_id becomes nullable for system/website-originated leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->string('state', 100)->nullable()->after('company_name');
            $table->string('city', 100)->nullable()->after('state');
            $table->string('source_channel', 100)->nullable()->after('source');
            $table->string('utm_source', 255)->nullable()->after('remarks');
            $table->string('utm_medium', 255)->nullable()->after('utm_source');
            $table->string('utm_campaign', 255)->nullable()->after('utm_medium');
            $table->string('landing_url', 2000)->nullable()->after('utm_campaign');
            $table->string('referrer', 2000)->nullable()->after('landing_url');
            $table->string('ip_address', 45)->nullable()->after('referrer');
            $table->string('idempotency_key', 128)->nullable()->after('ip_address');

            $table->index('idempotency_key');
            $table->index(['city', 'state']);
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->change();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by_user_id')->nullable(false)->change();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropIndex(['idempotency_key']);
            $table->dropIndex(['city', 'state']);
            $table->dropColumn([
                'state',
                'city',
                'source_channel',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'landing_url',
                'referrer',
                'ip_address',
                'idempotency_key',
            ]);
        });
    }
};
