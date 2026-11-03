<?php

namespace App\Http\Middleware;

/**
 * The registry child ADMISSION gate (FB-FAMILY-ADD-01 ADD-WP-07C; ADR §7 Admission, §10).
 * Runs AFTER family.context, on the admission route only, and FAILS CLOSED exactly like
 * the discovery gate: the flag must allow this Family and the account must hold
 * family-member.admit-from-registry (no role has it), both refusals being the same 404
 * ADMISSION_UNAVAILABLE.
 *
 * Then its own per-user limits, counted here and BEFORE the request is validated or the
 * action runs (5 per minute, 20 per hour by default, independent of discovery and of the
 * civil lookup): an invalid or refused attempt counts too, and a closed gate counts
 * nothing. 429 RATE_LIMITED with Retry-After. The keys name the account id only.
 *
 * AdmitRegistryChildAction checks the flag and the permission again (defense in depth).
 */
class EnsureRegistryAdmissionAvailable extends EnsureRegistryDiscoveryAvailable
{
    protected function windows(int|string $id): array
    {
        return [
            "family-registry-admission|user|{$id}|minute" => [(int) config('family_registry_admission.limits.admission_user_minute'), 60],
            "family-registry-admission|user|{$id}|hour" => [(int) config('family_registry_admission.limits.admission_user_hour'), 3600],
        ];
    }
}
