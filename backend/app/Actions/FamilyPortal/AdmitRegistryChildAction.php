<?php

namespace App\Actions\FamilyPortal;

use App\Actions\AddFamilyMemberAction;
use App\Actions\AttachFamilyMemberAction;
use App\Enums\FamilyMemberIdentityOutcome;
use App\Enums\FamilyStatus;
use App\Enums\RegistryChildReason as Reason;
use App\Exceptions\CivilLookupException;
use App\Exceptions\DuplicateNationalIdException;
use App\Exceptions\RegistryAdmissionReferenceException as RefException;
use App\Exceptions\RegistryAdmissionRefusedException as Refused;
use App\Exceptions\RegistryAdmissionUnavailableException;
use App\Exceptions\RegistryReleasePinUnavailableException as PinUnavailable;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyMemberIdentity;
use App\Support\FamilyPortal\FamilyRegistryAdmissionPolicy;
use App\Support\FamilyPortal\HouseholdMemberReference;
use App\Support\FamilyPortal\RegistryAdmission\AdmissionCandidate;
use App\Support\FamilyPortal\RegistryAdmission\FamilyRegistryAdmissionAudit as Audit;
use App\Support\FamilyPortal\RegistryAdmission\GatedRegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\PostgresRegistryReleasePin;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionEvidence as Evidence;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionFailure;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionOutcome as Outcome;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionReference;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRefs as Refs;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionReplayClaim;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRequest;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSourceIntegrityException;
use App\Support\FamilyPortal\RegistryAdmission\RegistryReleasePinVerifier;
use App\Support\FamilyPortal\RegistryAdmission\SuggestedChildrenDiscovery;
use App\Support\NationalIdGuard;
use App\Support\RelationshipTypes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Direct admission of the signed-in household head's own civil-verified son or daughter
 * (FB-FAMILY-ADD-01 ADD-WP-07B-2c; ADR §2, §5, §7, §9 – §10a; plan ADD-WP-07B-2c-P).
 * INTERNAL: no route, controller, job or command calls it, and nothing binds its
 * dependencies. In operation it can admit no one: the flag is OFF, no role holds the
 * permission, no registry source is bound, every release pin fails closed until
 * DBA-1 … DBA-4, and GatedRegistryChildAdmissibility refuses every child until gates G-04
 * and G-05 close (D-06).
 *
 * handle(), inside RegistryAdmissionFailure::run():
 * A. household context (403), flag and permission (404), declaration (422): nothing recorded;
 * B. a committed admission of this actor's key → replay, before any registry read;
 * C. the strict reference (15 minutes); an authentic but expired one may only replay;
 * D. the registry, OUTSIDE the transaction: the head's children in one release;
 * E. one READ COMMITTED transaction, locks in this order:
 *    release pin → Family FOR UPDATE → head membership FOR SHARE → the child's National
 *    ID advisory lock → Person FOR UPDATE (an active member anywhere is refused first);
 *    then eligibility again under the locks, the Person (created or attached by the
 *    existing actions), the evidence row and the success event, committed together.
 * F. every refusal rolls everything back, is recorded in its own short transaction and
 *    does not consume the idempotency key.
 */
final class AdmitRegistryChildAction
{
    public const PERMISSION = 'family-member.admit-from-registry';

    private readonly SuggestedChildrenDiscovery $discovery;

    public function __construct(
        RegistryChildSource $source,
        private readonly RegistryReleasePinVerifier $verifier,
        private readonly RegistryChildAdmissibility $admissibility = new GatedRegistryChildAdmissibility,
        private readonly AddFamilyMemberAction $add = new AddFamilyMemberAction,
        private readonly AttachFamilyMemberAction $attach = new AttachFamilyMemberAction,
    ) {
        // Test-only admissibility overrides never run outside the test runner.
        if (! $admissibility instanceof GatedRegistryChildAdmissibility && ! app()->runningUnitTests()) {
            throw new LogicException('Only the gated admissibility may admit outside the test runner.');
        }
        $this->discovery = new SuggestedChildrenDiscovery($source);
    }

    /**
     * @throws Refused 403 / 404 / 409 / 422
     * @throws RefException REFERENCE_INVALID (422) or RELEASE_CHANGED (409)
     * @throws RegistryAdmissionUnavailableException 503, sanitized
     */
    public function handle(FamilyAccessResult $context, RegistryAdmissionRequest $request): Outcome
    {
        return RegistryAdmissionFailure::run(fn () => $this->admit($context, $request), Refused::class);
    }

    private function admit(FamilyAccessResult $context, RegistryAdmissionRequest $request): Outcome
    {
        if (! $context->hasFamilyContext()) {
            throw new Refused(Refused::FAMILY_CONTEXT_UNAVAILABLE);
        }
        if (! FamilyRegistryAdmissionPolicy::allowsFamily($context->family) || ! $context->user->can(self::PERMISSION)) {
            throw new Refused(Refused::ADMISSION_UNAVAILABLE);
        }
        $declaration = config('family_registry_admission.declaration_version');
        if (! is_string($declaration) || trim($declaration) === '' || ! $request->declarationAccepted
            || ! hash_equals($declaration, $request->declarationVersion)) {
            throw new Refused(Refused::DECLARATION_REQUIRED);
        }
        self::assertNoQueryCapture();

        $known = new AdmissionAttemptFacts;
        try {
            return $this->decide($context, $request, $known);
        } catch (Refused $e) {
            $this->recordRefusal($context, $request, $known, $e->auditCode);
            throw $e;
        } catch (RefException $e) {
            $this->recordRefusal($context, $request, $known, $e->reason);
            throw $e;
        } catch (PinUnavailable|CivilLookupException|RegistryChildSourceIntegrityException $e) {
            Audit::registryUnavailable($context->user, $context->family, $known->releaseId, $request->ip);
            throw $e;
        }
    }

    private function decide(FamilyAccessResult $context, RegistryAdmissionRequest $request, AdmissionAttemptFacts $known): Outcome
    {
        // B. A committed admission of this key: replay, without reading the registry.
        $evidence = Evidence::forKey((int) $context->user->getKey(), $request->clientReference);
        if ($evidence !== null) {
            return $this->replay($context, $request, $evidence);
        }

        // C. The strict reference. An authentic but expired one may only find a committed admission.
        try {
            $ref = Refs::readForAdmission($request->ref, $context);
        } catch (RefException) {
            return $this->replayOrRefuse($context, $request, Refs::openForReplay($request->ref, $context));
        }
        $known->releaseId = $ref->releaseId;
        $known->reference = $ref;

        // D. The registry, outside the transaction (its own read-only session and timeouts).
        $candidates = $this->discovery->candidates($context);
        if ($candidates->releaseId !== $ref->releaseId) {
            throw new RefException(RefException::RELEASE_CHANGED);
        }
        if ($candidates->tooBroad) {
            throw Refused::notEligible(Audit::RESULT_SET_TOO_BROAD);
        }
        $candidate = $candidates->for($ref->childNationalId()) ?? throw Refused::notEligible(Reason::NOT_A_CHILD_OF_HEAD->value);

        // E. The atomic admission.
        try {
            return DB::transaction(fn () => $this->admitLocked($context, $request, $ref, $candidate));
        } catch (AdmissionReplayInstead $instead) {
            return $this->replay($context, $request, $instead->evidence);
        } catch (UniqueConstraintViolationException|DuplicateNationalIdException|ValidationException) {
            // A guard refused what the locks should have prevented. Same key committed meanwhile → replay.
            $evidence = Evidence::forKey((int) $context->user->getKey(), $request->clientReference);
            if ($evidence !== null) {
                return $this->replay($context, $request, $evidence);
            }
            throw Refused::notEligible(Audit::LOST_RACE);
        }
    }

    private function admitLocked(FamilyAccessResult $context, RegistryAdmissionRequest $request, RegistryAdmissionReference $ref, AdmissionCandidate $candidate): Outcome
    {
        $db = DB::connection();
        if ($db->getDriverName() === 'pgsql') {
            $db->statement("SET LOCAL lock_timeout = '3s'");
            $db->statement("SET LOCAL statement_timeout = '15s'");
        }

        // ① The release pin, on THIS connection: the release cannot change before the commit.
        (new PostgresRegistryReleasePin($db, $this->verifier))->pin($ref->releaseId);

        // ② The Family, then ③ the head's membership; the household context must still hold.
        $family = Family::query()->whereKey($context->family->getKey())->lockForUpdate()->first();
        $head = FamilyMembership::query()->whereKey($context->membership->getKey())->lock('for share')->first();
        if ($family === null || $family->status !== FamilyStatus::ACTIVE || $head === null || ! $head->is_active || ! $head->is_household_head
            || (int) $head->family_id !== (int) $family->getKey() || (int) $head->person_id !== (int) $context->person->getKey()
            || ! self::sameContext($context, app(FamilyAccessResolver::class)->familyContext($context->user))) {
            throw new Refused(Refused::REFERENCE_INVALID, Audit::CONTEXT_CHANGED);
        }

        // The same key may have committed while this request waited for the Family.
        if (($evidence = Evidence::forKey((int) $context->user->getKey(), $request->clientReference)) !== null) {
            throw new AdmissionReplayInstead($evidence);
        }

        // ④ The National ID lock: from here no other writer can create, attach or move this child.
        NationalIdGuard::lock($ref->childNationalId());

        // Eligibility again, under the locks (E-3 … E-9, D-06), with fresh reads of the registry.
        $admission = $this->admissibility->assess($context, $candidate->record, $candidate->sourceReasons);
        if (! $admission->admissible() || $admission->parentLink !== $ref->parentLink || $admission->relationship() === null) {
            throw Refused::notEligible($admission->admissible() ? Reason::PARENT_LINK_INDETERMINATE->value : $admission->refusalReason()->value);
        }

        // An active member anywhere is refused before ⑤ the Person lock (the same lock, re-entrant).
        $identity = FamilyMemberIdentity::resolve($ref->childNationalId());
        if ($identity->outcome->refused()) {
            throw Refused::notEligible(match ($identity->outcome) {
                FamilyMemberIdentityOutcome::AMBIGUOUS => Reason::AMBIGUOUS_HOLDERS->value,
                FamilyMemberIdentityOutcome::ACTIVE_MEMBERSHIP => Reason::ACTIVE_MEMBERSHIP_ELSEWHERE->value,
                default => Reason::PERSON_NOT_ATTACHABLE->value,
            });
        }

        $actorId = (int) $context->user->getKey();
        $created = $identity->outcome === FamilyMemberIdentityOutcome::NEW_PERSON;
        $membership = $created
            ? $this->add->handle($family, [
                'full_name' => $admission->fullName,
                'national_id' => $ref->childNationalId(),
                'gender' => $admission->gender->value,
                'marital_status' => $admission->maritalStatus->value,
                'birth_date' => $admission->birthDate,
                'relationship_type_id' => RelationshipTypes::required($admission->relationship()),
            ], $actorId)
            // The registry Person is attached as recorded: nothing about them is changed.
            : $this->attach->handle($family, $identity->person, $admission->relationship(), $actorId);

        $outcome = $created ? Audit::PERSON_CREATED : Audit::PERSON_ATTACHED;
        $reference = Audit::referenceFingerprint($ref->childNationalId());
        DB::table('family_registry_admissions')->insert([
            'uuid' => (string) Str::uuid(),
            'family_id' => $family->getKey(),
            'person_id' => $membership->person_id,
            'family_membership_id' => $membership->getKey(),
            'admitted_by' => $actorId,
            'admitted_by_person_id' => $context->person->getKey(),
            'client_reference' => $request->clientReference,
            'outcome_type' => $outcome,
            'parent_link' => $ref->parentLink,
            'reference_fingerprint' => $reference['fingerprint'],
            'reference_key_version' => $reference['key_version'],
            'release_id' => $ref->releaseId,
            'declaration_version' => $request->declarationVersion,
            'declared_at' => now(),
            'created_at' => now(),
        ]);
        Audit::admissionSucceeded($context->user, $family, $ref->releaseId, $outcome, $ref->childNationalId(), $request->ip);

        return new Outcome(HouseholdMemberReference::of((int) $family->getKey(), (int) $membership->getKey()), replayed: false);
    }

    /** An authentic but expired reference: only a committed admission of this key may answer it. */
    private function replayOrRefuse(FamilyAccessResult $context, RegistryAdmissionRequest $request, RegistryAdmissionReplayClaim $claim): Outcome
    {
        // Wait for an admission of this key that may be committing under the Family lock.
        $evidence = DB::transaction(function () use ($context, $request) {
            // Bounded like the admission itself: a long-held Family lock is a retryable 503, never an endless wait.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '3s'");
            }
            Family::query()->whereKey($context->family->getKey())->lockForUpdate()->first();

            return Evidence::forKey((int) $context->user->getKey(), $request->clientReference);
        });
        if ($evidence === null) {
            throw new RefException(RefException::REFERENCE_INVALID);
        }

        return $this->replay($context, $request, $evidence, $claim);
    }

    /** A committed admission of this key: the same request answers it again, and nothing else is written. */
    private function replay(FamilyAccessResult $context, RegistryAdmissionRequest $request, Evidence $evidence, ?RegistryAdmissionReplayClaim $claim = null): Outcome
    {
        $claim ??= Refs::openForReplay($request->ref, $context);
        if ($evidence->familyId !== (int) $context->family->getKey() || $evidence->admittedByPersonId !== (int) $context->person->getKey()
            || ! $claim->matchesEvidence($evidence, $request->declarationVersion)) {
            throw new Refused(Refused::ADMISSION_IDEMPOTENCY_CONFLICT, Audit::IDEMPOTENCY_CONFLICT);
        }
        Audit::replayedFromEvidence($context->user, $context->family, $evidence, $request->ip);

        $active = $evidence->familyMembershipId !== null && FamilyMembership::query()->whereKey($evidence->familyMembershipId)
            ->where('family_id', $evidence->familyId)->where('is_active', true)->exists();

        return new Outcome($active ? HouseholdMemberReference::of($evidence->familyId, $evidence->familyMembershipId) : null, replayed: true);
    }

    /** One refusal row, in its own short transaction: the admission transaction is already rolled back. */
    private function recordRefusal(FamilyAccessResult $context, RegistryAdmissionRequest $request, AdmissionAttemptFacts $known, ?string $code): void
    {
        if ($code === null) {
            return;
        }
        if ($code === Audit::IDEMPOTENCY_CONFLICT) {
            Audit::idempotencyConflict($context->user, $context->family, $request->ip);

            return;
        }
        Audit::admissionRefused($context->user, $context->family, $code, $known->releaseId, $known->reference?->childNationalId(), $request->ip);
    }

    /** The household context resolved again under the Family lock is the same one. */
    private static function sameContext(FamilyAccessResult $before, FamilyAccessResult $now): bool
    {
        return $now->hasFamilyContext()
            && (int) $now->user->getKey() === (int) $before->user->getKey()
            && (int) $now->person->getKey() === (int) $before->person->getKey()
            && (int) $now->family->getKey() === (int) $before->family->getKey()
            && (int) $now->membership->getKey() === (int) $before->membership->getKey()
            && (int) $now->link->getKey() === (int) $before->link->getKey()
            && UserPersonLink::query()->whereKey($now->link->getKey())->current()->exists();
    }

    /**
     * The admission's queries carry the child's National ID as a bound value. They must not
     * be captured by the query log or by a query listener (Telescope, Debugbar and the like):
     * if either is active, nothing is done (fail closed, 503).
     */
    private static function assertNoQueryCapture(): void
    {
        if (DB::connection()->logging() || app('events')->hasListeners(QueryExecuted::class)) {
            throw new LogicException('Query capture is active: no admission is run.');
        }
    }
}
