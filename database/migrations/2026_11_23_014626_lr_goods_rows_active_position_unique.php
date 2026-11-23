<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
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

        DB::statement('ALTER TABLE lr_goods_rows DROP CONSTRAINT IF EXISTS lr_goods_rows_lorry_receipt_id_position_unique');

        DB::statement('
            CREATE UNIQUE INDEX lr_goods_rows_lorry_receipt_id_position_active_unique
            ON lr_goods_rows (lorry_receipt_id, position)
            WHERE deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        if (! Schema::hasTable('lr_goods_rows')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS lr_goods_rows_lorry_receipt_id_position_active_unique');

        DB::statement('
            CREATE UNIQUE INDEX lr_goods_rows_lorry_receipt_id_position_unique
            ON lr_goods_rows (lorry_receipt_id, position)
        ');
    }
};
