<?php

use App\Models\LorryReceipt;
use App\Models\LrGoodsRow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('active lr goods positions are unique per lorry receipt', function () {
    $lr = LorryReceipt::factory()->create();

    LrGoodsRow::factory()->create([
        'lorry_receipt_id' => $lr->id,
        'company_id' => $lr->company_id,
        'position' => 1,
    ]);

    expect(fn () => LrGoodsRow::factory()->create([
        'lorry_receipt_id' => $lr->id,
        'company_id' => $lr->company_id,
        'position' => 1,
    ]))->toThrow(QueryException::class);
});

test('soft-deleted lr goods rows do not block reuse of the same position', function () {
    $lr = LorryReceipt::factory()->create();

    $original = LrGoodsRow::factory()->create([
        'lorry_receipt_id' => $lr->id,
        'company_id' => $lr->company_id,
        'position' => 1,
        'goods_description' => 'Original line',
    ]);

    $original->delete();

    expect($original->fresh()->trashed())->toBeTrue();

    $replacement = LrGoodsRow::factory()->create([
        'lorry_receipt_id' => $lr->id,
        'company_id' => $lr->company_id,
        'position' => 1,
        'goods_description' => 'Replacement line',
    ]);

    expect($replacement->deleted_at)->toBeNull()
        ->and(LrGoodsRow::query()->where('lorry_receipt_id', $lr->id)->where('position', 1)->count())->toBe(1)
        ->and(LrGoodsRow::onlyTrashed()->where('lorry_receipt_id', $lr->id)->where('position', 1)->count())->toBe(1)
        ->and(DB::table('lr_goods_rows')->where('lorry_receipt_id', $lr->id)->count())->toBe(2);
});

test('lr goods soft delete preserves row history without forceDelete', function () {
    $lr = LorryReceipt::factory()->create();
    $row = LrGoodsRow::factory()->create([
        'lorry_receipt_id' => $lr->id,
        'company_id' => $lr->company_id,
        'position' => 2,
    ]);

    $id = $row->id;
    $row->delete();

    expect(LrGoodsRow::withTrashed()->whereKey($id)->exists())->toBeTrue()
        ->and(LrGoodsRow::withTrashed()->findOrFail($id)->trashed())->toBeTrue();
});
