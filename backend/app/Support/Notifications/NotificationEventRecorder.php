<?php

namespace App\Support\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\WorkflowActorSide;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * The ONE writer of notification_recipient_events (FB-NOTIFY-01B.2b.3; ADR
 * FB-NOTIFY-01 ADR-02, ADR-06, ADR-10). Append-only: it only INSERTs; the
 * model refuses update / delete and, on PostgreSQL, a trigger does too.
 *
 * It runs ONLY inside the caller's transaction (the Domain Action's), so an
 * event commits with the change it records or not at all; it never opens a
 * transaction of its own.
 *
 * It enforces in the application what the PostgreSQL CHECKs enforce in the
 * database (so the SQLite suite holds the same rules), plus the application
 * rules of docs/02 §59:
 * - scope: message events reference no recipient; recipient events their
 *   recipient; follow-up events their follow-up. The message and recipient
 *   are DERIVED from the follow-up / recipient, so they cannot disagree;
 * - actor: the side is allowed for the type (NotificationEventType::
 *   actorSides); SYSTEM has no user, every other side a persisted user of
 *   the matching account side (FAMILY / COORDINATOR family-side, STAFF
 *   Staff-side); a view or acknowledgement only by the household head whose
 *   CURRENT family context (FamilyAccessResolver) is the recipient family;
 * - reason codes: CODE format; required for PUBLISH_FAILED, CONTACT_RECORDED,
 *   a manual FOLLOWUP_CLOSED and NOTE_CORRECTION;
 * - internal note: CONTACT_RECORDED / FOLLOWUP_CLOSED by Staff or a
 *   coordinator only, 1..1000 characters;
 * - NOTE_CORRECTION: Staff, an EARLIER event of the SAME follow-up that
 *   carries a note and is not itself a correction;
 * - metadata: a JSON object of codes and counts only — snake_case keys;
 *   values a CODE, a boolean or a count 0..9 999 999 (so a National ID or a
 *   mobile number can never be stored as a "count"); no nesting.
 *
 * Authorization (who may cancel, contact, close) is the calling action's.
 */
final class NotificationEventRecorder
{
    public const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,49}$/';

    public const MAX_NOTE = 1000;

    public const MAX_METADATA_KEYS = 20;

    public const MAX_COUNT = 9_999_999;

    private const REASON_REQUIRED = [
        NotificationEventType::PUBLISH_FAILED,
        NotificationEventType::CONTACT_RECORDED,
        NotificationEventType::NOTE_CORRECTION,
    ];

    public function __construct(private readonly FamilyAccessResolver $access) {}

    /** A message-level event (publication lifecycle). */
    public function message(
        NotificationMessage $message,
        NotificationEventType $type,
        WorkflowActorSide $side,
        ?User $actor = null,
        ?string $reasonCode = null,
        array $metadata = [],
    ): NotificationRecipientEvent {
        $this->assertInTransaction();
        $this->assertScope($type, 'MESSAGE');
        $this->assertPersisted($message);

        return $this->write($type, $side, $actor, $reasonCode, null, $metadata, ['message_id' => $message->getKey()]);
    }

    /** A recipient-level event: the family's own view or acknowledgement. */
    public function recipient(
        NotificationRecipient $recipient,
        NotificationEventType $type,
        User $actor,
        array $metadata = [],
    ): NotificationRecipientEvent {
        $this->assertInTransaction();
        $this->assertScope($type, 'RECIPIENT');
        $this->assertPersisted($recipient);
        // No acting for the family (ADR-05): the actor's CURRENT family
        // context must be this recipient's family — a coordinator's or another
        // family's account is family-side too, and is refused here.
        $context = $this->access->familyContext($actor);
        if (! $context->hasFamilyContext() || (int) $context->family->getKey() !== (int) $recipient->family_id) {
            throw new InvalidArgumentException('Only the recipient family\'s own household head records viewing and acknowledgement.');
        }

        return $this->write($type, WorkflowActorSide::FAMILY, $actor, null, null, $metadata, [
            'message_id' => $recipient->message_id,
            'recipient_id' => $recipient->getKey(),
        ]);
    }

    /** A follow-up-level event other than a note correction. */
    public function followup(
        NotificationFollowup $followup,
        NotificationEventType $type,
        WorkflowActorSide $side,
        ?User $actor = null,
        ?string $reasonCode = null,
        ?string $internalNote = null,
        array $metadata = [],
        ?string $clientReference = null,
    ): NotificationRecipientEvent {
        $this->assertInTransaction();
        $this->assertScope($type, 'FOLLOWUP');
        if ($type === NotificationEventType::NOTE_CORRECTION) {
            throw new InvalidArgumentException('A note correction is recorded with correction().');
        }
        // Contact idempotency (F3.2; chk_nre_client_reference): a uuid, on a
        // person's CONTACT_RECORDED only.
        if ($clientReference !== null && ($type !== NotificationEventType::CONTACT_RECORDED || $actor === null || ! Str::isUuid($clientReference))) {
            throw new InvalidArgumentException('A client reference is a uuid on a contact recorded by a person only.');
        }
        $references = $this->followupColumns($followup);
        if ($clientReference !== null) {
            $references['client_reference'] = strtolower($clientReference);
        }

        return $this->write($type, $side, $actor, $reasonCode, $internalNote, $metadata, $references);
    }

    /**
     * NOTE_CORRECTION (ADR-06): Staff suppresses an earlier note in
     * application views. The corrected row is never changed.
     */
    public function correction(
        NotificationRecipientEvent $corrected,
        User $actor,
        string $reasonCode,
        array $metadata = [],
    ): NotificationRecipientEvent {
        $this->assertInTransaction();
        $this->assertPersisted($corrected);
        $original = NotificationRecipientEvent::query()->whereKey($corrected->getKey())->first();
        if ($original === null || $original->followup_id === null || $original->internal_note === null
            || $original->event_type === NotificationEventType::NOTE_CORRECTION) {
            throw new InvalidArgumentException('Only an earlier follow-up event carrying a note can be corrected.');
        }
        $followup = NotificationFollowup::query()->findOrFail($original->followup_id);

        return $this->write(NotificationEventType::NOTE_CORRECTION, WorkflowActorSide::STAFF, $actor, $reasonCode, null, $metadata, [
            ...$this->followupColumns($followup),
            'corrects_event_id' => $original->getKey(),
        ]);
    }

    /** @param  array<string, int|string|null>  $references */
    private function write(
        NotificationEventType $type,
        WorkflowActorSide $side,
        ?User $actor,
        ?string $reasonCode,
        ?string $internalNote,
        array $metadata,
        array $references,
    ): NotificationRecipientEvent {
        $this->assertActor($type, $side, $actor);
        $this->assertReasonCode($type, $side, $reasonCode);
        $this->assertInternalNote($type, $side, $internalNote);
        $this->assertMetadata($metadata);

        return NotificationRecipientEvent::create([
            ...$references,
            'event_type' => $type,
            'actor_side' => $side,
            'actor_user_id' => $actor?->getKey(),
            'reason_code' => $reasonCode,
            'internal_note' => $internalNote,
            'metadata' => $metadata,
        ]);
    }

    /** @return array{message_id: int, recipient_id: int, followup_id: int} derived from the stored rows */
    private function followupColumns(NotificationFollowup $followup): array
    {
        $this->assertPersisted($followup);
        $recipient = NotificationRecipient::query()->findOrFail($followup->recipient_id);

        return [
            'message_id' => (int) $recipient->message_id,
            'recipient_id' => (int) $recipient->getKey(),
            'followup_id' => (int) $followup->getKey(),
        ];
    }

    /** The Domain Action's transaction is the event's: never one of its own. */
    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Notification events are recorded only inside the calling Domain Action transaction.');
        }
    }

    private function assertScope(NotificationEventType $type, string $scope): void
    {
        if ($type->scope() !== $scope) {
            throw new InvalidArgumentException("{$type->value} is not a ".strtolower($scope).'-level event.');
        }
    }

    private function assertPersisted(object $model): void
    {
        if (! $model->exists) {
            throw new InvalidArgumentException('A notification event references stored rows only.');
        }
    }

    private function assertActor(NotificationEventType $type, WorkflowActorSide $side, ?User $actor): void
    {
        if (! in_array($side, $type->actorSides(), true)) {
            throw new InvalidArgumentException("{$side->value} cannot record {$type->value}.");
        }
        if ($side === WorkflowActorSide::SYSTEM) {
            if ($actor !== null) {
                throw new InvalidArgumentException('A SYSTEM event has no acting user.');
            }

            return;
        }
        $matches = match ($side) {
            WorkflowActorSide::STAFF => $actor !== null && AccountSide::isStaff($actor),
            default => $actor !== null && AccountSide::isFamily($actor),
        };
        if (! $matches || ! $actor->exists) {
            throw new InvalidArgumentException("A {$side->value} event needs a stored {$side->value}-side user.");
        }
    }

    private function assertReasonCode(NotificationEventType $type, WorkflowActorSide $side, ?string $reasonCode): void
    {
        if ($reasonCode !== null && preg_match(self::CODE_PATTERN, $reasonCode) !== 1) {
            throw new InvalidArgumentException('A reason code is a CODE, never free text.');
        }
        $manualClosure = $type === NotificationEventType::FOLLOWUP_CLOSED && $side !== WorkflowActorSide::SYSTEM;
        if ($reasonCode === null && ($manualClosure || in_array($type, self::REASON_REQUIRED, true))) {
            throw new InvalidArgumentException("{$type->value} requires a reason code.");
        }
        if ($reasonCode !== null && $type->isFamilyAction()) {
            throw new InvalidArgumentException('Family actions carry no reason code.');
        }
    }

    private function assertInternalNote(NotificationEventType $type, WorkflowActorSide $side, ?string $note): void
    {
        if ($note === null) {
            return;
        }
        if (! $type->allowsInternalNote() || ! in_array($side, [WorkflowActorSide::STAFF, WorkflowActorSide::COORDINATOR], true)) {
            throw new InvalidArgumentException('Only a Staff or coordinator contact or closure carries an internal note.');
        }
        if (trim($note) === '' || mb_strlen($note) > self::MAX_NOTE) {
            throw new InvalidArgumentException('An internal note has 1 to '.self::MAX_NOTE.' characters.');
        }
    }

    private function assertMetadata(array $metadata): void
    {
        if ($metadata !== [] && array_is_list($metadata)) {
            throw new InvalidArgumentException('Event metadata is a JSON object.');
        }
        if (count($metadata) > self::MAX_METADATA_KEYS) {
            throw new InvalidArgumentException('Event metadata has too many keys.');
        }
        foreach ($metadata as $key => $value) {
            $valid = is_string($key) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) === 1
                && (is_bool($value)
                    || (is_int($value) && $value >= 0 && $value <= self::MAX_COUNT)
                    || (is_string($value) && preg_match(self::CODE_PATTERN, $value) === 1));
            if (! $valid) {
                throw new InvalidArgumentException('Event metadata holds codes and counts only.');
            }
        }
    }
}
