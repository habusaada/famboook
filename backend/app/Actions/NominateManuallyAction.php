<?php

namespace App\Actions;

use App\Enums\NominationSource;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\Family;
use App\Models\Person;
use App\Support\AssistanceNomination;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "إضافة مرشح يدويًا" (permission assistance.nominate): a whole family, or
 * one ACTIVE member of it. nomination_source = MANUAL.
 */
class NominateManuallyAction
{
    public function handle(Assistance $assistance, string $familyCode, ?string $personCode, ?int $actingUserId): AssistanceBeneficiary
    {
        return DB::transaction(function () use ($assistance, $familyCode, $personCode, $actingUserId) {
            $assistance = AssistanceNomination::lockOpen($assistance);

            $family = Family::where('family_code', $familyCode)->first();
            if ($family === null) {
                throw ValidationException::withMessages(['family_code' => 'الأسرة غير موجودة.']);
            }

            $personId = null;
            if ($personCode !== null) {
                $personId = Person::query()
                    ->where('person_code', $personCode)
                    ->whereHas('activeMembership', fn ($q) => $q->where('family_id', $family->id))
                    ->value('id');

                if ($personId === null) {
                    throw ValidationException::withMessages([
                        'person_code' => 'الشخص المحدد ليس فردًا نشطًا في هذه الأسرة.',
                    ]);
                }
            }

            $nominee = AssistanceNomination::add(
                $assistance, $family->id, $personId, NominationSource::MANUAL, null, null, $actingUserId
            );

            if ($nominee === null) {
                throw ValidationException::withMessages([
                    $personId ? 'person_code' : 'family_code' => $personId
                        ? 'هذا الشخص مرشّح مسبقًا لهذه المساعدة.'
                        : 'هذه الأسرة مرشّحة مسبقًا لهذه المساعدة.',
                ]);
            }

            return $nominee;
        });
    }
}
