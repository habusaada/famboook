<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;

/**
 * One allowed Change Request status transition (ChangeRequestTransitions).
 * `from` is NULL only for a request's creation. `reasons` lists the
 * rejection reasons the transition accepts; empty means it takes none.
 */
final readonly class ChangeRequestTransition
{
    /** @param list<ChangeRequestRejectionReason> $reasons */
    public function __construct(
        public ?ChangeRequestStatus $from,
        public ChangeRequestStatus $to,
        public WorkflowEventType $event,
        public WorkflowActorSide $actorSide,
        public string $permission,
        public array $reasons = [],
    ) {}

    public function requiresReason(): bool
    {
        return $this->reasons !== [];
    }

    public function accepts(?ChangeRequestRejectionReason $reason): bool
    {
        return $reason === null ? ! $this->requiresReason() : in_array($reason, $this->reasons, true);
    }
}
