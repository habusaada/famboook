<?php

namespace App\Actions\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Enums\FamilyActivityType;
use App\Enums\SubmissionChannel;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\Clan;
use App\Models\Family;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestActors;
use App\Support\ChangeRequests\ChangeRequestBase;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestSubmissionContext;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\ChangeRequests\ChangeRequestTransitions;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\CoordinatorSubmissionPolicy;
use App\Support\ChangeRequests\CoordinatorSubmittable;
use App\Support\ChangeRequests\FamilySubmissionPolicy;
use App\Support\ChangeRequests\ProposesNewFamily;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use App\Support\FamilyActivityLog;
use App\Support\FamilyAuth\CoordinatorScopes;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Closure;
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
 *
 * CO-02.3b: handleCoordinator() is the COORDINATOR channel — a Coordinator
 * proposing a change about a Family in their scope, through the same core
 * (validation, locks, idempotency, open conflicts, base fingerprint). Its
 * own actor (coordinator-request.submit, never change-request.*), its own
 * switch (CoordinatorSubmissionPolicy), the scope re-checked before any
 * input is read and again under the Family lock, and only handlers that
 * opt in (CoordinatorSubmittable). The open-conflict check spans both
 * channels: one open proposal per key and Family, whoever submitted it.
 *
 * CO-02.5: handleCoordinatorProposal() submits a request for a NEW Family
 * (REGISTER_FAMILY, ProposesNewFamily): no family_id, a proposed Clan /
 * Branch checked against the scope (coversPlacement) before any input is
 * read and again inside the transaction; every refusal of a placement is
 * ONE answer (placementUnavailable), so an inaccessible, inactive or
 * nonexistent Clan or Branch is never told apart. Nothing identity-
 * dependent is answered; the open-conflict check is per submitting user.
 * No Family, Person, identifier or Family activity is written.
 */
class SubmitChangeRequestAction
{
    /** Bounds on the raw input and the stored proposal (JSON bytes, nesting). */
    public const MAX_PAYLOAD_BYTES = 16384;

    public const MAX_PAYLOAD_DEPTH = 4;

    public function __construct(private readonly ChangeRequestTypes $types) {}

    public function handle(FamilyAccessResult $context, ChangeRequestSubmission $submission, bool $systemVerified = false): ChangeRequestOutcome
    {
        // WHO first (the actor refusal keeps its precedence), then the family
        // submission channel (PWA-6.1b, FamilySubmissionPolicy: OFF / PILOT
        // allowlist / GENERAL) for THIS context's Family — before any input is
        // validated, read or written. Authoritative: the HTTP layer only asks
        // earlier.
        $transition = ChangeRequestTransitions::assertAllowed(null, ChangeRequestStatus::SUBMITTED);
        $user = ChangeRequestActors::family($context, $transition);
        if (! FamilySubmissionPolicy::allows($context)) {
            throw new ChangeRequestException(ChangeRequestException::SUBMISSION_DISABLED);
        }
        $person = $context->person ?? throw new ChangeRequestException(ChangeRequestException::ACTOR_NOT_ALLOWED);
        $submitter = ChangeRequestSubmissionContext::family($context, $user, $person);

        $handler = $this->types->handler($submission->type);
        if (! $handler->familySubmittable()) {
            throw new ChangeRequestException(ChangeRequestException::TYPE_UNAVAILABLE);
        }
        // FP-ADR-079: a system-verified type (CONTACT_UPDATE) is created only
        // by its own OTP flow, never through the generic submission.
        if ($submission->type->isSystemVerified() !== $systemVerified) {
            if ($systemVerified) {
                throw new LogicException('Only a system-verified type is submitted through its verification flow.');
            }
            throw new ChangeRequestException(ChangeRequestException::TYPE_UNAVAILABLE);
        }

        return $this->create($submitter, $handler, $submission, fn (array $validated) => $handler->resolveTarget($context, $validated));
    }

    /**
     * A Coordinator proposes a change about a Family in their scope
     * (CO-02.3b; PERSON_ADD = ADD_FAMILY_MEMBER). WHO first (a Coordinator
     * right now, with coordinator-request.submit), then the coordinator
     * switch, then the scope — a Family outside it is NOT_FOUND, exactly
     * like a nonexistent one — all before any input is read. The Family is
     * the caller's server-resolved row; this action re-checks it.
     */
    public function handleCoordinator(User $user, Family $family, ChangeRequestSubmission $submission): ChangeRequestOutcome
    {
        $coordinator = ChangeRequestActors::coordinator($user, ChangeRequestTransitions::assertCoordinatorAllowed(null, ChangeRequestStatus::SUBMITTED));
        if (! CoordinatorSubmissionPolicy::allows($user)) {
            throw new ChangeRequestException(ChangeRequestException::SUBMISSION_DISABLED);
        }
        $scopes = app(CoordinatorScopes::class);
        if (! $scopes->canAccessFamily($coordinator, $family)) {
            throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
        }
        // The requester Person is the Coordinator's own (always an eligible head, D12).
        $person = app(FamilyAccessResolver::class)->familyContext($user)->person
            ?? throw new ChangeRequestException(ChangeRequestException::ACTOR_NOT_ALLOWED);
        $submitter = ChangeRequestSubmissionContext::coordinator($user, $person, $family);

        $handler = $this->types->handler($submission->type);
        if (! $handler instanceof CoordinatorSubmittable || $handler instanceof ProposesNewFamily || $submission->type->isSystemVerified()) {
            throw new ChangeRequestException(ChangeRequestException::TYPE_UNAVAILABLE);
        }

        return $this->create(
            $submitter,
            $handler,
            $submission,
            fn (array $validated) => $handler->resolveCoordinatorTarget($submitter, $validated),
            // Re-checked under the Family lock: a scope revoked meanwhile wins.
            function () use ($scopes, $coordinator, $family): void {
                if (! $scopes->canAccessFamily($coordinator, $family)) {
                    throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
                }
            },
        );
    }

    /**
     * A Coordinator proposes a NEW Family placed in this Clan / Branch
     * (CO-02.5; FAMILY_ADD = REGISTER_FAMILY). WHO first (a Coordinator right
     * now, with coordinator-request.submit), then the coordinator switch,
     * then the placement against the CURRENT scope — before any input is
     * read. The Clan and Branch are the caller's server-resolved rows (a
     * Branch of another Clan is refused like any uncovered placement).
     */
    public function handleCoordinatorProposal(User $user, Clan $clan, ?Branch $branch, ChangeRequestSubmission $submission): ChangeRequestOutcome
    {
        $coordinator = ChangeRequestActors::coordinator($user, ChangeRequestTransitions::assertCoordinatorAllowed(null, ChangeRequestStatus::SUBMITTED));
        if (! CoordinatorSubmissionPolicy::allows($user)) {
            throw new ChangeRequestException(ChangeRequestException::SUBMISSION_DISABLED);
        }
        $scopes = app(CoordinatorScopes::class);
        if (! $scopes->coversPlacement($coordinator, $clan, $branch)) {
            throw self::placementUnavailable();
        }
        $person = app(FamilyAccessResolver::class)->familyContext($user)->person
            ?? throw new ChangeRequestException(ChangeRequestException::ACTOR_NOT_ALLOWED);

        $handler = $this->types->handler($submission->type);
        if (! $handler instanceof ProposesNewFamily) {
            throw new ChangeRequestException(ChangeRequestException::TYPE_UNAVAILABLE);
        }

        self::assertBounded($submission->input, 'input');
        $validated = Validator::make($submission->input, $handler->proposalRules())->validate();
        $data = $handler->normalizeProposal($validated);
        if ($data === [] || array_is_list($data)) {
            throw new LogicException('A change request proposal must be a non-empty map.');
        }
        self::assertBounded($data, 'data');
        Validator::make($data, $handler->proposalRules())->validate();
        $reason = ChangeRequestWorkflow::message($submission->reason, 'reason', false);
        $reference = $submission->clientReference;
        if ($reference !== null && ! Str::isUuid($reference)) {
            throw ValidationException::withMessages(['client_reference' => 'مرجع الطلب غير صالح.']);
        }

        $material = [
            'family_id' => null, 'type' => $submission->type, 'target_membership_id' => null,
            'submitted_data' => $data, 'reason' => $reason, 'submission_channel' => SubmissionChannel::COORDINATOR,
            'proposed_clan_id' => (int) $clan->getKey(), 'proposed_branch_id' => $branch === null ? null : (int) $branch->getKey(),
        ];

        try {
            return DB::transaction(function () use ($handler, $scopes, $clan, $branch, $user, $person, $data, $reason, $reference, $material, $submission) {
                $request = new ChangeRequest([
                    'family_id' => null,
                    'submission_channel' => SubmissionChannel::COORDINATOR,
                    'type' => $submission->type,
                    'payload_version' => $handler->payloadVersion(),
                    'submitted_data' => $data,
                    'reason' => $reason,
                    'proposed_clan_id' => $clan->getKey(),
                    'proposed_branch_id' => $branch?->getKey(),
                    'submitted_by' => $user->getKey(),
                    'submitted_by_person_id' => $person->getKey(),
                    'client_reference' => $reference,
                ]);
                // The handler takes the locks its base needs (e.g. the head's
                // National ID): it serializes the replay and open-conflict checks.
                $base = ChangeRequestBase::of($handler->proposalBaseValues($request));

                if ($reference !== null && ($existing = $this->byReference($user->getKey(), $reference)) !== null) {
                    return $this->replay($existing, $material);
                }
                // Re-checked here with a freshly resolved context (coversPlacement
                // reads the context's assignments): a scope revoked meanwhile wins.
                if (! $scopes->coversPlacement($scopes->context($user), $clan, $branch)) {
                    throw self::placementUnavailable();
                }
                $this->assertNoOpenProposal($handler, $user, $submission, $data);

                $request->forceFill([
                    'base_fingerprint' => $base['fingerprint'],
                    'base_key_version' => $base['key_version'],
                    'status' => ChangeRequestStatus::SUBMITTED,
                    'submitted_at' => now(),
                ])->save();
                WorkflowEventRecorder::record($request, null, WorkflowEventType::SUBMITTED, WorkflowActorSide::COORDINATOR, $user->getKey());

                return new ChangeRequestOutcome($request);
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $reference !== null ? $this->byReference($user->getKey(), $reference) : null;
            if ($existing === null) {
                throw $e;
            }

            return $this->replay($existing, $material);
        }
    }

    /**
     * The ONE answer for a placement a Coordinator may not use — nonexistent,
     * inactive, of another Clan or outside the scope alike (CO-02.5).
     */
    public static function placementUnavailable(): ValidationException
    {
        return ValidationException::withMessages(['placement' => 'مكان الأسرة المقترح غير متاح ضمن نطاقك.']);
    }

    /**
     * The shared core of both channels: validate, resolve the target inside
     * the submitter's Family, build the proposal, then — under the Family row
     * lock — replay check, preconditions, open conflicts, base fingerprint,
     * the request, its SUBMITTED event (the channel's actor side) and the
     * CHANGE_REQUEST_SUBMITTED activity.
     *
     * @param  Closure(array<string, mixed>): ChangeRequestTarget  $resolveTarget
     * @param  (Closure(): void)|null  $underLock  an extra check once the Family is locked
     */
    private function create(
        ChangeRequestSubmissionContext $submitter,
        ChangeRequestHandler $handler,
        ChangeRequestSubmission $submission,
        Closure $resolveTarget,
        ?Closure $underLock = null,
    ): ChangeRequestOutcome {
        $family = $submitter->family;
        $user = $submitter->user;

        self::assertBounded($submission->input, 'input');
        $validated = Validator::make($submission->input, $handler->inputRules($family))->validate();
        $target = $resolveTarget($validated);
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
            'submitted_data' => $data, 'reason' => $reason, 'submission_channel' => $submitter->channel,
            'proposed_clan_id' => null, 'proposed_branch_id' => null,
        ];

        try {
            return DB::transaction(function () use ($handler, $submitter, $family, $user, $target, $data, $reason, $reference, $material, $submission, $underLock) {
                // Serializes submissions of one Family: replay, open-conflict and creation.
                Family::query()->whereKey($family->getKey())->lockForUpdate()->firstOrFail();
                if ($underLock !== null) {
                    $underLock();
                }

                if ($reference !== null && ($existing = $this->byReference($user->getKey(), $reference)) !== null) {
                    return $this->replay($existing, $material);
                }

                $handler->preconditions($target, $data);
                $this->assertNoOpenConflict($handler, $family, $submission, $target, $data);
                $base = ChangeRequestBase::of($handler->baseValues($target, $data));

                $request = new ChangeRequest([
                    'family_id' => $family->getKey(),
                    'submission_channel' => $submitter->channel,
                    'type' => $submission->type,
                    'payload_version' => $handler->payloadVersion(),
                    'person_id' => $target->person?->getKey(),
                    'target_membership_id' => $target->membership?->getKey(),
                    'submitted_data' => $data,
                    'reason' => $reason,
                    'base_fingerprint' => $base['fingerprint'],
                    'base_key_version' => $base['key_version'],
                    'submitted_by' => $user->getKey(),
                    'submitted_by_person_id' => $submitter->person->getKey(),
                    'client_reference' => $reference,
                ]);
                $request->forceFill(['status' => ChangeRequestStatus::SUBMITTED, 'submitted_at' => now()])->save();

                WorkflowEventRecorder::record($request, null, WorkflowEventType::SUBMITTED, $submitter->actorSide(), $user->getKey());
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
        $same = self::sameId($existing->family_id, $material['family_id'])
            && self::sameId($existing->proposed_clan_id, $material['proposed_clan_id'])
            && self::sameId($existing->proposed_branch_id, $material['proposed_branch_id'])
            && $existing->submission_channel === $material['submission_channel']
            && $existing->type === $material['type']
            && $existing->target_membership_id === $material['target_membership_id']
            && $existing->reason === $material['reason']
            && ChangeRequestBase::canonical($existing->submitted_data) === ChangeRequestBase::canonical($material['submitted_data']);
        if (! $same) {
            throw new ChangeRequestException(ChangeRequestException::IDEMPOTENCY_CONFLICT);
        }

        return new ChangeRequestOutcome($existing, replayed: true);
    }

    private static function sameId(mixed $stored, ?int $expected): bool
    {
        return ($stored === null ? null : (int) $stored) === $expected;
    }

    /**
     * One open proposal per key for THIS submitting user (CO-02.5) — never
     * across users: another Coordinator's proposal must not be revealed.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoOpenProposal(ProposesNewFamily $handler, User $user, ChangeRequestSubmission $submission, array $data): void
    {
        $key = $handler->proposalOpenConflictKey($data);
        if ($key === null) {
            return;
        }
        $open = ChangeRequest::query()
            ->where('submitted_by', $user->getKey())
            ->where('type', $submission->type)
            ->whereNull('family_id')
            ->whereIn('status', ChangeRequestStatus::open())
            ->get();
        foreach ($open as $other) {
            if ($handler->proposalOpenConflictKey($other->submitted_data) === $key) {
                throw new ChangeRequestException(ChangeRequestException::ALREADY_OPEN);
            }
        }
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
