<?php

namespace App\Support\CivilLookup;

use App\Exceptions\CivilLookupException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Accounting of full National ID disclosures at the STANDARD level
 * (FB-CIVIL-02F, ADR §L-6c): the first disclosure of a record to a staff user
 * on a Gaza-local day costs one quota unit; reopening it that day is free.
 *
 * charge() is atomic: in one transaction it inserts the (user, day, release,
 * record) row — ON CONFLICT DO NOTHING on its UNIQUE key — and, only when the
 * row is new, consumes one unit with CivilLookupQuota::consume (the existing
 * guarded upsert). A refused consume throws QUOTA_EXHAUSTED and rolls the row
 * back, so nothing is disclosed and nothing is marked. A concurrent insert of
 * the same key waits for the first transaction (PostgreSQL), then finds the
 * row (free) or, if the first rolled back, inserts and consumes itself.
 */
final class CivilLookupDisclosure
{
    private const TABLE = 'civil_lookup_record_disclosures';

    /**
     * @param  bool  $exempt  the requester supplied this record's exact National ID (marked, not charged)
     * @return int|null the quota used after a charge; null when nothing was charged
     *
     * @throws CivilLookupException QUOTA_EXHAUSTED
     */
    public static function charge(User $user, EffectivePolicy $policy, CivilRecord $record, bool $exempt = false): ?int
    {
        return DB::transaction(function () use ($user, $policy, $record, $exempt) {
            if (! self::mark($user, $record) || $exempt) {
                return null;
            }

            return CivilLookupQuota::consume($user, $policy);
        });
    }

    /** Records the disclosure without a charge (an already charged lookup); true when the row is new. */
    public static function mark(User $user, CivilRecord $record): bool
    {
        return DB::table(self::TABLE)->insertOrIgnore([
            'user_id' => $user->id,
            'disclosure_date' => CivilLookupQuota::today(),
            'release_id' => $record->releaseId,
            'record_id' => $record->recordId,
            'created_at' => now(),
        ]) === 1;
    }
}
