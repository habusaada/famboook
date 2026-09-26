<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Plain "contains" matching for the Family / People registries (docs/03
 * §93a). Case-insensitive (ILIKE on PostgreSQL) with the user's %, _ and !
 * escaped, so they match literally. No Arabic normalization, no fuzzy or
 * phonetic matching (PDD-003 stays open).
 */
final class RegistrySearch
{
    public const MAX_TERM = 100;

    /**
     * @template T of EloquentBuilder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public static function contains(EloquentBuilder|QueryBuilder $query, string $column, string $term, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        $operator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        // "!" as the LIKE escape character: a backslash inside the SQL
        // literal would make PDO's placeholder parser misread the string
        // (and lose the bindings) on PostgreSQL.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        return $query->whereRaw("{$column} {$operator} ? ESCAPE '!'", ['%'.$escaped.'%'], $boolean);
    }
}
