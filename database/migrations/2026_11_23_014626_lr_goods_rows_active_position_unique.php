<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-003: preserve soft-deleted LR goods history.
 * Active positions remain unique; soft-deleted rows no longer block reuse of a position.
 * Mirrors enquiry_goods_rows_active_position_unique (prevents Batch E forceDelete class of defect).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lr_goods_rows')) {
            return;
        }

        SoftDeleteUniqueIndex::dropUniqueConstraintOrIndexIfExists(
            'lr_goods_rows',
            'lr_goods_rows_lorry_receipt_id_position_unique',
        );

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'lr_goods_rows',
            'lr_goods_rows_lorry_receipt_id_position_active_unique',
            ['lorry_receipt_id', 'position'],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('lr_goods_rows')) {
            return;
        }

        SoftDeleteUniqueIndex::dropIndexIfExists(
            'lr_goods_rows',
            'lr_goods_rows_lorry_receipt_id_position_active_unique',
        );

        SoftDeleteUniqueIndex::createUniqueIndex(
            'lr_goods_rows',
            'lr_goods_rows_lorry_receipt_id_position_unique',
            ['lorry_receipt_id', 'position'],
        );
    }
};
