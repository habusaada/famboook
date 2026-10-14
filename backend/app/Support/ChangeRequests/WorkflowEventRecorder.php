<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestApplyFailure;
use App\Enums\ChangeRequestAttestation;
use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Enums\SubmissionChannel;
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
 *   COORDINATOR events use the coordinator table and only on a
 *   COORDINATOR-channel request; FAMILY events only on a FAMILY-channel
 *   request (CO-02.3b);
 * - a rejection carries a reason the transition accepts; a failed apply
 *   carries its ChangeRequestApplyFailure code; nothing else carries one;
 * - public_message (family-visible) is required to return a request and to
 *   resubmit it, optional on a rejection, refused elsewhere; internal_note
 *   (Staff-only) only on Staff events. Plain text, at most 2000 characters;
 * - metadata keys are allow-listed per event, each value a controlled code.
 *   Only APPROVED has keys (attestation codes, FP-ADR-076; the reviewer's
 *   life-status verification method, FP-ADR-077). The request
 *   payload and any registry value are never copied into an event.
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
     * Allowed metadata per event type: key => either the enum its value must
     * be a case of, or the ONE enum case its value must be. Any other key is
     * refused. APPROVED carries the reviewer's attestation codes for types
     * that require them (FP-ADR-076): each key accepts only its own code;
     * and the life-status verification method for types that require one
     * (FP-ADR-077): any LifeStatusVerificationMethod code.
     *
     * @var array<string, array<string, class-string<\BackedEnum>|\BackedEnum>>
     */
    public const EVENT_CODE_METADATA = [
        'APPROVED' => [
            'identity' => ChangeRequestAttestation::IDENTITY_VERIFIED,
            'relationship' => ChangeRequestAttestation::RELATIONSHIP_VERIFIED,
            'verification_method' => LifeStatusVerificationMethod::class,
        ],
    ];

    /**
     * Keys that may only appear together (all or none) per event type: an
     * approval attests identity AND relationship, never one of them.
     *
     * @var array<string, list<string>>
     */
    public const EVENT_METADATA_TOGETHER = [
        'APPROVED' => ['identity', 'relationship'],
    ];

    /**
     * @param  array<string, string>  $metadata
     */
    public static function record(
        ChangeRequest $request,
        ?ChangeRequestStatus $from,
        WorkflowEventType $event,
        WorkflowActorSide $actorSide,
        ?int $actorUserId,
        ChangeRequestRejectionReason|ChangeRequestApplyFailure|null $reason = null,
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
            // PWA-5b: the safe failure code is required — it decides whether
            // the request may later be rejected as NO_LONGER_APPLICABLE.
            if (! $reason instanceof ChangeRequestApplyFailure) {
                throw new InvalidArgumentException('APPLY_FAILED requires its failure code.');
            }
        } else {
            if ($reason instanceof ChangeRequestApplyFailure) {
                throw new InvalidArgumentException('A failure code belongs to APPLY_FAILED only.');
            }
            if ($actorSide === WorkflowActorSide::SYSTEM) {
                // FP-ADR-079: only a system-verified type moves by the system.
                if (! $request->type->isSystemVerified() || $reason !== null) {
                    throw new LogicException('Only a system-verified request type has SYSTEM transitions.');
                }
                $transition = ChangeRequestTransitions::assertSystemAllowed($from, $to);
            } elseif ($actorSide === WorkflowActorSide::COORDINATOR) {
                // CO-02.3b: a Coordinator acts only on a COORDINATOR-channel request.
                if ($request->submission_channel !== SubmissionChannel::COORDINATOR || $reason !== null) {
                    throw new LogicException('Coordinator events belong to coordinator-channel requests only.');
                }
                $transition = ChangeRequestTransitions::assertCoordinatorAllowed($from, $to);
            } else {
                // CO-02.3b: the household acts only on a FAMILY-channel request.
                if ($actorSide === WorkflowActorSide::FAMILY && $request->submission_channel !== SubmissionChannel::FAMILY) {
                    throw new LogicException('Family events belong to family-channel requests only.');
                }
                $transition = ChangeRequestTransitions::assertAllowed($from, $to, $reason);
            }
            if ($transition->event !== $event || $transition->actorSide !== $actorSide) {
                throw new LogicException('Workflow event does not match the transition: '.$event->value.' by '.$actorSide->value);
            }
        }

        if ($actorSide !== WorkflowActorSide::SYSTEM && $actorUserId === null) {
            throw new InvalidArgumentException('A family, Coordinator or Staff event needs its acting user.');
        }

        $publicMessage = self::cleanText($publicMessage, 'public_message');
        $internalNote = self::cleanText($internalNote, 'internal_note');
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
     * Public so the Domain Actions validate exactly what will be stored.
     */
    public static function cleanText(?string $value, string $field): ?string
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
            $allowed = is_string($key) ? ($contract[$key] ?? null) : null;
            if ($allowed === null) {
                throw new InvalidArgumentException('Metadata key not allowed: '.(is_string($key) ? $key : 'non-string key'));
            }
            $valid = is_string($value) && ($allowed instanceof \BackedEnum ? $value === $allowed->value : $allowed::tryFrom($value) !== null);
            if (! $valid) {
                throw new InvalidArgumentException("Metadata {$key} must be its controlled code.");
            }
        }
        $together = self::EVENT_METADATA_TOGETHER[$event->value] ?? [];
        $present = array_intersect($together, array_keys($metadata));
        if ($present !== [] && count($present) !== count($together)) {
            throw new InvalidArgumentException('Metadata keys must appear together: '.implode(', ', $together));
        }
    }
}
