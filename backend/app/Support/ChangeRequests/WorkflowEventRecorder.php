<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Models\ChangeRequest;
use App\Models\WorkflowEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of Change Request workflow events (docs/04 §33, PWA-5a).
 * Called by the PWA-5b Domain Actions inside their transaction, AFTER the
 * request's new status has been set, so the event commits or rolls back with
 * the transition. A refused / failed apply (APPLY_FAILED) is recorded in its
 * own small transaction after the rollback, with the request still APPROVED.
 *
 * It refuses — never repairs — an event that does not fit:
 * - the transition must be in ChangeRequestTransitions, with the event and
 *   actor side it defines, and must match the request's current status;
 * - a rejection carries a reason the transition accepts; nothing else does;
 * - public_message (family-visible) is required to return a request and to
 *   resubmit it, optional on a rejection, refused elsewhere; internal_note
 *   (Staff-only) only on Staff events. Plain text, at most 2000 characters;
 * - metadata keys are allow-listed per event, each value a controlled code.
 *   No key is allowed yet: PWA-5b adds them with their enum contracts. The
 *   request payload and any registry value are never copied into an event.
 *
 * Nothing is logged; exception messages carry codes and key names only.
 */
final class WorkflowEventRecorder
{
    public const MAX_MESSAGE_LENGTH = 2000;

    /** Events whose family-visible message is required. */
    private const PUBLIC_MESSAGE_REQUIRED = [WorkflowEventType::RETURNED, WorkflowEventType::RESUBMITTED];

    /** Events that may carry a family-visible message. */
    private const PUBLIC_MESSAGE_ALLOWED = [WorkflowEventType::RETURNED, WorkflowEventType::RESUBMITTED, WorkflowEventType::REJECTED];

    /**
     * Allowed metadata per event type: key => the enum its value must be a
     * case of. Any other key is refused. Empty until PWA-5b defines one.
     *
     * @var array<string, array<string, class-string<\BackedEnum>>>
     */
    public const EVENT_CODE_METADATA = [];

    /**
     * @param  array<string, string>  $metadata
     */
    public static function record(
        ChangeRequest $request,
        ?ChangeRequestStatus $from,
        WorkflowEventType $event,
        WorkflowActorSide $actorSide,
        ?int $actorUserId,
        ?ChangeRequestRejectionReason $reason = null,
        ?string $publicMessage = null,
        ?string $internalNote = null,
        array $metadata = [],
    ): WorkflowEvent {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Workflow events must be recorded inside a transaction.');
        }
        if (! $request->exists || $request->isDirty('status')) {
            throw new LogicException('Record the event after the change request status is saved.');
        }

        $to = $request->status;
        if ($event === WorkflowEventType::APPLY_FAILED) {
            // Not a transition: the request stays APPROVED (docs/04 §43).
            if ($from !== ChangeRequestStatus::APPROVED || $to !== ChangeRequestStatus::APPROVED || $actorSide !== WorkflowActorSide::STAFF) {
                throw new LogicException('APPLY_FAILED is recorded by Staff on an APPROVED request only.');
            }
            if ($reason !== null) {
                throw new InvalidArgumentException('APPLY_FAILED carries no rejection reason.');
            }
        } else {
            $transition = ChangeRequestTransitions::assertAllowed($from, $to, $reason);
            if ($transition->event !== $event || $transition->actorSide !== $actorSide) {
                throw new LogicException('Workflow event does not match the transition: '.$event->value.' by '.$actorSide->value);
            }
        }

        if ($actorSide !== WorkflowActorSide::SYSTEM && $actorUserId === null) {
            throw new InvalidArgumentException('A family or Staff event needs its acting user.');
        }

        $publicMessage = self::text($publicMessage, 'public_message');
        $internalNote = self::text($internalNote, 'internal_note');
        if ($publicMessage === null && in_array($event, self::PUBLIC_MESSAGE_REQUIRED, true)) {
            throw new InvalidArgumentException('This event requires a family-visible message: '.$event->value);
        }
        if ($publicMessage !== null && ! in_array($event, self::PUBLIC_MESSAGE_ALLOWED, true)) {
            throw new InvalidArgumentException('This event carries no family-visible message: '.$event->value);
        }
        if ($internalNote !== null && $actorSide !== WorkflowActorSide::STAFF) {
            throw new InvalidArgumentException('Only Staff events carry an internal note.');
        }

        self::assertMetadata($event, $metadata);

        return WorkflowEvent::create([
            'workflowable_type' => $request->getMorphClass(),
            'workflowable_id' => $request->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'event_type' => $event,
            'actor_user_id' => $actorUserId,
            'actor_side' => $actorSide,
            'reason_code' => $reason?->value,
            'public_message' => $publicMessage,
            'internal_note' => $internalNote,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * Plain text: control and invisible format characters (including bidi
     * overrides) removed, line breaks kept, trimmed, bounded; blank = none.
     */
    private static function text(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim((string) preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r\n", "\n", $value)));
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean) > self::MAX_MESSAGE_LENGTH) {
            throw new InvalidArgumentException("{$field} is longer than ".self::MAX_MESSAGE_LENGTH.' characters.');
        }

        return $clean;
    }

    /** @param array<array-key, mixed> $metadata */
    private static function assertMetadata(WorkflowEventType $event, array $metadata): void
    {
        $contract = self::EVENT_CODE_METADATA[$event->value] ?? [];
        foreach ($metadata as $key => $value) {
            $enum = is_string($key) ? ($contract[$key] ?? null) : null;
            if ($enum === null) {
                throw new InvalidArgumentException('Metadata key not allowed: '.(is_string($key) ? $key : 'non-string key'));
            }
            if (! is_string($value) || $enum::tryFrom($value) === null) {
                throw new InvalidArgumentException("Metadata {$key} must be a controlled code.");
            }
        }
    }
}
