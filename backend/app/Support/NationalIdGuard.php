<?php

namespace App\Support;

use App\Exceptions\DuplicateNationalIdException;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Exact National ID duplicate prevention (docs/03 §21, AUTH-ADR-058).
 *
 * Exact match only — no normalization beyond the request's whitespace
 * trimming (PDD-001 stays open), no partial or fuzzy matching, no merging.
 * National ID is deliberately not UNIQUE in the schema; the creation
 * actions enforce the rule instead, holding a PostgreSQL transaction-level
 * advisory lock per National ID so two concurrent creations with the same
 * value cannot both pass the check.
 *
 * Nothing here ever returns or logs the National ID value.
 */
final class NationalIdGuard
{
    /** Matches listed at most (a well-kept registry has one). */
    public const MAX_MATCHES = 5;

    /**
     * Non-deleted Persons holding exactly this National ID.
     *
     * @return Collection<int, Person>
     */
    public static function matches(string $nationalId, ?int $exceptPersonId = null): Collection
    {
        return Person::query()
            ->where('national_id', $nationalId)
            ->when($exceptPersonId, fn ($q) => $q->whereKeyNot($exceptPersonId))
            ->with(['activeMembership.family', 'activeMembership.relationshipType'])
            ->orderBy('id')
            ->limit(self::MAX_MATCHES)
            ->get();
    }

    /**
     * Inside the caller's transaction: serialize on this National ID, then
     * refuse if another Person already holds it. Blank values are allowed
     * (a missing National ID stays NULL).
     */
    public static function assertAvailable(?string $nationalId, string $field, ?int $exceptPersonId = null): void
    {
        if ($nationalId === null || trim($nationalId) === '') {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['famboook.national_id:'.$nationalId]);
        }

        $matches = self::matches($nationalId, $exceptPersonId);
        if ($matches->isNotEmpty()) {
            throw new DuplicateNationalIdException($field, $matches);
        }
    }

    /**
     * Safe references to existing Persons: codes and family context; the
     * name only for users who may view Persons. Never the National ID,
     * never internal ids.
     *
     * @param  Collection<int, Person>  $matches
     * @return list<array<string, mixed>>
     */
    public static function describe(Collection $matches, Request $request): array
    {
        $withNames = $request->user()?->can('person.view') ?? false;

        return $matches->map(function (Person $person) use ($withNames) {
            $membership = $person->activeMembership;

            return [
                'person_code' => $person->person_code,
                'full_name' => $withNames ? $person->full_name : null,
                'family' => $membership ? [
                    'family_code' => $membership->family->family_code,
                    'is_household_head' => $membership->is_household_head,
                    'relationship' => $membership->relationshipType?->name,
                ] : null,
            ];
        })->values()->all();
    }
}
