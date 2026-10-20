<?php

namespace App\Support\Notifications;

use App\Enums\NotificationFollowupStatus;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Exceptions\NotificationFollowupException;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\User;
use App\Support\AccountSide;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The shared lock-then-authorize step of the follow-up management actions —
 * recording a contact and closing manually (FB-NOTIFY-01E.3-F3.1; ADR
 * FB-NOTIFY-01 ADR-04, ADR-06, §2c, §2d). Inside the caller's transaction.
 *
 * WHO (re-evaluated under the locks, never taken from the caller):
 *   Staff        an active Staff-side account with SUPER_ADMIN or
 *                ADMINISTRATOR AND notification-followup.manage — any
 *                follow-up, CENTRAL ones included;
 *   Coordinator  an active family-side account with COORDINATOR AND
 *                coordinator-notification-followup.manage that is the
 *                family's CURRENT primary coordinator
 *                (FollowupOwnership::coordinatorMayManage: current family
 *                context, coordinator-space.access, effective scope covering
 *                the family, never their own household). In scope but not
 *                primary, out of scope, a historical assignment: refused.
 *
 * LOCK ORDER — coordinator (FB-NOTIFY-01E.3-F3.1b clan-row protocol):
 *   0. the family's CLAN row FOR SHARE — BEFORE the recipient. Assignment
 *      creation (AssignCoordinatorScopeAction) locks the target's clan FOR
 *      NO KEY UPDATE before its user, and every assignment covering a family
 *      has that family's clan_id: a new assignment either committed before
 *      this lock (and is seen by the ranking) or waits for this transaction.
 *      Taken before the recipient, so a coordinator action waiting for an
 *      assignment never holds up the family's view / acknowledgement or the
 *      scheduler. The family's clan is re-checked after the recipient lock
 *      (moved meanwhile: OWNERSHIP_CHANGED);
 *   1. the notification_recipients row FOR UPDATE;
 *   2. the follow-up row FOR UPDATE;
 *   3. users FOR SHARE, by id: the actor and every CURRENT ownership
 *      candidate (FollowupOwnership::candidateUserIds) — a role grant /
 *      revocation of any of them (user FOR UPDATE first) waits;
 *   4. those users' non-revoked assignments FOR SHARE, by id — a scope
 *      revocation (assignment FOR UPDATE) waits.
 * Then the ownership is resolved under the locks (a candidate that is not
 * locked: OWNERSHIP_CHANGED, defensive — the clan lock makes it unreachable
 * through assignment creation).
 * LOCK ORDER — Staff (unchanged): 1, 2, then the actor's user row FOR SHARE.
 * Clan → user (assignment) and clan → … → users (here) are consistent; no
 * path takes a user lock and then a clan lock. On PostgreSQL every wait is
 * bounded by lock_timeout 5 s: SQLSTATE 55P03 (a QueryException), which the
 * API stage (F3.2) must answer as retryable.
 *
 * NOT SERIALIZED (ADR §2e): changes that alter the owner WITHOUT an
 * assignment mutation — a family placement change committing after the clan
 * re-check, branch / group structure changes (activation, regrouping), a
 * coordinator's own household eligibility, role–permission mappings — and
 * Staff role changes made outside these actions (Spatie, ADR-04).
 *
 * WHAT: the follow-up must be OPEN, and its message not expired (at or after
 * expires_at, or ARCHIVED, expiry wins: the scheduler closes it EXPIRED).
 */
final class FollowupManagement
{
    public const MAX_NOTE = NotificationEventRecorder::MAX_NOTE;

    public function __construct(private readonly FollowupOwnership $ownership) {}

    /**
     * Cheap refusal BEFORE any lock: an account holding neither management
     * permission can never manage a follow-up.
     */
    public static function assertMayManageAny(User $actor): void
    {
        if (! $actor->can('notification-followup.manage') && ! $actor->can('coordinator-notification-followup.manage')) {
            throw new NotificationFollowupException(NotificationFollowupException::ACTOR_NOT_ALLOWED);
        }
    }

    /**
     * NULL, or a trimmed internal note of 1..1000 characters
     * (chk_nre_internal_note). A note of only Unicode blanks (no-break,
     * zero-width and other separator or format characters) or invalid UTF-8
     * is refused like an empty one.
     */
    public static function note(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }
        $note = trim($note);
        $blank = preg_match('/^[\s\p{Z}\p{Cc}\p{Cf}]*$/u', $note);
        if ($blank !== 0 || mb_strlen($note) > self::MAX_NOTE) {
            throw new NotificationFollowupException(NotificationFollowupException::NOTE_INVALID);
        }

        return $note;
    }

    /**
     * Locks in the documented order, then authorizes and checks the state.
     *
     * @return array{followup: NotificationFollowup, recipient: NotificationRecipient, message: NotificationMessage,
     *     actor: User, side: WorkflowActorSide, now: CarbonImmutable}
     */
    public function lockAndAuthorize(User $actor, string $followupUuid): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Follow-up management runs inside the Domain Action transaction.');
        }
        NotificationLifecycle::limitTransaction();

        $found = Str::isUuid($followupUuid) ? NotificationFollowup::query()->where('uuid', $followupUuid)->first() : null;
        if ($found === null) {
            throw new NotificationFollowupException(NotificationFollowupException::NOT_FOUND);
        }
        // 0. coordinator only: the family's clan row FOR SHARE, before anything else.
        $coordinator = AccountSide::isFamily($actor);
        $clanId = null;
        if ($coordinator) {
            $familyId = NotificationRecipient::query()->whereKey($found->recipient_id)->value('family_id');
            $clanId = Family::withTrashed()->whereKey($familyId)->value('clan_id');
            if ($clanId !== null) {
                Clan::query()->whereKey($clanId)->sharedLock()->first();
            }
        }

        // 1. recipient, 2. follow-up (its recipient never changes).
        $recipient = NotificationRecipient::query()->whereKey($found->recipient_id)->lockForUpdate()->firstOrFail();
        $followup = NotificationFollowup::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();
        $family = Family::withTrashed()->findOrFail($recipient->family_id);
        if ($coordinator && (int) $family->clan_id !== (int) $clanId) {
            throw new NotificationFollowupException(NotificationFollowupException::OWNERSHIP_CHANGED);
        }

        // 3. / 4. the authorization's own rows, then the decision on fresh data.
        $user = $coordinator ? $this->lockCoordinatorRows($actor, $family) : $this->lockUsers([(int) $actor->getKey()], (int) $actor->getKey());
        $side = $this->authorize($user, $family, $coordinator);

        $message = NotificationMessage::query()->findOrFail($recipient->message_id);
        $now = CarbonImmutable::now();
        if ($followup->status !== NotificationFollowupStatus::OPEN) {
            throw new NotificationFollowupException(NotificationFollowupException::NOT_OPEN);
        }
        if ($message->status === NotificationStatus::ARCHIVED
            || ($message->expires_at !== null && $now->getTimestamp() >= $message->expires_at->getTimestamp())) {
            throw new NotificationFollowupException(NotificationFollowupException::EXPIRED);
        }

        return ['followup' => $followup, 'recipient' => $recipient, 'message' => $message, 'actor' => $user, 'side' => $side, 'now' => $now];
    }

    /** Coordinator: the actor and the family's candidates FOR SHARE, then their assignments; candidates re-ranked. */
    private function lockCoordinatorRows(User $actor, Family $family): User
    {
        $actorId = (int) $actor->getKey();
        $users = array_values(array_unique([$actorId, ...$this->ownership->candidateUserIds($family)]));
        $user = $this->lockUsers($users, $actorId);
        CoordinatorScopeAssignment::query()
            ->whereIn('user_id', $users)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->sharedLock()
            ->get(['id']);

        // A candidate that is not locked appeared after the first ranking: refuse, write nothing.
        if (array_diff($this->ownership->candidateUserIds($family), $users) !== []) {
            throw new NotificationFollowupException(NotificationFollowupException::OWNERSHIP_CHANGED);
        }

        return $user;
    }

    /** @param  list<int>  $ids */
    private function lockUsers(array $ids, int $actorId): User
    {
        sort($ids);
        $locked = User::query()->whereKey($ids)->orderBy('id')->sharedLock()->get()->keyBy('id');

        return $locked->get($actorId) ?? throw new NotificationFollowupException(NotificationFollowupException::ACTOR_NOT_ALLOWED);
    }

    private function authorize(User $user, Family $family, bool $coordinator): WorkflowActorSide
    {
        if (! $user->is_active) {
            throw new NotificationFollowupException(NotificationFollowupException::ACTOR_NOT_ALLOWED);
        }
        if (! $coordinator) {
            $staff = AccountSide::isStaff($user)
                && $user->hasAnyRole(['SUPER_ADMIN', 'ADMINISTRATOR'])
                && $user->can('notification-followup.manage');
            if (! $staff) {
                throw new NotificationFollowupException(NotificationFollowupException::ACTOR_NOT_ALLOWED);
            }

            return WorkflowActorSide::STAFF;
        }
        $owner = $user->hasRole(AccountSide::COORDINATOR)
            && $this->ownership->coordinatorMayManage($user, $family);
        if (! $owner) {
            throw new NotificationFollowupException(NotificationFollowupException::ACTOR_NOT_ALLOWED);
        }

        return WorkflowActorSide::COORDINATOR;
    }
}
