<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        SoftDeleteUniqueIndex::dropIndexIfExists(
            'partload_challan_lines',
            'partload_challan_lines_active_lr_unique',
        );

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_challan_lines',
            'partload_challan_lines_challan_lr_unique',
            ['company_id', 'partload_challan_id', 'lorry_receipt_id'],
        );

        Schema::table('partload_inward_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('partload_inward_lines', 'shortage_packages')) {
                $table->decimal('shortage_packages', 18, 3)->default(0)->after('weight_kg');
            }
            if (! Schema::hasColumn('partload_inward_lines', 'shortage_weight_kg')) {
                $table->decimal('shortage_weight_kg', 18, 3)->default(0)->after('shortage_packages');
            }
            if (! Schema::hasColumn('partload_inward_lines', 'is_damaged')) {
                $table->boolean('is_damaged')->default(false)->after('shortage_weight_kg');
            }
        });
    }

    public function down(): void
    {
        Schema::table('partload_inward_lines', function (Blueprint $table): void {
            $cols = array_values(array_filter([
                Schema::hasColumn('partload_inward_lines', 'shortage_packages') ? 'shortage_packages' : null,
                Schema::hasColumn('partload_inward_lines', 'shortage_weight_kg') ? 'shortage_weight_kg' : null,
                Schema::hasColumn('partload_inward_lines', 'is_damaged') ? 'is_damaged' : null,
            ]));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        SoftDeleteUniqueIndex::dropIndexIfExists(
            'partload_challan_lines',
            'partload_challan_lines_challan_lr_unique',
        );

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'partload_challan_lines',
            'partload_challan_lines_active_lr_unique',
            ['company_id', 'lorry_receipt_id'],
        );
    }
};
