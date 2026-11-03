<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\FingerprintContext;
use App\Enums\RegistryChildReason;
use App\Models\Family;
use App\Models\User;
use App\Support\FamilyAuth\KeyedFingerprint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of family_registry_admission_events (FB-FAMILY-ADD-01; ADR §9).
 * A row names the actor, the Family, the release, a controlled outcome code, a
 * keyed fingerprint of the child (admission events only) and a keyed IP hash:
 * never a National ID, a name, a token, a status per child or a registry payload.
 * Append-only on PostgreSQL; no retention rule is applied here (gate G-02).
 *
 * - DISCOVERY_LISTED (ADD-WP-06B-3): one row per listing or too-broad refusal.
 * - ADD-WP-07B-1, foundation only (nothing calls these yet):
 *   - admissionSucceeded() runs ONLY inside the caller's transaction, so the event
 *     commits or rolls back with the membership and the evidence it describes;
 *   - replayedFromEvidence() and admissionRefused() are written on their own, after
 *     the admission transaction (a refusal rolls it back).
 *   Every argument is checked before the insert; a bad one or a failed insert
 *   throws, and the caller must answer without changing anything (fail closed).
 * - The child's fingerprint is referenceFingerprint(): the same value the evidence
 *   row stores, under FingerprintContext::FAMILY_REGISTRY_ADMISSION_REFERENCE.
 * - ADD-WP-07B-2a (ADD-WP-07B-2R D-B5, D-E3), foundation only:
 *   - replayedFromEvidence() records a replay with the STORED child fingerprint, key
 *     version and release of the committed evidence, never recomputed. It is the only
 *     replay path: the earlier admissionReplayed(), which recomputed the fingerprint
 *     under the current key, is removed (ADD-WP-07B-2a-F);
 *   - idempotencyConflict() records the actor's key used for another request, with no
 *     child and no release;
 *   - registryUnavailable() records a refusal because the registry or the release pin
 *     could not be used (REGISTRY_UNAVAILABLE), with no child.
 */
final class FamilyRegistryAdmissionAudit
{
    public const DISCOVERY_LISTED = 'DISCOVERY_LISTED';

    public const ADMISSION_SUCCEEDED = 'ADMISSION_SUCCEEDED';

    public const ADMISSION_REPLAYED = 'ADMISSION_REPLAYED';

    public const ADMISSION_REFUSED = 'ADMISSION_REFUSED';

    public const LISTED = 'LISTED';

    /** More matches than the source cap: 409, nothing was shown (ADD-WP-06B-3R); also an admission refusal. */
    public const RESULT_SET_TOO_BROAD = 'RESULT_SET_TOO_BROAD';

    public const PERSON_CREATED = 'PERSON_CREATED';

    public const PERSON_ATTACHED = 'PERSON_ATTACHED';

    public const REPLAYED = 'REPLAYED';

    /** Admission refusals that are not an eligibility reason (RegistryChildReason). */
    public const REFERENCE_INVALID = 'REFERENCE_INVALID';

    public const RELEASE_CHANGED = 'RELEASE_CHANGED';

    public const IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';

    public const CONTEXT_CHANGED = 'CONTEXT_CHANGED';

    /** A uniqueness guard refused a write the locks should have prevented. */
    public const LOST_RACE = 'LOST_RACE';

    /** The registry, the release pin or a bounded wait could not be used: retryable, nothing changed. */
    public const REGISTRY_UNAVAILABLE = 'REGISTRY_UNAVAILABLE';

    private const PROCEDURAL_REFUSALS = [
        self::REFERENCE_INVALID, self::RELEASE_CHANGED, self::IDEMPOTENCY_CONFLICT, self::CONTEXT_CHANGED,
        self::LOST_RACE, self::RESULT_SET_TOO_BROAD, self::REGISTRY_UNAVAILABLE,
    ];

    public static function discoveryListed(User $actor, Family $family, ?int $releaseId, int $resultCount, bool $tooBroad, ?string $ip): void
    {
        DB::table('family_registry_admission_events')->insert([
            'event_type' => self::DISCOVERY_LISTED,
            'actor_user_id' => $actor->getKey(),
            'family_id' => $family->getKey(),
            'outcome_code' => $tooBroad ? self::RESULT_SET_TOO_BROAD : self::LISTED,
            'release_id' => $releaseId,
            'reference_fingerprint' => null,
            'reference_key_version' => null,
            'result_count' => $resultCount,
            'ip_hash' => self::ipHash($ip),
            'created_at' => now(),
        ]);
    }

    /** The child of an admission, as evidence and events store it. */
    public static function referenceFingerprint(#[\SensitiveParameter] string $childNationalId): array
    {
        if (! CivilRegistryValues::validNationalId($childNationalId)) {
            throw new InvalidArgumentException('An admission names a child by a valid nine-digit National ID.');
        }
        $version = KeyedFingerprint::currentVersion();

        return ['fingerprint' => KeyedFingerprint::of(FingerprintContext::FAMILY_REGISTRY_ADMISSION_REFERENCE, $childNationalId, $version), 'key_version' => $version];
    }

    /** @return list<string> every code admissionRefused() accepts */
    public static function refusalCodes(): array
    {
        return [...array_map(fn (RegistryChildReason $reason) => $reason->value, RegistryChildReason::cases()), ...self::PROCEDURAL_REFUSALS];
    }

    /** Inside the transaction that creates the membership and the evidence row; never on its own. */
    public static function admissionSucceeded(User $actor, Family $family, int $releaseId, string $outcome, #[\SensitiveParameter] string $childNationalId, ?string $ip): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('An admission success is recorded only inside the admission transaction.');
        }
        if (! in_array($outcome, [self::PERSON_CREATED, self::PERSON_ATTACHED], true)) {
            throw new InvalidArgumentException('Unknown admission outcome.');
        }
        self::admission(self::ADMISSION_SUCCEEDED, $actor, $family, $outcome, self::release($releaseId), self::referenceFingerprint($childNationalId), $ip);
    }

    /** $childNationalId and $releaseId are NULL when the refusal came before they were known (an invalid reference). */
    public static function admissionRefused(User $actor, Family $family, string $code, ?int $releaseId, #[\SensitiveParameter] ?string $childNationalId, ?string $ip): void
    {
        if (! in_array($code, self::refusalCodes(), true)) {
            throw new InvalidArgumentException('Unknown admission refusal code.');
        }
        self::admission(self::ADMISSION_REFUSED, $actor, $family, $code, $releaseId === null ? null : self::release($releaseId),
            $childNationalId === null ? null : self::referenceFingerprint($childNationalId), $ip);
    }

    /** A replay of a committed admission, recorded with what its evidence stored. */
    public static function replayedFromEvidence(User $actor, Family $family, RegistryAdmissionEvidence $evidence, ?string $ip): void
    {
        if ($evidence->admittedBy !== (int) $actor->getKey() || $evidence->familyId !== (int) $family->getKey()) {
            throw new InvalidArgumentException('A replay is recorded only for the actor and Family of its evidence.');
        }
        self::admission(self::ADMISSION_REPLAYED, $actor, $family, self::REPLAYED, self::release($evidence->releaseId),
            ['fingerprint' => $evidence->referenceFingerprint, 'key_version' => $evidence->referenceKeyVersion], $ip);
    }

    /** The actor's idempotency key was already used for another request: nothing of either request is repeated. */
    public static function idempotencyConflict(User $actor, Family $family, ?string $ip): void
    {
        self::admissionRefused($actor, $family, self::IDEMPOTENCY_CONFLICT, null, null, $ip);
    }

    /** $releaseId is the release the attempt was about, when one was known. */
    public static function registryUnavailable(User $actor, Family $family, ?int $releaseId, ?string $ip): void
    {
        self::admissionRefused($actor, $family, self::REGISTRY_UNAVAILABLE, $releaseId, null, $ip);
    }

    /** @param  array{fingerprint: string, key_version: int}|null  $reference */
    private static function admission(string $type, User $actor, Family $family, string $outcome, ?int $releaseId, ?array $reference, ?string $ip): void
    {
        DB::table('family_registry_admission_events')->insert([
            'event_type' => $type,
            'actor_user_id' => $actor->getKey(),
            'family_id' => $family->getKey(),
            'outcome_code' => $outcome,
            'release_id' => $releaseId,
            'reference_fingerprint' => $reference['fingerprint'] ?? null,
            'reference_key_version' => $reference['key_version'] ?? null,
            'result_count' => null,
            'ip_hash' => self::ipHash($ip),
            'created_at' => now(),
        ]);
    }

    private static function release(int $releaseId): int
    {
        if ($releaseId < 1) {
            throw new InvalidArgumentException('A release id is positive.');
        }

        return $releaseId;
    }

    private static function ipHash(?string $ip): ?string
    {
        return $ip === null || $ip === '' ? null : KeyedFingerprint::of(FingerprintContext::FAMILY_REGISTRY_ADMISSION_IP, $ip);
    }
}
