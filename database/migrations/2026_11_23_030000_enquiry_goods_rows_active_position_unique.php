<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
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

        DB::statement('ALTER TABLE enquiry_goods_rows DROP CONSTRAINT IF EXISTS enquiry_goods_rows_enquiry_id_position_unique');

        DB::statement('
            CREATE UNIQUE INDEX enquiry_goods_rows_enquiry_id_position_active_unique
            ON enquiry_goods_rows (enquiry_id, position)
            WHERE deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        if (! Schema::hasTable('enquiry_goods_rows')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS enquiry_goods_rows_enquiry_id_position_active_unique');

        DB::statement('
            CREATE UNIQUE INDEX enquiry_goods_rows_enquiry_id_position_unique
            ON enquiry_goods_rows (enquiry_id, position)
        ');
    }
};
