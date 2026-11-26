<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_tyre_layouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 128);
            $table->unsignedSmallInteger('spare_tyre_count')->default(0);
            $table->unsignedSmallInteger('drive_tyre_count')->default(0);
            $table->boolean('needs_axle_completion')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_user_id', 'is_active'], 'owner_tyre_layouts_owner_active_idx');
        });

        Schema::create('owner_tyre_layout_axles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tyre_layout_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('configuration', 16);
            $table->timestamps();

            $table->foreign('tyre_layout_id', 'otl_axles_layout_fk')
                ->references('id')
                ->on('owner_tyre_layouts')
                ->cascadeOnDelete();
            $table->unique(['tyre_layout_id', 'sequence'], 'otl_axles_sequence_unique');
        });

        Schema::create('owner_tyre_layout_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tyre_layout_id');
            $table->string('position_key', 32);
            $table->unsignedSmallInteger('axle_sequence');
            $table->string('side', 1);
            $table->string('ring', 16)->nullable();
            $table->string('label', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tyre_layout_id', 'otl_positions_layout_fk')
                ->references('id')
                ->on('owner_tyre_layouts')
                ->cascadeOnDelete();
            $table->unique(['tyre_layout_id', 'position_key'], 'otl_positions_key_unique');
        });

        $tyreRows = DB::table('owner_catalogue_items')
            ->where('catalogue_type', 'tyre_layout')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($tyreRows as $row) {
            DB::table('owner_tyre_layouts')->insert([
                'id' => $row->id,
                'owner_user_id' => $row->owner_user_id,
                'name' => $row->name,
                'spare_tyre_count' => 0,
                'drive_tyre_count' => 0,
                'needs_axle_completion' => true,
                'is_active' => (bool) $row->is_active,
                'sort_order' => (int) $row->sort_order,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'deleted_at' => null,
            ]);
        }

        DB::table('owner_catalogue_items')
            ->where('catalogue_type', 'tyre_layout')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropForeign('vehicles_tyre_layout_fk');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreign('tyre_layout_id', 'vehicles_tyre_layout_fk')
                ->references('id')
                ->on('owner_tyre_layouts')
                ->nullOnDelete();
        });

        SoftDeleteUniqueIndex::dropUniqueConstraintOrIndexIfExists(
            'owner_catalogue_items',
            'owner_catalogue_unique',
        );

        Schema::table('owner_catalogue_items', function (Blueprint $table): void {
            $table->string('code', 64)->nullable()->change();
        });

        DB::table('owner_catalogue_items')
            ->whereIn('catalogue_type', [
                'goods_type',
                'truck_type',
                'expense_category',
                'package_type',
            ])
            ->update(['code' => null]);

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'owner_catalogue_items',
            'owner_catalogue_name_active_unique',
            ['owner_user_id', 'catalogue_type', 'name'],
            extraWhere: "catalogue_type IN ('goods_type', 'truck_type', 'expense_category', 'package_type')",
        );

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'owner_catalogue_items',
            'owner_catalogue_document_code_unique',
            ['owner_user_id', 'catalogue_type', 'code'],
            extraWhere: "catalogue_type = 'document_type' AND code IS NOT NULL",
        );

        SoftDeleteUniqueIndex::createActiveUniqueIndex(
            'owner_tyre_layouts',
            'owner_tyre_layouts_name_active_unique',
            ['owner_user_id', 'name'],
        );
    }

    public function down(): void
    {
        SoftDeleteUniqueIndex::dropIndexIfExists(
            'owner_tyre_layouts',
            'owner_tyre_layouts_name_active_unique',
        );
        SoftDeleteUniqueIndex::dropIndexIfExists(
            'owner_catalogue_items',
            'owner_catalogue_document_code_unique',
        );
        SoftDeleteUniqueIndex::dropIndexIfExists(
            'owner_catalogue_items',
            'owner_catalogue_name_active_unique',
        );

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropForeign('vehicles_tyre_layout_fk');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreign('tyre_layout_id', 'vehicles_tyre_layout_fk')
                ->references('id')
                ->on('owner_catalogue_items')
                ->nullOnDelete();
        });

        Schema::table('owner_catalogue_items', function (Blueprint $table): void {
            $table->string('code', 64)->nullable(false)->change();
        });

        SoftDeleteUniqueIndex::createUniqueIndex(
            'owner_catalogue_items',
            'owner_catalogue_unique',
            ['owner_user_id', 'catalogue_type', 'code'],
        );

        Schema::dropIfExists('owner_tyre_layout_positions');
        Schema::dropIfExists('owner_tyre_layout_axles');
        Schema::dropIfExists('owner_tyre_layouts');
    }
};
