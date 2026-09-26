<?php

namespace App\Support\Reporting;

use Illuminate\Database\Query\Builder;

/** Server-side pagination of report detail rows (page / per_page). */
final class ReportPage
{
    /**
     * @param  callable(object): array<string, mixed>  $map
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public static function of(Builder $query, int $perPage, callable $map): array
    {
        $page = $query->paginate($perPage);

        return [
            'data' => array_map($map, $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /** "YYYY-MM-DD" from a date/timestamp column value (null-safe). */
    public static function date(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
