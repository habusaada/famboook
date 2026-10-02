<?php

namespace App\Support\FamilyAuth;

use App\Enums\FamilyAccessDenial;
use App\Models\Family;
use App\Models\FamilyAuthIdentity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;

/**
 * What the Family access resolver decided (docs/11 §30a). Read-only, and
 * built only by FamilyAccessResolver: either a denial and nothing else, or
 * the resolved records. The Family can only come from the resolver's own
 * membership query — there is no way to hand one in from a request.
 */
final readonly class FamilyAccessResult
{
    private function __construct(
        public ?FamilyAccessDenial $denial,
        public ?User $user = null,
        public ?UserPersonLink $link = null,
        public ?Person $person = null,
        public ?FamilyAuthIdentity $authIdentity = null,
        public ?FamilyMembership $membership = null,
        public ?Family $family = null,
    ) {}

    /** @internal FamilyAccessResolver only. */
    public static function denied(FamilyAccessDenial $denial): self
    {
        return new self($denial);
    }

    /** @internal FamilyAccessResolver only. */
    public static function identity(User $user, UserPersonLink $link, Person $person, FamilyAuthIdentity $authIdentity): self
    {
        return new self(null, $user, $link, $person, $authIdentity);
    }

    /** @internal FamilyAccessResolver only. */
    public function withFamily(FamilyMembership $membership, Family $family): self
    {
        return new self(null, $this->user, $this->link, $this->person, $this->authIdentity, $membership, $family);
    }

    public function allowed(): bool
    {
        return $this->denial === null;
    }

    /** True once a Family context was resolved (identity alone is not one). */
    public function hasFamilyContext(): bool
    {
        return $this->denial === null && $this->family !== null;
    }
}
