<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive geography FKs. Preserve free-text state/city for historical display.
 * Matching of historical text is performed by IndiaGeographySeeder / artisan command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->uuid('state_id')->nullable()->after('state');
            $table->uuid('city_id')->nullable()->after('city');
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->uuid('state_id')->nullable()->after('state');
            $table->uuid('city_id')->nullable()->after('city');
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
        });

        Schema::table('parties', function (Blueprint $table): void {
            $table->uuid('state_id')->nullable()->after('state');
            $table->uuid('city_id')->nullable()->after('city');
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
        });

        Schema::table('stations', function (Blueprint $table): void {
            $table->uuid('state_id')->nullable()->after('state');
            $table->uuid('city_id')->nullable()->after('city');
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign(['state_id']);
            $table->dropForeign(['city_id']);
            $table->dropColumn(['state_id', 'city_id']);
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropForeign(['state_id']);
            $table->dropForeign(['city_id']);
            $table->dropColumn(['state_id', 'city_id']);
        });

        Schema::table('parties', function (Blueprint $table): void {
            $table->dropForeign(['state_id']);
            $table->dropForeign(['city_id']);
            $table->dropColumn(['state_id', 'city_id']);
        });

        Schema::table('stations', function (Blueprint $table): void {
            $table->dropForeign(['state_id']);
            $table->dropForeign(['city_id']);
            $table->dropColumn(['state_id', 'city_id']);
        });
    }
};
