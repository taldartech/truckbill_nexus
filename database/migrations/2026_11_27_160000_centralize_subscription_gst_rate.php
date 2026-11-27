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
        Schema::create('subscription_tax_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gst_rate_id')->nullable();
            $table->decimal('gst_rate_percent', 5, 2)->default(0);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('gst_rate_id')->references('id')->on('gst_rates')->nullOnDelete();
        });

        $source = DB::table('platform_billing_profiles')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereNotNull('gst_rate_id')
            ->orderByDesc('updated_at')
            ->first(['gst_rate_id', 'gst_rate_percent']);

        if ($source !== null) {
            DB::table('subscription_tax_settings')->insert([
                'id' => (string) Str::uuid(),
                'gst_rate_id' => $source->gst_rate_id,
                'gst_rate_percent' => $source->gst_rate_percent,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('platform_billing_profiles', function (Blueprint $table): void {
            $table->dropForeign(['gst_rate_id']);
        });

        Schema::table('platform_billing_profiles', function (Blueprint $table): void {
            $table->dropColumn(['gst_rate_id', 'gst_rate_percent']);
        });
    }

    public function down(): void
    {
        Schema::table('platform_billing_profiles', function (Blueprint $table): void {
            $table->uuid('gst_rate_id')->nullable()->after('gstin');
            $table->decimal('gst_rate_percent', 5, 2)->default(18.00)->after('gst_rate_id');
            $table->foreign('gst_rate_id')->references('id')->on('gst_rates')->nullOnDelete();
        });

        Schema::dropIfExists('subscription_tax_settings');
    }
};
