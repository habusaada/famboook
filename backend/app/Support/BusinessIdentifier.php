<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Generates permanent business identifiers (e.g. FAM-000001, PER-000001)
 * as required by docs/02-DATA-DICTIONARY.md §71 and docs/04-DATABASE.md §7.
 *
 * The documentation defines the *display format* but not an exact
 * generation algorithm (sequential vs. gapped, reset policy, concurrency
 * handling). This is the smallest safe implementation: it zero-pads the
 * table's own auto-increment id, sourced atomically from PostgreSQL's
 * sequence via pg_get_serial_sequence(), so the id — and therefore the
 * code — is reserved uniquely even under concurrent inserts. No numbering
 * policy beyond the documented format is invented. If a different policy
 * is approved later (e.g. gap-free numbering, region-scoped prefixes),
 * this is the single place to change.
 */
class BusinessIdentifier
{
    public static function nextId(string $table): int
    {
        if (DB::getDriverName() === 'pgsql') {
            $sequence = DB::selectOne(
                "select pg_get_serial_sequence(?, 'id') as sequence",
                [$table]
            )->sequence;

            return (int) DB::selectOne("select nextval('{$sequence}') as id")->id;
        }

        // SQLite (test suite): no sequence object to pre-fetch from.
        // Reserve the next id by taking the current max + 1. This is not
        // safe under concurrent writers, which is acceptable only because
        // SQLite is exclusively the single-threaded test environment here;
        // production always runs on PostgreSQL per docs/04 §2.
        return (int) DB::table($table)->max('id') + 1;
    }

    public static function format(string $prefix, int $id): string
    {
        return sprintf('%s-%06d', $prefix, $id);
    }
}
