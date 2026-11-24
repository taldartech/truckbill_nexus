<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Portable case-insensitive LIKE for PostgreSQL and MySQL 8.4.
 */
final class CaseInsensitiveLike
{
    /** @var list<string> */
    private const MYSQL_COLUMNS = [
        'account_holder_name',
        'bank_name',
        'chassis_number',
        'city',
        'code',
        'contact_number',
        'email',
        'gstin',
        'invoice_number',
        'lr_number',
        'make',
        'model',
        'name',
        'pan',
        'registration_number',
    ];

    public static function escape(string $term): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $term,
        );
    }

    public static function containsPattern(string $term): string
    {
        $escaped = self::escape(trim($term));
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return '%'.$escaped.'%';
        }

        return '%'.mb_strtolower($escaped, 'UTF-8').'%';
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    public static function whereAnyContains(Builder $builder, array $columns, string $term): Builder
    {
        if ($columns === []) {
            throw new InvalidArgumentException('At least one column is required for search.');
        }

        $pattern = self::containsPattern($term);
        $driver = DB::connection()->getDriverName();

        return $builder->where(function (Builder $inner) use ($columns, $pattern, $driver): void {
            foreach ($columns as $index => $column) {
                self::assertSupportedColumn($column);
                if ($driver === 'pgsql') {
                    if ($index === 0) {
                        $inner->where($column, 'ilike', $pattern);
                    } else {
                        $inner->orWhere($column, 'ilike', $pattern);
                    }

                    continue;
                }

                $sql = 'LOWER(`'.$column.'`) LIKE ?';
                if ($index === 0) {
                    $inner->whereRaw($sql, [$pattern]);
                } else {
                    $inner->orWhereRaw($sql, [$pattern]);
                }
            }
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public static function whereContains(Builder $builder, string $column, string $term): Builder
    {
        return self::whereAnyContains($builder, [$column], $term);
    }

    private static function assertSupportedColumn(string $column): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return;
        }

        if (! in_array($column, self::MYSQL_COLUMNS, true)) {
            throw new InvalidArgumentException("Unsupported LIKE column [{$column}].");
        }
    }
}
