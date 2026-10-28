<?php

namespace App\Support\CivilLookup;

use App\Exceptions\CivilLookupException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The daily lookup quota (FB-CIVIL-02A, ADR §L-6): one counter per user per
 * Asia/Gaza calendar day (DST-aware), reset at local midnight.
 *
 * Callers check → execute → consume. consume() is ONE statement (an upsert
 * that only increments while used < limit), so concurrent requests can never
 * exceed the limit; when it changes nothing the result is withheld and
 * QUOTA_EXHAUSTED is answered. An unlimited policy still counts (statistics).
 */
final class CivilLookupQuota
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('civil_lookup.timezone', 'Asia/Gaza'));
    }

    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** The next local midnight, in UTC ISO 8601. */
    public static function resetsAt(): string
    {
        return self::now()->addDay()->startOfDay()->utc()->toIso8601String();
    }

    public static function used(User $user): int
    {
        return (int) DB::table('civil_lookup_daily_usage')->where('user_id', $user->id)->where('usage_date', self::today())->value('used');
    }

    /**
     * @return array{enabled: bool, unlimited: bool, daily_limit: ?int, used: int, remaining: ?int, resets_at: string, reason: ?string}
     */
    public static function status(User $user): array
    {
        $access = CivilLookupAccess::evaluate($user);
        $policy = $access['policy'];
        $used = self::used($user);

        return [
            'enabled' => $access['allowed'],
            'unlimited' => $policy?->unlimited() ?? false,
            'daily_limit' => $policy?->dailyLimit,
            'used' => $used,
            'remaining' => $policy === null ? 0 : ($policy->unlimited() ? null : max(0, $policy->dailyLimit - $used)),
            'resets_at' => self::resetsAt(),
            'reason' => $access['reason'],
        ];
    }

    /** @throws CivilLookupException QUOTA_EXHAUSTED */
    public static function assertAvailable(User $user, EffectivePolicy $policy): void
    {
        if (! $policy->unlimited() && self::used($user) >= $policy->dailyLimit) {
            throw self::exhausted($user, $policy);
        }
    }

    /**
     * Counts one lookup; returns the count after it.
     *
     * @throws CivilLookupException QUOTA_EXHAUSTED when the limit was reached meanwhile
     */
    public static function consume(User $user, EffectivePolicy $policy): int
    {
        $bindings = [$user->id, self::today(), now()];
        if (! $policy->unlimited()) {
            $bindings[] = $policy->dailyLimit;
        }
        $row = DB::selectOne(self::consumeStatement(! $policy->unlimited()), $bindings);
        if ($row === null) {
            throw self::exhausted($user, $policy);
        }

        return (int) $row->used;
    }

    /**
     * The single consuming statement: insert the day's row with 1, or add 1 only
     * while used < limit (bindings: user, date, time[, limit]). Returns no row
     * when the limit was already reached — also against a concurrent request,
     * whose committed increment the guard re-checks.
     */
    public static function consumeStatement(bool $limited): string
    {
        return 'INSERT INTO civil_lookup_daily_usage (user_id, usage_date, used, updated_at) VALUES (?, ?, 1, ?)
            ON CONFLICT (user_id, usage_date) DO UPDATE SET used = civil_lookup_daily_usage.used + 1, updated_at = excluded.updated_at'
            .($limited ? ' WHERE civil_lookup_daily_usage.used < ?' : '').' RETURNING used';
    }

    private static function exhausted(User $user, EffectivePolicy $policy): CivilLookupException
    {
        return new CivilLookupException(CivilLookupException::QUOTA_EXHAUSTED, ['quota' => [
            'daily_limit' => $policy->dailyLimit, 'used' => self::used($user), 'remaining' => 0, 'resets_at' => self::resetsAt(),
        ]]);
    }
}
