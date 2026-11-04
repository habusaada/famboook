<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\CivilValueStatus;
use App\Enums\RegistryChildReason as Reason;
use App\Models\ChangeRequest;
use App\Support\CivilLookup\CivilRecord;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\FamilyNationalId;
use App\Support\FamilyMemberIdentity;
use App\Support\NationalIdGuard;
use LogicException;

/**
 * The read-only evaluation of one civil registry record as a suggested child of the
 * signed-in household head (FB-FAMILY-ADD-01 ADD-WP-05; ADR §5, E-3 … E-9).
 *
 * - The household and the head's National ID come from the family.context result,
 *   never from a client.
 * - It reads the registry Person state without locks, for display. The admission
 *   action must evaluate again under its locks (ADR §10).
 * - It never reads or writes identity_ref: the record is passed in.
 * - Every applicable reason is collected, so a later gate change cannot hide another.
 *   While gates G-04 and G-05 are open, no record can be ELIGIBLE.
 * - Discovery passes the page's RegistryChildFacts (ADD-WP-06B-1) so the registry is
 *   read once per page; without them each record is read on its own. The rules below
 *   are the same either way: only where the facts come from differs.
 */
final class RegistryChildEligibility
{
    public static function evaluate(FamilyAccessResult $context, CivilRecord $record, ?RegistryChildFacts $facts = null): RegistryChildAssessment
    {
        if (! $context->hasFamilyContext()) {
            throw new LogicException('A suggested child is evaluated only for an eligible household head.');
        }
        if ($facts !== null && $facts->familyId !== (int) $context->family->getKey()) {
            throw new LogicException('The facts were read for another household.');
        }
        $headId = FamilyNationalId::normalize($context->person->national_id)
            ?? throw new LogicException('An eligible household head has a nine-digit National ID.');

        $reasons = [];
        $link = CivilRegistryValues::parentLink($record, $headId);
        if ($link === null) {
            $reasons[] = Reason::NOT_A_CHILD_OF_HEAD;
        } elseif ($link === 'BOTH') {
            $reasons[] = Reason::PARENT_LINK_INDETERMINATE;
        }

        if (! CivilRegistryValues::validNationalId($record->mainid)) {
            $reasons[] = Reason::INVALID_NATIONAL_ID;
        } elseif ($record->mainid === $headId) {
            $reasons[] = Reason::IS_HEAD;
        } else {
            array_push($reasons, ...self::registryReasons($context, $record->mainid, $facts));
        }

        if (CivilRegistryValues::fullName($record) === null) {
            $reasons[] = Reason::SOURCE_INCOMPLETE;
        }

        $gender = CivilRegistryValues::gender($record->payload['gender'] ?? null);
        if (! $gender->mapped()) {
            $reasons[] = Reason::GENDER_UNSUPPORTED;
        } elseif (! CivilRegistryValues::MAPPINGS_APPROVED) {
            $reasons[] = Reason::GENDER_MAPPING_PROVISIONAL;
        }

        // RR-02: the registry's death evidence first, then only an authoritative verifier
        // (none is bound, G-05) can prove a life. An empty death date never does.
        $life = RegistryLifeStatus::of($record);
        if ($life !== LifeStatus::PROVEN_ALIVE) {
            $reasons[] = $life === LifeStatus::DECEASED ? Reason::DECEASED : Reason::LIFE_UNVERIFIED;
        }

        return new RegistryChildAssessment(
            $reasons,
            in_array($link, ['FATHER', 'MOTHER'], true) ? $link : null,
            $gender->status === CivilValueStatus::MAPPED ? $gender->value : null,
            livingProven: $life === LifeStatus::PROVEN_ALIVE,
        );
    }

    /**
     * Whether evaluate() reads the application registry for this record, i.e. which
     * National IDs a page's RegistryChildFacts must cover.
     */
    public static function readsRegistry(FamilyAccessResult $context, CivilRecord $record): bool
    {
        return CivilRegistryValues::validNationalId($record->mainid)
            && $record->mainid !== FamilyNationalId::normalize($context->person->national_id);
    }

    /** @return list<Reason> E-7 … E-9 from the application registry, read without locks. */
    private static function registryReasons(FamilyAccessResult $context, #[\SensitiveParameter] string $nationalId, ?RegistryChildFacts $facts): array
    {
        $reasons = [];
        $matches = $facts?->holders($nationalId) ?? NationalIdGuard::equivalentMatches($nationalId);
        if ($matches->count() > 1) {
            $reasons[] = Reason::AMBIGUOUS_HOLDERS;
        } elseif (($holder = $matches->first()) !== null) {
            $membership = $holder->activeMembership;
            if ($membership !== null) {
                $reasons[] = (int) $membership->family_id === (int) $context->family->getKey()
                    ? Reason::ALREADY_MEMBER
                    : Reason::ACTIVE_MEMBERSHIP_ELSEWHERE;
            } elseif (! FamilyMemberIdentity::attachable($holder)) {
                $reasons[] = Reason::PERSON_NOT_ATTACHABLE;
            }
        } elseif ($facts?->heldByDeleted($nationalId) ?? NationalIdGuard::heldByDeleted($nationalId)) {
            $reasons[] = Reason::SOFT_DELETED_HOLDER;
        }

        $open = $facts?->requestOpen($nationalId) ?? ChangeRequest::query()
            ->where('family_id', $context->family->getKey())
            ->where('type', ChangeRequestType::ADD_FAMILY_MEMBER)
            ->whereIn('status', ChangeRequestStatus::open())
            ->where('submitted_data->national_id', $nationalId)
            ->exists();
        if ($open) {
            $reasons[] = Reason::REQUEST_OPEN;
        }

        return $reasons;
    }
}
