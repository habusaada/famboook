<?php

namespace App\Support\Notifications;

use App\Enums\AuthIdentityStatus;
use App\Enums\UserPersonLinkStatus;
use App\Models\Family;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The publication-time recipient snapshot (FB-NOTIFY-01B.2b.3; ADR FB-NOTIFY-01
 * ADR-03): ONE set-based SELECT over a resolved family query, whose columns
 * are exactly COLUMNS, in order — so the publication transaction can write
 * every recipient with a single
 *
 *   DB::table('notification_recipients')->insertUsing(COLUMNS, query(...))
 *
 * and nothing is loaded into PHP or queried per family (no N+1). This class
 * writes nothing.
 *
 *   family_status_at_publication  families.status now
 *   head_person_id                the ACTIVE household head's person
 *                                 (uq_family_active_household_head: at most
 *                                 one), NULL when the family has none
 *   had_family_account            that head has an ACTIVE User-Person Link to
 *                                 an ACTIVE user holding an ACTIVE Family Auth
 *                                 Identity (ADR-03 "No activated family
 *                                 account" otherwise)
 *
 * The values are publication-time facts and are never rewritten later.
 */
final class NotificationRecipientSnapshot
{
    public const COLUMNS = [
        'message_id',
        'family_id',
        'family_status_at_publication',
        'head_person_id',
        'had_family_account',
        'created_at',
        'updated_at',
    ];

    /** @param  Builder<Family>  $families  a resolved audience (NotificationAudienceResolver) */
    public static function query(Builder $families, int $messageId, DateTimeInterface $now): QueryBuilder
    {
        $head = fn (QueryBuilder $q) => $q->select('hm.person_id')
            ->from('family_memberships as hm')
            ->whereColumn('hm.family_id', 'families.id')
            ->where('hm.is_active', true)
            ->where('hm.is_household_head', true)
            ->limit(1);

        $account = DB::query()->selectRaw('1')
            ->from('family_memberships as am')
            ->join('user_person_links as al', 'al.person_id', '=', 'am.person_id')
            ->join('users as au', 'au.id', '=', 'al.user_id')
            ->whereColumn('am.family_id', 'families.id')
            ->where('am.is_active', true)
            ->where('am.is_household_head', true)
            ->where('al.status', UserPersonLinkStatus::ACTIVE->value)
            ->where('au.is_active', true)
            ->whereExists(fn (QueryBuilder $i) => $i->selectRaw('1')
                ->from('family_auth_identities as ai')
                ->whereColumn('ai.user_id', 'al.user_id')
                ->where('ai.status', AuthIdentityStatus::ACTIVE->value));

        $time = $now->format('Y-m-d H:i:s');

        return $families->clone()->toBase()
            ->select([])
            ->selectRaw(self::typed('bigint').' as message_id', [$messageId])
            ->addSelect(['families.id as family_id', 'families.status as family_status_at_publication'])
            ->selectSub($head, 'head_person_id')
            ->selectRaw('EXISTS ('.$account->toSql().') as had_family_account', $account->getBindings())
            ->selectRaw(self::typed('timestamp').' as created_at', [$time])
            ->selectRaw(self::typed('timestamp').' as updated_at', [$time])
            ->reorder()
            ->orderBy('families.id');
    }

    /**
     * A bound value with an explicit type on PostgreSQL, whose untyped
     * parameters in a SELECT list would otherwise be text. (SQLite has no
     * such types: a CAST there would change the value.)
     */
    private static function typed(string $type): string
    {
        return DB::getDriverName() === 'pgsql' ? "CAST(? AS {$type})" : '?';
    }
}
