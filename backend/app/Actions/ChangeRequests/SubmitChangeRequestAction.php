<?php

namespace App\Actions\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Enums\FamilyActivityType;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Support\ChangeRequests\ChangeRequestActors;
use App\Support\ChangeRequests\ChangeRequestBase;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\ChangeRequests\ChangeRequestTransitions;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use App\Support\FamilyActivityLog;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A family submits a Change Request (PWA-5b, docs/05 §56 / WF-ADR-049). V1
 * has no DRAFT: the request is created SUBMITTED. Canonical data does not
 * change (docs/03 §67).
 *
 * WHO and WHICH FAMILY come only from the trusted family.context result —
 * never from the submission: no client family_id, status, actor, base
 * fingerprint or internal target id is read. The type's handler validates
 * the input, resolves the target inside THIS Family (member_ref), and builds
 * the proposal from validated values only.
 *
 * Under the Family row lock: the client_reference replay check, the type's
 * preconditions, the open-conflict check, the base fingerprint, then the
 * request, its SUBMITTED event and the CHANGE_REQUEST_SUBMITTED activity.
 *
 * Idempotency (client_reference, unique per submitting user): the same key
 * with the same Family, type, target, proposal and reason returns the
 * existing request untouched (replayed); the same key with anything
 * different is refused (CHANGE_REQUEST_IDEMPOTENCY_CONFLICT) — never the
 * other request. The comparison uses the stored proposal; nothing extra is
 * kept.
 */
class SubmitChangeRequestAction
{
    /** Bounds on the raw input and the stored proposal (JSON bytes, nesting). */
    public const MAX_PAYLOAD_BYTES = 16384;

    public const MAX_PAYLOAD_DEPTH = 4;

    public function __construct(private readonly ChangeRequestTypes $types) {}

    public function handle(FamilyAccessResult $context, ChangeRequestSubmission $submission): ChangeRequestOutcome
    {
        // The family submission switch (PWA-5e), checked first and server-side:
        // nothing is validated, read or written while it is off.
        if (config('change_requests.family_submission_enabled') !== true) {
            throw new ChangeRequestException(ChangeRequestException::SUBMISSION_DISABLED);
        }

        $transition = ChangeRequestTransitions::assertAllowed(null, ChangeRequestStatus::SUBMITTED);
        $user = ChangeRequestActors::family($context, $transition);
        /** @var Family $family */
        $family = $context->family;
        $person = $context->person ?? throw new ChangeRequestException(ChangeRequestException::ACTOR_NOT_ALLOWED);

        $handler = $this->types->handler($submission->type);
        if (! $handler->familySubmittable()) {
            throw new ChangeRequestException(ChangeRequestException::TYPE_UNAVAILABLE);
        }

        self::assertBounded($submission->input, 'input');
        $validated = Validator::make($submission->input, $handler->inputRules($family))->validate();
        $target = $handler->resolveTarget($context, $validated);
        if ((int) $target->family->getKey() !== (int) $family->getKey()) {
            throw new LogicException('A change request target must belong to the submitting Family.');
        }
        $data = $this->proposal($handler, $family, $validated, $target);
        $reason = ChangeRequestWorkflow::message($submission->reason, 'reason', false);
        $reference = $submission->clientReference;
        if ($reference !== null && ! Str::isUuid($reference)) {
            throw ValidationException::withMessages(['client_reference' => 'مرجع الطلب غير صالح.']);
        }

        $material = [
            'family_id' => (int) $family->getKey(), 'type' => $submission->type, 'target_membership_id' => $target->membership?->getKey(),
            'submitted_data' => $data, 'reason' => $reason,
        ];

        try {
            return DB::transaction(function () use ($handler, $family, $person, $user, $target, $data, $reason, $reference, $material, $submission) {
                // Serializes submissions of one Family: replay, open-conflict and creation.
                Family::query()->whereKey($family->getKey())->lockForUpdate()->firstOrFail();

                if ($reference !== null && ($existing = $this->byReference($user->getKey(), $reference)) !== null) {
                    return $this->replay($existing, $material);
                }

                $handler->preconditions($target, $data);
                $this->assertNoOpenConflict($handler, $family, $submission, $target, $data);
                $base = ChangeRequestBase::of($handler->baseValues($target));

                $request = new ChangeRequest([
                    'family_id' => $family->getKey(),
                    'type' => $submission->type,
                    'payload_version' => $handler->payloadVersion(),
                    'person_id' => $target->person?->getKey(),
                    'target_membership_id' => $target->membership?->getKey(),
                    'submitted_data' => $data,
                    'reason' => $reason,
                    'base_fingerprint' => $base['fingerprint'],
                    'base_key_version' => $base['key_version'],
                    'submitted_by' => $user->getKey(),
                    'submitted_by_person_id' => $person->getKey(),
                    'client_reference' => $reference,
                ]);
                $request->forceFill(['status' => ChangeRequestStatus::SUBMITTED, 'submitted_at' => now()])->save();

                WorkflowEventRecorder::record($request, null, WorkflowEventType::SUBMITTED, WorkflowActorSide::FAMILY, $user->getKey());
                FamilyActivityLog::record($family->getKey(), FamilyActivityType::CHANGE_REQUEST_SUBMITTED, $request, $user->getKey(), [
                    'request_type' => $submission->type->value,
                ]);

                return new ChangeRequestOutcome($request);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The unique (submitted_by, client_reference) index is the final guard.
            $existing = $reference !== null ? $this->byReference($user->getKey(), $reference) : null;
            if ($existing === null) {
                throw $e;
            }

            return $this->replay($existing, $material);
        }
    }

    /**
     * The proposal to store: normalized by the handler, bounded, and valid
     * under the type's own data rules.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function proposal(ChangeRequestHandler $handler, Family $family, array $validated, ChangeRequestTarget $target): array
    {
        $data = $handler->normalize($validated, $target);
        if ($data === [] || array_is_list($data)) {
            throw new LogicException('A change request proposal must be a non-empty map.');
        }
        self::assertBounded($data, 'data');
        Validator::make($data, $handler->dataRules($family))->validate();

        return $data;
    }

    /** @param array<array-key, mixed> $value */
    private static function assertBounded(array $value, string $field): void
    {
        // Depth counts the outer map: at most MAX_PAYLOAD_DEPTH nested levels.
        $json = json_encode($value, JSON_UNESCAPED_UNICODE, self::MAX_PAYLOAD_DEPTH);
        if ($json === false || strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw ValidationException::withMessages([$field => 'بيانات الطلب كبيرة أو متداخلة أكثر من المسموح.']);
        }
    }

    private function byReference(int $userId, string $reference): ?ChangeRequest
    {
        return ChangeRequest::query()->where('submitted_by', $userId)->where('client_reference', $reference)->first();
    }

    /** @param array<string, mixed> $material */
    private function replay(ChangeRequest $existing, array $material): ChangeRequestOutcome
    {
        $same = (int) $existing->family_id === $material['family_id']
            && $existing->type === $material['type']
            && $existing->target_membership_id === $material['target_membership_id']
            && $existing->reason === $material['reason']
            && ChangeRequestBase::canonical($existing->submitted_data) === ChangeRequestBase::canonical($material['submitted_data']);
        if (! $same) {
            throw new ChangeRequestException(ChangeRequestException::IDEMPOTENCY_CONFLICT);
        }

        return new ChangeRequestOutcome($existing, replayed: true);
    }

    /** @param array<string, mixed> $data */
    private function assertNoOpenConflict(ChangeRequestHandler $handler, Family $family, ChangeRequestSubmission $submission, ChangeRequestTarget $target, array $data): void
    {
        $key = $handler->openConflictKey($target, $data);
        if ($key === null) {
            return;
        }
        $open = ChangeRequest::query()
            ->where('family_id', $family->getKey())
            ->where('type', $submission->type)
            ->whereIn('status', ChangeRequestStatus::open())
            ->get();
        foreach ($open as $other) {
            if ($handler->openConflictKey(ChangeRequestTarget::of($other, $family), $other->submitted_data) === $key) {
                throw new ChangeRequestException(ChangeRequestException::ALREADY_OPEN);
            }
        }
    }
}
