<?php

namespace App\Support\FamilyAuth;

use App\Enums\AuthIdentityStatus;
use App\Enums\FamilyAccessDenial;
use App\Enums\FamilyStatus;
use App\Enums\LifeStatus;
use App\Enums\UserPersonLinkStatus;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\AccountSide;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The one authority on Family Portal access (docs/11 §30a, docs/03 §89b).
 *
 * IDENTITY VALIDITY — who the user is:
 *   active User · family-side account · ACTIVE User-Person Link · Person not
 *   deleted, active and ALIVE (UNKNOWN is not eligible) · ACTIVE Family Auth
 *   Identity that is the fingerprint of the Person's CURRENT National ID
 *
 * FAMILY CONTEXT — which Family, if any, the user acts for:
 *   identity validity · active membership · household head · Family ACTIVE
 *   and not deleted
 *
 * The Family is always derived here, from the membership of the linked
 * Person. Nothing in this class accepts a Family (or its id) as input, so a
 * client can never choose one.
 *
 * Pure reads: no lock, no cache, no audit event. It is evaluated afresh on
 * every call, which is what makes a death, a head change, a suspended link
 * or a deactivated account take effect on the next request. Denial reasons
 * are internal and are never sent to a client.
 */
final class FamilyAccessResolver
{
    public function __construct(private readonly FamilyAuthIdentities $identities) {}

    public function identity(User $user): FamilyAccessResult
    {
        if (! $user->is_active) {
            return FamilyAccessResult::denied(FamilyAccessDenial::USER_INACTIVE);
        }
        if (! AccountSide::isFamily($user)) {
            return FamilyAccessResult::denied(FamilyAccessDenial::NOT_FAMILY_SIDE);
        }

        $link = UserPersonLink::query()->where('user_id', $user->getKey())->current()->first();
        if ($link === null) {
            return FamilyAccessResult::denied(FamilyAccessDenial::NO_LINK);
        }
        if ($link->status !== UserPersonLinkStatus::ACTIVE) {
            return FamilyAccessResult::denied(FamilyAccessDenial::LINK_SUSPENDED);
        }

        // Including soft-deleted rows: "deleted" is a reason, not "missing".
        $person = Person::withTrashed()->find($link->person_id);
        if ($person === null || $person->trashed()) {
            return FamilyAccessResult::denied(FamilyAccessDenial::PERSON_DELETED);
        }
        if ($denial = $this->personDenial($person)) {
            return FamilyAccessResult::denied($denial);
        }

        $authIdentity = $this->identities->current($user);
        if ($authIdentity === null) {
            return FamilyAccessResult::denied(FamilyAccessDenial::NO_AUTH_IDENTITY);
        }
        if ($authIdentity->status !== AuthIdentityStatus::ACTIVE) {
            return FamilyAccessResult::denied(FamilyAccessDenial::AUTH_IDENTITY_SUSPENDED);
        }
        if (FamilyNationalId::normalize($person->national_id) === null) {
            return FamilyAccessResult::denied(FamilyAccessDenial::NATIONAL_ID_INVALID);
        }
        try {
            $consistent = $this->identities->isConsistent($authIdentity, $person);
        } catch (LogicException) {
            // No usable key: fail closed. Logged without any value.
            Log::error('Family access denied: the Family Auth fingerprint key is unavailable.');

            return FamilyAccessResult::denied(FamilyAccessDenial::FINGERPRINT_UNAVAILABLE);
        }
        if (! $consistent) {
            return FamilyAccessResult::denied(FamilyAccessDenial::IDENTITY_MISMATCH);
        }

        return FamilyAccessResult::identity($user, $link, $person, $authIdentity);
    }

    public function familyContext(User $user): FamilyAccessResult
    {
        $result = $this->identity($user);
        if (! $result->allowed()) {
            return $result;
        }

        [$denial, $membership, $family] = $this->household($result->person);

        return $denial !== null ? FamilyAccessResult::denied($denial) : $result->withFamily($membership, $family);
    }

    /**
     * The Person-and-Family part of eligibility, with no account involved:
     * NULL when $person is an eligible household head. Activation needs it
     * before any User exists.
     */
    public function headEligibility(Person $person): ?FamilyAccessDenial
    {
        if ($person->trashed()) {
            return FamilyAccessDenial::PERSON_DELETED;
        }

        return $this->personDenial($person) ?? $this->household($person)[0];
    }

    private function personDenial(Person $person): ?FamilyAccessDenial
    {
        if (! $person->is_active) {
            return FamilyAccessDenial::PERSON_INACTIVE;
        }

        // ALIVE only: UNKNOWN is not eligible.
        return $person->life_status === LifeStatus::ALIVE ? null : FamilyAccessDenial::PERSON_NOT_ALIVE;
    }

    /** @return array{0: ?FamilyAccessDenial, 1: ?FamilyMembership, 2: ?Family} */
    private function household(Person $person): array
    {
        $membership = FamilyMembership::query()->where('person_id', $person->getKey())->where('is_active', true)->first();
        if ($membership === null) {
            return [FamilyAccessDenial::NO_ACTIVE_MEMBERSHIP, null, null];
        }
        if (! $membership->is_household_head) {
            return [FamilyAccessDenial::NOT_HOUSEHOLD_HEAD, null, null];
        }

        $family = Family::withTrashed()->find($membership->family_id);
        if ($family === null || $family->trashed()) {
            return [FamilyAccessDenial::FAMILY_DELETED, null, null];
        }
        if ($family->status !== FamilyStatus::ACTIVE) {
            return [FamilyAccessDenial::FAMILY_NOT_ACTIVE, null, null];
        }

        return [null, $membership, $family];
    }
}
