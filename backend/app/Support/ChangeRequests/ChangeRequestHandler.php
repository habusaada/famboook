<?php

namespace App\Support\ChangeRequests;

use App\Enums\ProfileReviewSection;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Support\FamilyAuth\FamilyAccessResult;

/**
 * The behaviour of ONE Change Request type (PWA-5b). Registered only in
 * ChangeRequestTypes — the one type → handler mapping; controllers and the
 * frontend never switch on the type.
 *
 * The engine (the Change Request Domain Actions) owns the workflow: locks,
 * transitions, events, activity, idempotency and the base fingerprint. A
 * handler owns only its type's data and its canonical effect:
 *
 * - it NEVER writes a registry model or table itself: apply() calls the
 *   existing canonical Domain Action(s) only (enforced by
 *   ChangeRequestHandlerBoundaryTest);
 * - it reads canonical state fresh: preconditions() and baseValues() run at
 *   submit, approve and apply, under the engine's locks;
 * - it refuses with ChangeRequestException (PRECONDITION_FAILED,
 *   NOT_APPLICABLE) or ValidationException — fixed messages, no values.
 */
interface ChangeRequestHandler
{
    /** May a family submit this type through the Family Portal? */
    public function familySubmittable(): bool;

    /** The version of the submitted_data schema this handler writes and reads. */
    public function payloadVersion(): int;

    /**
     * Laravel validation rules for the submission input (the proposed values
     * and any member_ref). Unknown keys never reach submitted_data:
     * normalize() builds it from validated values only.
     *
     * @return array<string, mixed>
     */
    public function inputRules(Family $family): array;

    /**
     * Laravel validation rules for the STORED proposal (submitted_data),
     * re-checked at submit, approve and apply.
     *
     * @return array<string, mixed>
     */
    public function dataRules(Family $family): array;

    /**
     * The target inside the resolved Family — the Family itself or a member
     * resolved from the input's member_ref among THIS Family's ACTIVE
     * memberships (never an id from the client). Unknown → ChangeRequestException NOT_FOUND.
     *
     * @param  array<string, mixed>  $validated
     */
    public function resolveTarget(FamilyAccessResult $context, array $validated): ChangeRequestTarget;

    /**
     * The proposed values to store as submitted_data: a non-empty map built
     * only from validated input, never containing the member reference.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalize(array $validated, ChangeRequestTarget $target): array;

    /**
     * Exactly the CURRENT canonical state this request depends on, read
     * fresh — the values it would change or, for something that does not
     * exist yet (a new member), the registry state that decides it (e.g. who
     * holds the proposed National ID). Only their keyed fingerprint is
     * stored (base_fingerprint).
     *
     * @param  array<string, mixed>  $data  the normalized proposal
     * @return array<string, mixed>
     */
    public function baseValues(ChangeRequestTarget $target, array $data): array;

    /**
     * Business preconditions against current canonical state (e.g. the target
     * is still an active, living member). Throws ChangeRequestException
     * PRECONDITION_FAILED or NOT_APPLICABLE.
     *
     * @param  array<string, mixed>  $data  the normalized proposal
     */
    public function preconditions(ChangeRequestTarget $target, array $data): void;

    /**
     * The canonical effect: call the approved existing Domain Action(s) with
     * the stored proposal. Runs inside the engine's APPLY transaction; any
     * exception rolls everything back.
     */
    public function apply(ChangeRequest $request, ChangeRequestTarget $target, int $actingUserId): void;

    /**
     * The proposal for one viewer, in the approved V1 shape
     * `{rows: [{label, current, proposed}]}` built with
     * ChangeRequestPresentation (PWA-6.1). The context is computed on the
     * server (audience, canViewSensitiveIdentity, canViewInternalNotes);
     * never a raw sensitive value the viewer may not see.
     *
     * @return array{rows: list<array{label: string, current: ?string, proposed: ?string}>}
     */
    public function present(ChangeRequest $request, ChangeRequestPresentationContext $context): array;

    /**
     * The Family Profile Review sections this type touches (the PWA-4 PENDING
     * mapping; nothing else reads it in PWA-5).
     *
     * @return list<ProfileReviewSection>
     */
    public function profileSections(): array;

    /**
     * Two OPEN requests of this type with the same key may not coexist in a
     * Family (e.g. "one open correction of this member"). NULL = no limit.
     *
     * @param  array<string, mixed>  $data  the normalized proposal
     */
    public function openConflictKey(ChangeRequestTarget $target, array $data): ?string;
}
