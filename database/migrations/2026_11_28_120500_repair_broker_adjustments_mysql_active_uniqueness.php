<?php

use App\Domains\Broker\Adjustment\BrokerAdjustmentActiveUniqueness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Environments that completed the original MySQL unconditional index before this fix
 * need the generated-column uniqueness strategy instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('broker_adjustments')) {
            return;
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        if (! BrokerAdjustmentActiveUniqueness::hasLegacyMysqlUnconditionalIndex($connection)) {
            return;
        }

        BrokerAdjustmentActiveUniqueness::reconcileActiveDuplicates($connection);
        BrokerAdjustmentActiveUniqueness::install($connection);
    }

    public function down(): void
    {
        // No-op: down is handled by the primary migration's uninstall when rolling back as a group.
    }
};
