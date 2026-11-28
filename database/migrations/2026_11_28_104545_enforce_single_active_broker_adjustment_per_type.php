<?php

use App\Domains\Broker\Adjustment\BrokerAdjustmentActiveUniqueness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('broker_adjustments')) {
            return;
        }

        $connection = DB::connection();
        BrokerAdjustmentActiveUniqueness::reconcileActiveDuplicates($connection);
        BrokerAdjustmentActiveUniqueness::install($connection);
    }

    public function down(): void
    {
        if (! Schema::hasTable('broker_adjustments')) {
            return;
        }

        BrokerAdjustmentActiveUniqueness::uninstall(DB::connection());
    }
};
