<?php

namespace App\Support\ChangeRequests;

use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use LogicException;

/**
 * What a Change Request is about inside its Family (PWA-5b): the Family
 * itself, or one household member — a membership of THIS Family and its
 * Person (docs/11 FP-ADR-063: the request stores the internal membership id;
 * the family names the member by member_ref, never by an id).
 *
 * Built only from server-resolved rows: at submission by the handler from
 * the family.context Family; at approve / apply from the stored ids, re-read.
 */
final readonly class ChangeRequestTarget
{
    private function __construct(
        public Family $family,
        public ?FamilyMembership $membership,
        public ?Person $person,
    ) {}

    public static function family(Family $family): self
    {
        return new self($family, null, null);
    }

    /** A household member: the membership must belong to this Family. */
    public static function member(Family $family, FamilyMembership $membership): self
    {
        if ((int) $membership->family_id !== (int) $family->getKey()) {
            throw new LogicException('A change request target must be a membership of its own Family.');
        }
        $person = Person::withTrashed()->findOrFail($membership->person_id);

        return new self($family, $membership, $person);
    }

    /** The target of a stored request, re-read from the database. */
    public static function of(ChangeRequest $request, Family $family): self
    {
        if ($request->target_membership_id === null) {
            return self::family($family);
        }

        return self::member($family, FamilyMembership::query()->findOrFail($request->target_membership_id));
    }

    public function isFamily(): bool
    {
        return $this->membership === null;
    }
}
