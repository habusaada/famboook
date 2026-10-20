<?php

namespace App\Support\Notifications;

use App\Enums\AuthIdentityStatus;
use App\Enums\NotificationFollowupStatus;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Exceptions\NotificationFollowupException;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\FamilyAuthIdentity;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
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
 * LOCK ORDER — coordinator (F3.1b clan-row protocol, F3.1d hardening). All
 * FOR SHARE unless stated; every multi-row step by id:
 *   placement (before the recipient, so a coordinator action that waits for
 *   an administrator never holds up the family's view / acknowledgement or
 *   the scheduler):
 *     a. the family's CLAN row — assignment creation locks it FOR NO KEY
 *        UPDATE before its user (every covering assignment has the family's
 *        clan_id): a new assignment is seen or waits;
 *     b. the target FAMILY row — a placement change (UpdateFamilyAction's
 *        UPDATE) waits; its clan is re-checked (moved: OWNERSHIP_CHANGED);
 *     c. the family's BRANCH row, then its BRANCH GROUP row — activation and
 *        regrouping (ManageClanStructureAction's single-row UPDATEs) wait;
 *   follow-up:
 *     d. the notification_recipients row FOR UPDATE, e. the follow-up FOR UPDATE;
 *   eligibility of the actor and every CURRENT candidate, in the writers'
 *   own order (person → link → identity → user):
 *     f. their PERSON rows — death, life-status confirmation, National ID
 *        correction, password reset (person FOR UPDATE first) wait;
 *     g. their current USER-PERSON LINK rows — suspension / end / resume
 *        (link FOR UPDATE) wait;
 *     h. their current FAMILY AUTH IDENTITY rows — supersession / suspension
 *        (identity FOR UPDATE, after person and link) wait;
 *     i. their USER rows — role grant / revocation, scope assignment, session
 *        revocation (UPDATE users) wait;
 *     j. their non-revoked ASSIGNMENTS — scope revocation waits.
 * Then the ownership is resolved under the locks. A candidate, a link or a
 * person that is not locked (it appeared meanwhile): OWNERSHIP_CHANGED,
 * nothing written.
 * LOCK ORDER — Staff: d, e, then the actor's user row FOR SHARE
 * (ManageStaffUsersAction locks it FOR UPDATE first for a role or activation
 * change).
 * Consistency: family → person (change requests, memberships), person → link
 * → identity → user (lifecycle, sessions), person → user (password reset),
 * clan → user (assignment), user → assignments (role revocation) are all
 * subsequences of this order; no writer locks a person, link, identity or user
 * and then a family, branch, group or clan FOR UPDATE / NO KEY UPDATE (the
 * activity-log foreign keys only take KEY SHARE on families, compatible). On
 * PostgreSQL every wait is bounded by lock_timeout 5 s: SQLSTATE 55P03 (a
 * QueryException), which the API stage (F3.2) must answer as retryable.
 *
 * NOT SERIALIZED (ADR §2e): role–permission mappings (seeded only during the
 * deployment maintenance window) and writers that do not exist in the
 * application today — household-head succession, family status changes,
 * person deactivation or deletion, a candidate's own-family membership.
 * Any such future writer must lock the person (and the family) row first.
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
        // a–c. coordinator only: the placement rows, before the recipient.
        $coordinator = AccountSide::isFamily($actor);
        $placement = $coordinator ? $this->lockPlacement((int) $found->recipient_id) : null;

        // d. recipient, e. follow-up (its recipient never changes).
        $recipient = NotificationRecipient::query()->whereKey($found->recipient_id)->lockForUpdate()->firstOrFail();
        $followup = NotificationFollowup::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();
        $family = $placement ?? Family::withTrashed()->findOrFail($recipient->family_id);

        // f–j. the authorization's own rows, then the decision on fresh data.
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

    /**
     * a–c: clan, family, branch, group FOR SHARE. The family's clan and
     * branch are read before their locks and re-checked after the family
     * lock; a move in between refuses (OWNERSHIP_CHANGED, nothing written).
     */
    private function lockPlacement(int $recipientId): Family
    {
        $familyId = NotificationRecipient::query()->whereKey($recipientId)->value('family_id');
        $clanId = Family::withTrashed()->whereKey($familyId)->value('clan_id');
        if ($clanId !== null) {
            Clan::query()->whereKey($clanId)->sharedLock()->first();
        }
        $family = Family::withTrashed()->whereKey($familyId)->sharedLock()->firstOrFail();
        if ((int) $family->clan_id !== (int) $clanId) {
            throw new NotificationFollowupException(NotificationFollowupException::OWNERSHIP_CHANGED);
        }
        if ($family->branch_id !== null) {
            // The family's branch_id is stable now; so is the branch's group under its lock.
            $groupId = Branch::query()->whereKey($family->branch_id)->sharedLock()->value('branch_group_id');
            if ($groupId !== null) {
                BranchGroup::query()->whereKey($groupId)->sharedLock()->first();
            }
        }

        return $family;
    }

    /**
     * f–j: the eligibility rows of the actor and every current candidate —
     * persons, links, identities, users, assignments — then the candidates
     * re-ranked. Only a NEW account can ever get a new link (activation never
     * reuses a User), so a candidate cannot regain a link behind these locks.
     */
    private function lockCoordinatorRows(User $actor, Family $family): User
    {
        $actorId = (int) $actor->getKey();
        $users = array_values(array_unique([$actorId, ...$this->ownership->candidateUserIds($family)]));
        sort($users);

        $personIds = UserPersonLink::query()->whereIn('user_id', $users)->current()->pluck('person_id')->map(fn ($id) => (int) $id)->all();
        sort($personIds);
        Person::withTrashed()->whereKey($personIds)->orderBy('id')->sharedLock()->get(['id']);
        $links = UserPersonLink::query()->whereIn('user_id', $users)->current()->orderBy('id')->sharedLock()->get(['id', 'person_id']);
        FamilyAuthIdentity::query()->whereIn('user_id', $users)
            ->whereIn('status', [AuthIdentityStatus::ACTIVE->value, AuthIdentityStatus::SUSPENDED->value])
            ->orderBy('id')->sharedLock()->get(['id']);
        $user = $this->lockUsers($users, $actorId);
        CoordinatorScopeAssignment::query()
            ->whereIn('user_id', $users)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->sharedLock()
            ->get(['id']);

        // Anything that appeared after the first reads and is not locked: refuse, write nothing.
        $unlockedLink = $links->contains(fn (UserPersonLink $link) => ! in_array((int) $link->person_id, $personIds, true));
        if ($unlockedLink || array_diff($this->ownership->candidateUserIds($family), $users) !== []) {
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
