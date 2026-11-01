<?php

namespace App\Support;

use App\Enums\FamilyMemberIdentityOutcome;
use App\Models\Person;

/** The answer of FamilyMemberIdentity::resolve(): $person only when ATTACHABLE, locked. */
final class FamilyMemberIdentityResolution
{
    public function __construct(
        public readonly FamilyMemberIdentityOutcome $outcome,
        public readonly ?Person $person = null,
    ) {}
}
