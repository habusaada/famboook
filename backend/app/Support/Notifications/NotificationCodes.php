<?php

namespace App\Support\Notifications;

use Illuminate\Support\Facades\DB;

/**
 * The human reference of a notification message: NTF-000001 (FB-NOTIFY-01B.1
 * D1). Generated server-side, never derived from the primary key.
 *
 * PostgreSQL: the dedicated notification_message_code_seq sequence —
 * gap-tolerant, never reused, safe under concurrency. SQLite exists only as
 * the in-memory test database (one connection), where the next number
 * follows the highest code issued. The unique index on notice_code is the
 * final guard. (The ChangeRequestCodes pattern.)
 */
final class NotificationCodes
{
    public static function next(): string
    {
        if (DB::getDriverName() === 'pgsql') {
            $number = (int) DB::selectOne("select nextval('notification_message_code_seq') as n")->n;
        } else {
            $highest = DB::table('notification_messages')->max(DB::raw('CAST(SUBSTR(notice_code, 5) AS INTEGER)'));
            $number = (int) $highest + 1;
        }

        return sprintf('NTF-%06d', $number);
    }
}
