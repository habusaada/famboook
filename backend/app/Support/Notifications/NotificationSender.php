<?php

namespace App\Support\Notifications;

use App\Enums\NotificationSenderSide;
use App\Enums\WorkflowActorSide;
use App\Models\User;
use App\Support\FamilyAuth\CoordinatorAccessResult;

/**
 * The account acting on an announcement, resolved on the server by
 * NotificationActors (FB-NOTIFY-01B.2b.4): its side comes from the account
 * and, for a coordinator, from the CURRENT Coordinator context — never from
 * the client.
 */
final readonly class NotificationSender
{
    public function __construct(
        public User $user,
        public NotificationSenderSide $side,
        public ?CoordinatorAccessResult $coordinator = null,
    ) {}

    public function actorSide(): WorkflowActorSide
    {
        return $this->side === NotificationSenderSide::COORDINATOR ? WorkflowActorSide::COORDINATOR : WorkflowActorSide::STAFF;
    }

    /**
     * The coordinator scope snapshot (ADR-10): the effective assignments at
     * this moment, as internal ids — server-side only (the model hides
     * sender_scope). NULL for Staff, as chk_nm_sender_scope requires.
     *
     * @return array{assignments: list<array{type: string, clan_id: int, branch_group_id: int|null, branch_id: int|null}>}|null
     */
    public function scopeSnapshot(): ?array
    {
        if ($this->coordinator === null) {
            return null;
        }

        return ['assignments' => $this->coordinator->assignments->map(fn ($assignment) => [
            'type' => $assignment->scope_type->value,
            'clan_id' => (int) $assignment->clan_id,
            'branch_group_id' => $assignment->branch_group_id === null ? null : (int) $assignment->branch_group_id,
            'branch_id' => $assignment->branch_id === null ? null : (int) $assignment->branch_id,
        ])->values()->all()];
    }
}
