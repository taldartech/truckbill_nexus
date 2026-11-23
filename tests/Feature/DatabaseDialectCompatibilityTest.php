<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents dual-database migration strategy:
 *
 * - pgsql: partial UNIQUE INDEX ... WHERE deleted_at IS NULL (and optional extras);
 *   nullable scope uses COALESCE(branch_id::text, '') expression unique.
 * - mysql/mariadb: VIRTUAL generated columns that are NULL when the row is inactive,
 *   plus UNIQUE INDEX on those keys (NULL keys do not conflict). Nullable scope uses
 *   generated scope_key = CONCAT_WS(..., IFNULL(branch_id, '')).
 *
 * Full fresh-migrate on MySQL should be covered by a CI matrix when a MySQL service is available.
 */
test('SoftDeleteUniqueIndex exposes the dual-database helper surface', function () {
    expect(method_exists(SoftDeleteUniqueIndex::class, 'createActiveUniqueIndex'))->toBeTrue()
        ->and(method_exists(SoftDeleteUniqueIndex::class, 'dropIndexIfExists'))->toBeTrue()
        ->and(method_exists(SoftDeleteUniqueIndex::class, 'createNullableScopeUniqueIndex'))->toBeTrue()
        ->and(method_exists(SoftDeleteUniqueIndex::class, 'createPartialUniqueIndex'))->toBeTrue()
        ->and(method_exists(SoftDeleteUniqueIndex::class, 'dropCheckConstraintIfExists'))->toBeTrue()
        ->and(method_exists(SoftDeleteUniqueIndex::class, 'setColumnNotNull'))->toBeTrue();

    expect(SoftDeleteUniqueIndex::driver())->toBeIn(['pgsql', 'mysql', 'mariadb', 'sqlite']);
});

test('current driver rejects duplicate active positions on a soft-delete unique index', function () {
    $table = '_dialect_compat_tmp';
    $index = 'dialect_compat_tmp_position_active_unique';

    Schema::dropIfExists($table);

    Schema::create($table, function (Blueprint $blueprint) {
        $blueprint->id();
        $blueprint->unsignedInteger('position');
        $blueprint->softDeletes();
    });

    SoftDeleteUniqueIndex::createActiveUniqueIndex($table, $index, ['position']);

    DB::table($table)->insert(['position' => 1, 'deleted_at' => null]);

    expect(fn () => DB::table($table)->insert(['position' => 1, 'deleted_at' => null]))
        ->toThrow(QueryException::class);
});

test('current driver allows position reuse after soft delete on the active unique index', function () {
    $table = '_dialect_compat_reuse_tmp';
    $index = 'dialect_compat_reuse_tmp_position_active_unique';

    Schema::dropIfExists($table);

    Schema::create($table, function (Blueprint $blueprint) {
        $blueprint->id();
        $blueprint->unsignedInteger('position');
        $blueprint->softDeletes();
    });

    SoftDeleteUniqueIndex::createActiveUniqueIndex($table, $index, ['position']);

    DB::table($table)->insert(['position' => 1, 'deleted_at' => null]);
    DB::table($table)->where('position', 1)->update(['deleted_at' => now()]);

    DB::table($table)->insert(['position' => 1, 'deleted_at' => null]);

    expect(DB::table($table)->where('position', 1)->whereNull('deleted_at')->count())->toBe(1)
        ->and(DB::table($table)->where('position', 1)->whereNotNull('deleted_at')->count())->toBe(1);

    SoftDeleteUniqueIndex::dropIndexIfExists($table, $index);
    Schema::dropIfExists($table);
});

test('current driver enforces nullable-scope uniqueness on a temp table', function () {
    $table = '_dialect_scope_tmp';
    $index = 'dialect_scope_tmp_scope_unique';

    Schema::dropIfExists($table);

    Schema::create($table, function (Blueprint $blueprint) {
        $blueprint->uuid('company_id');
        $blueprint->string('document_type', 32);
        $blueprint->string('scope_type', 16);
        $blueprint->uuid('branch_id')->nullable();
    });

    SoftDeleteUniqueIndex::createNullableScopeUniqueIndex(
        $table,
        $index,
        ['company_id', 'document_type', 'scope_type'],
        'branch_id',
        'scope_key',
    );

    $companyId = '11111111-1111-1111-1111-111111111111';

    DB::table($table)->insert([
        'company_id' => $companyId,
        'document_type' => 'lr',
        'scope_type' => 'company',
        'branch_id' => null,
    ]);

    expect(fn () => DB::table($table)->insert([
        'company_id' => $companyId,
        'document_type' => 'lr',
        'scope_type' => 'company',
        'branch_id' => null,
    ]))->toThrow(QueryException::class);
});
