<?php

namespace Tests\Support\ChangeRequests;

use App\Actions\CreateFamilyAction;
use App\Enums\ProfileReviewSection;
use App\Enums\RegistrationSource;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestPresentation;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\ChangeRequests\ProposesNewFamily;
use App\Support\FamilyAuth\FamilyAccessResult;
use LogicException;
use RuntimeException;

/**
 * TEST-ONLY handler for a request that proposes a NEW Family (CO-02.3b). It
 * exists only under tests/ and is registered only through
 * ChangeRequestTypes::fake() for REGISTER_FAMILY — the real REGISTER_FAMILY
 * handler is a later phase. It proves the engine's family-less approve /
 * APPLY path end to end against a real canonical write: applyProposal()
 * creates the Family through the existing CreateFamilyAction (placement from
 * the request, registration_source COORDINATOR_REQUEST).
 *
 * Switches (reset()): a failed precondition, an exception AFTER the Family
 * was created (must roll it back), a changed base.
 */
final class FakeProposedFamilyHandler implements ChangeRequestHandler, ProposesNewFamily
{
    public static bool $preconditionFails = false;

    public static bool $failAfterCreate = false;

    public static string $base = 'v1';

    public static int $applied = 0;

    public static function reset(): void
    {
        self::$preconditionFails = false;
        self::$failAfterCreate = false;
        self::$base = 'v1';
        self::$applied = 0;
    }

    public function familySubmittable(): bool
    {
        return false;
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    public function inputRules(Family $family): array
    {
        return [];
    }

    public function dataRules(Family $family): array
    {
        return [];
    }

    public function resolveTarget(FamilyAccessResult $context, array $validated): ChangeRequestTarget
    {
        throw new LogicException('A proposed Family has no target before APPLY.');
    }

    public function normalize(array $validated, ChangeRequestTarget $target): array
    {
        throw new LogicException('A proposed Family has no target before APPLY.');
    }

    public function baseValues(ChangeRequestTarget $target, array $data): array
    {
        throw new LogicException('A proposed Family has no target before APPLY.');
    }

    public function preconditions(ChangeRequestTarget $target, array $data): void
    {
        throw new LogicException('A proposed Family has no target before APPLY.');
    }

    public function apply(ChangeRequest $request, ChangeRequestTarget $target, int $actingUserId): void
    {
        throw new LogicException('A proposed Family is applied by applyProposal().');
    }

    public function present(ChangeRequest $request, ChangeRequestPresentationContext $context): array
    {
        return ChangeRequestPresentation::make()->row('الأسرة المقترحة', null, 'أسرة جديدة')->toArray();
    }

    /** @return list<ProfileReviewSection> */
    public function profileSections(): array
    {
        return [];
    }

    public function openConflictKey(ChangeRequestTarget $target, array $data): ?string
    {
        return null;
    }

    public function proposalRules(): array
    {
        return ['head' => ['required', 'array'], 'head.full_name' => ['required', 'string', 'max:255']];
    }

    public function normalizeProposal(array $validated): array
    {
        return ['head' => ['full_name' => trim((string) $validated['head']['full_name'])]];
    }

    public function proposalOpenConflictKey(array $data): ?string
    {
        return null;
    }

    public function proposalPreconditions(ChangeRequest $request): void
    {
        if (self::$preconditionFails) {
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        }
    }

    public function proposalBaseValues(ChangeRequest $request): array
    {
        return ['base' => self::$base];
    }

    public function assertProposalApprovable(ChangeRequest $request, ChangeRequestApprovalEvidence $evidence): void {}

    public function applyProposal(ChangeRequest $request, int $actingUserId): Family
    {
        $family = app(CreateFamilyAction::class)->handle([
            'clan_code' => $request->proposedClan->code,
            'branch_code' => $request->proposedBranch?->code,
            'registration_date' => now()->toDateString(),
            'registration_source' => RegistrationSource::COORDINATOR_REQUEST->value,
        ], $actingUserId);
        if (self::$failAfterCreate) {
            throw new RuntimeException('Simulated failure after the Family was created.');
        }
        self::$applied++;

        return $family;
    }
}
