<?php

namespace App\Actions;

use App\Enums\AuthIdentitySupersedeReason;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\UserPersonLinkEndReason;
use App\Enums\UserPersonLinkStatus;
use App\Exceptions\FamilyIdentityException;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyAuthIdentities;
use App\Support\FamilyAuth\FamilySessions;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Ends a User-Person Link (docs/05 §53b): ACTIVE or SUSPENDED → ENDED.
 * TERMINAL — an ended link is never resumed; a later activation creates a
 * new link.
 *
 *   User-Person Link     ENDED, with its reason
 *   Family Auth Identity SUPERSEDED / LINK_ENDED (its login key is free)
 *   Sessions             all revoked
 *   User account         NOT changed: is_active stays as it is. Account
 *                        state, link state and authentication-identity
 *                        state are separate; deactivating an account is its
 *                        own explicit operation.
 *
 * The account is simply unusable for the Family Portal, because it has no
 * ACTIVE link and no ACTIVE identity.
 */
class EndUserPersonLinkAction
{
    public function __construct(private readonly FamilyAuthIdentities $identities) {}

    /** An administrative end: a Staff-side actor holding user-person-link.manage. */
    public function handle(User $actor, UserPersonLink $link, UserPersonLinkEndReason $reason): UserPersonLink
    {
        SuspendUserPersonLinkAction::authorize($actor);

        return DB::transaction(fn () => $this->end($link, $reason, $actor->getKey()));
    }

    /**
     * An end that follows from another Domain Action (a recorded death),
     * inside THAT action's transaction. No link permission is involved: the
     * calling action carries its own authorization.
     */
    public function bySystem(UserPersonLink $link, UserPersonLinkEndReason $reason, ?int $actorUserId = null): UserPersonLink
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A system link end runs inside its Domain Action transaction.');
        }

        return $this->end($link, $reason, $actorUserId);
    }

    private function end(UserPersonLink $link, UserPersonLinkEndReason $reason, ?int $actorUserId): UserPersonLink
    {
        /** @var UserPersonLink $link */
        $link = UserPersonLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();
        $from = $link->status;
        if (! in_array($from, [UserPersonLinkStatus::ACTIVE, UserPersonLinkStatus::SUSPENDED], true)) {
            throw new FamilyIdentityException(FamilyIdentityException::INVALID_TRANSITION);
        }

        $link->forceFill([
            'status' => UserPersonLinkStatus::ENDED,
            'ended_at' => now(),
            'ended_by' => $actorUserId,
            'end_reason' => $reason->value,
        ])->save();

        $user = $link->user;
        if ($identity = $this->identities->current($user, lock: true)) {
            $this->identities->supersede($identity, AuthIdentitySupersedeReason::LINK_ENDED);
        }

        FamilySessions::revoke($user, $actorUserId, $reason);
        AuthSecurityLog::record(
            AuthSecurityEventType::LINK_ENDED,
            AuthSecurityEventOutcome::SUCCESS,
            $reason,
            person: $link->person()->withTrashed()->first(), user: $user, actor: $actorUserId, link: $link,
            metadata: ['status_from' => $from->value, 'status_to' => UserPersonLinkStatus::ENDED->value],
        );

        return $link;
    }
}
