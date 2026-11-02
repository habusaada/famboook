<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\FingerprintContext;
use App\Models\Family;
use App\Models\User;
use App\Support\FamilyAuth\KeyedFingerprint;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of family_registry_admission_events (FB-FAMILY-ADD-01; ADR §9).
 * ADD-WP-06B-3 writes DISCOVERY_LISTED only. A row names the actor, the Family, the
 * release and how many children were shown, and a keyed IP hash: never a National
 * ID, a name, a status per child or a registry payload. Append-only on PostgreSQL;
 * no retention rule is applied here (gate G-02).
 */
final class FamilyRegistryAdmissionAudit
{
    public const LISTED = 'LISTED';

    /** More matches than the source cap: 409, nothing was shown (ADD-WP-06B-3R). */
    public const RESULT_SET_TOO_BROAD = 'RESULT_SET_TOO_BROAD';

    public static function discoveryListed(User $actor, Family $family, ?int $releaseId, int $resultCount, bool $tooBroad, ?string $ip): void
    {
        DB::table('family_registry_admission_events')->insert([
            'event_type' => 'DISCOVERY_LISTED',
            'actor_user_id' => $actor->getKey(),
            'family_id' => $family->getKey(),
            'outcome_code' => $tooBroad ? self::RESULT_SET_TOO_BROAD : self::LISTED,
            'release_id' => $releaseId,
            'reference_fingerprint' => null,
            'reference_key_version' => null,
            'result_count' => $resultCount,
            'ip_hash' => $ip === null || $ip === '' ? null : KeyedFingerprint::of(FingerprintContext::FAMILY_REGISTRY_ADMISSION_IP, $ip),
            'created_at' => now(),
        ]);
    }
}
