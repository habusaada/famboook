<?php

namespace App\Support\ChangeRequests;

use Illuminate\Support\Facades\DB;

/**
 * The human reference of a Change Request: CRQ-000001 (docs/02 §51, AE-6).
 * Generated server-side, never derived from the primary key.
 *
 * PostgreSQL: the dedicated change_request_code_seq sequence — gap-tolerant,
 * never reused, safe under concurrency. SQLite exists only as the in-memory
 * test database (one connection), where the next number follows the highest
 * code issued. The unique index on request_code is the final guard.
 */
final class ChangeRequestCodes
{
    public static function next(): string
    {
        if (DB::getDriverName() === 'pgsql') {
            $number = (int) DB::selectOne("select nextval('change_request_code_seq') as n")->n;
        } else {
            $highest = DB::table('change_requests')->max(DB::raw('CAST(SUBSTR(request_code, 5) AS INTEGER)'));
            $number = (int) $highest + 1;
        }

        return sprintf('CRQ-%06d', $number);
    }
}
