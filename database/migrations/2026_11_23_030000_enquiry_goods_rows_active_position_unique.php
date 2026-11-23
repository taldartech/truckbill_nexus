<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-003: preserve soft-deleted enquiry goods history.
 * Active positions remain unique; soft-deleted rows no longer block reuse of a position.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enquiry_goods_rows')) {
            return;
        }

        SoftDeleteUniqueIndex::dropUniqueConstraintOrIndexIfExists(
            'enquiry_goods_rows',
            'enquiry_goods_rows_enquiry_id_position_unique',
        );

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'enquiry_goods_rows',
            'enquiry_goods_rows_enquiry_id_position_active_unique',
            ['enquiry_id', 'position'],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('enquiry_goods_rows')) {
            return;
        }

        SoftDeleteUniqueIndex::dropIndexIfExists(
            'enquiry_goods_rows',
            'enquiry_goods_rows_enquiry_id_position_active_unique',
        );

        SoftDeleteUniqueIndex::createUniqueIndex(
            'enquiry_goods_rows',
            'enquiry_goods_rows_enquiry_id_position_unique',
            ['enquiry_id', 'position'],
        );
    }
};
