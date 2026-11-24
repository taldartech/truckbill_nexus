<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linked_bilty_groups', function (Blueprint $table) {
            $table->date('group_date')->nullable()->after('group_number');
            $table->uuid('vehicle_id')->nullable()->after('group_date');
            $table->uuid('payee_party_id')->nullable()->after('vehicle_id');
            $table->string('driver_name', 191)->nullable()->after('payee_party_id');
            $table->string('reference', 128)->nullable()->after('driver_name');

            $table->foreign(['company_id', 'vehicle_id'], 'linked_bilty_groups_company_vehicle_fk')
                ->references(['company_id', 'id'])
                ->on('vehicles')
                ->restrictOnDelete();
            // MySQL rejects ON DELETE SET NULL on composite FKs when company_id is NOT NULL (errno 1830).
            // Preserve tenant ownership; payee_party_id may be null, but party deletion is restricted while referenced.
            $table->foreign(['company_id', 'payee_party_id'], 'linked_bilty_groups_company_payee_fk')
                ->references(['company_id', 'id'])
                ->on('parties')
                ->restrictOnDelete();
        });

        Schema::create('linked_bilty_group_payment_idempotencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('linked_bilty_group_id');
            $table->string('idempotency_key', 128);
            $table->json('transporter_voucher_ids');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'linked_bilty_group_id'], 'lb_group_pay_idem_company_group_fk')
                ->references(['company_id', 'id'])
                ->on('linked_bilty_groups')
                ->restrictOnDelete();
            $table->unique(['company_id', 'idempotency_key'], 'lb_group_pay_idem_company_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_bilty_group_payment_idempotencies');

        Schema::table('linked_bilty_groups', function (Blueprint $table) {
            $table->dropForeign('linked_bilty_groups_company_vehicle_fk');
            $table->dropForeign('linked_bilty_groups_company_payee_fk');
            $table->dropColumn(['group_date', 'vehicle_id', 'payee_party_id', 'driver_name', 'reference']);
        });
    }
};
