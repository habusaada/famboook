<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/**
 * An AUTHORITATIVE source of life and death status (FB-FAMILY-ADD-01 RR-02; D-06, G-05).
 * The only thing that can prove a child alive: the civil registry cannot (an empty
 * `deathdate` is not proof, and `presonstate` is not interpreted).
 *
 * NOTHING implements it in the application and nothing binds it. Which official source
 * proves life, and how fresh its evidence must be, is gate G-05: an implementation is its
 * own approved work package. Until then RegistryLifeStatus answers UNVERIFIED for
 * everyone. Synthetic implementations exist under tests/ only.
 *
 * Contract: answer what the source states for this National ID, with the time the
 * source established it. Never infer, never default; a failure to read throws.
 */
interface RegistryLifeVerifier
{
    public function verify(#[\SensitiveParameter] string $nationalId): LifeVerification;
}
