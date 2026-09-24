<?php

namespace App\Support;

use App\Enums\AssistanceStatus;
use App\Enums\BeneficiaryStatus;
use App\Enums\FamilyActivityType;
use App\Enums\NominationSource;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\FamilyNeed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single writer of nominations (docs/03-BUSINESS-RULES.md §47c), used
 * by the manual, need-based and targeting-based nomination actions inside
 * their transaction. Records ASSISTANCE_NOMINEE_ADDED on the nominated
 * family's timeline, with no metadata.
 */
class AssistanceNomination
{
    /**
     * Re-reads the Assistance under a row lock (serializing concurrent
     * nominations) and requires it to be OPEN.
     */
    public static function lockOpen(Assistance $assistance): Assistance
    {
        $assistance = Assistance::whereKey($assistance->getKey())->lockForUpdate()->firstOrFail();

        abort_unless(
            $assistance->status === AssistanceStatus::OPEN,
            409,
            'يمكن إدارة المرشحين فقط عندما تكون المساعدة مفتوحة.'
        );

        return $assistance;
    }

    /** Whether a current (non-removed) nomination already covers this target. */
    public static function exists(Assistance $assistance, int $familyId, ?int $personId): bool
    {
        return AssistanceBeneficiary::query()
            ->where('assistance_id', $assistance->id)
            ->where('status', '!=', BeneficiaryStatus::REMOVED)
            ->when(
                $personId === null,
                fn ($q) => $q->whereNull('person_id')->where('family_id', $familyId),
                fn ($q) => $q->where('person_id', $personId),
            )
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $criteria  Targeting snapshot (TARGETING only).
     * @return AssistanceBeneficiary|null null when the target is already nominated.
     */
    public static function add(
        Assistance $assistance,
        int $familyId,
        ?int $personId,
        NominationSource $source,
        ?FamilyNeed $need,
        ?array $criteria,
        ?int $actingUserId,
    ): ?AssistanceBeneficiary {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Nominations must be written inside the Domain Action transaction.');
        }

        if (self::exists($assistance, $familyId, $personId)) {
            return null;
        }

        $nominee = AssistanceBeneficiary::create([
            'assistance_id' => $assistance->id,
            'family_id' => $familyId,
            'person_id' => $personId,
            'source_need_id' => $need?->id,
            'nomination_source' => $source,
            'targeting_criteria' => $source === NominationSource::TARGETING ? $criteria : null,
            'status' => BeneficiaryStatus::NOMINATED,
            'nominated_at' => Carbon::now(),
            'nominated_by' => $actingUserId,
        ]);

        FamilyActivityLog::record($familyId, FamilyActivityType::ASSISTANCE_NOMINEE_ADDED, $nominee, $actingUserId);

        return $nominee;
    }
}
