<?php

namespace App\Domains\Master\Party;

use App\Models\Party;
use App\Support\Database\CaseInsensitiveLike;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single Party search used by the register and every role selector (Rules §25).
 *
 * Matches name, short name and contact/tax identifiers; exact and prefix name or
 * short-name matches rank before substring matches.
 */
final class PartySearch
{
    /** @var list<string> */
    private const COLUMNS = [
        'name',
        'short_name',
        'contact_person',
        'contact_number',
        'email',
        'pan',
        'gstin',
        'city',
    ];

    /**
     * @param  Builder<Party>  $builder
     * @return Builder<Party>
     */
    public function apply(Builder $builder, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $builder->orderBy('name');
        }

        CaseInsensitiveLike::whereAnyContains($builder, self::COLUMNS, $term);

        $exact = mb_strtolower($term, 'UTF-8');
        $prefix = CaseInsensitiveLike::escape($exact).'%';

        return $builder
            ->orderByRaw(
                'CASE WHEN LOWER(name) = ? OR LOWER(short_name) = ? THEN 0 '
                .'WHEN LOWER(name) LIKE ? OR LOWER(short_name) LIKE ? THEN 1 ELSE 2 END',
                [$exact, $exact, $prefix, $prefix],
            )
            ->orderBy('name');
    }
}
