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
    public static function escape(string $term): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $term,
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public static function whereContains(Builder $builder, string $column, string $term): Builder
    {
        $escaped = self::escape($term);
        $driver = DB::connection()->getDriverName();
        $pattern = '%'.($driver === 'pgsql' ? $escaped : mb_strtolower($escaped, 'UTF-8')).'%';

        if ($driver === 'pgsql') {
            return $builder->where($column, 'ilike', $pattern);
        }

        return match ($column) {
            'invoice_number' => $builder->whereRaw('LOWER(invoice_number) LIKE ?', [$pattern]),
            'name' => $builder->whereRaw('LOWER(name) LIKE ?', [$pattern]),
            'email' => $builder->whereRaw('LOWER(email) LIKE ?', [$pattern]),
            'lr_number' => $builder->whereRaw('LOWER(lr_number) LIKE ?', [$pattern]),
            default => throw new InvalidArgumentException("Unsupported LIKE column [{$column}]."),
        };
    }
}
