<?php

namespace App\Support\ChangeRequests;

use App\Enums\SubmissionChannel;
use App\Enums\WorkflowActorSide;
use App\Models\Family;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResult;
use LogicException;

/**
 * WHO submits a Change Request, through WHICH channel, about WHICH Family
 * (CO-02.3b; docs/adr/CO-02-COORDINATOR-REQUESTS.md §12). Built only by
 * SubmitChangeRequestAction from server-resolved values — the family.context
 * result, or a granted Coordinator context and a Family already checked
 * against its scope — never from client input.
 *
 * - FAMILY: the household head acting for their own Family
 *   (`familyAccess` is the trusted family.context result, unchanged).
 * - COORDINATOR: a Coordinator acting for a Family in their scope. The
 *   requester Person is the Coordinator's own Person (User ≠ Person); there
 *   is NO family.context for the target Family, so member references of
 *   that Family (HouseholdMemberReference) cannot be resolved and a handler
 *   must not try.
 */
final readonly class ChangeRequestSubmissionContext
{
    private function __construct(
        public SubmissionChannel $channel,
        public User $user,
        public Person $person,
        public Family $family,
        public ?FamilyAccessResult $familyAccess,
    ) {}

    /** The household's own submission (the PWA-5e path, unchanged). */
    public static function family(FamilyAccessResult $context, User $user, Person $person): self
    {
        if ($context->family === null) {
            throw new LogicException('A family submission needs its resolved Family.');
        }

        return new self(SubmissionChannel::FAMILY, $user, $person, $context->family, $context);
    }

    /** A Coordinator's submission about a Family already checked against the scope. */
    public static function coordinator(User $user, Person $person, Family $family): self
    {
        return new self(SubmissionChannel::COORDINATOR, $user, $person, $family, null);
    }

    /** The workflow actor side of this channel's events. */
    public function actorSide(): WorkflowActorSide
    {
        return match ($this->channel) {
            SubmissionChannel::FAMILY => WorkflowActorSide::FAMILY,
            SubmissionChannel::COORDINATOR => WorkflowActorSide::COORDINATOR,
        };
    }
}
