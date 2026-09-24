<?php

namespace App\Actions;

use App\Enums\AssessmentStatus;
use App\Enums\FamilyActivityType;
use App\Models\Assessment;
use App\Models\Family;
use App\Support\AssessmentDraft;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Starts a new DRAFT family assessment (docs/03-BUSINESS-RULES.md §40a,
 * permission assessment.create). A family may have any number of
 * assessments, including several on the same assessment_date.
 */
class CreateAssessmentAction
{
    /**
     * @param  array<string, mixed>  $data  Already-validated payload (see StoreAssessmentRequest).
     */
    public function handle(Family $family, array $data, ?int $actingUserId): Assessment
    {
        return DB::transaction(function () use ($family, $data, $actingUserId) {
            $assessment = new Assessment([
                'family_id' => $family->id,
                'status' => AssessmentStatus::DRAFT,
                'created_by' => $actingUserId,
            ]);

            AssessmentDraft::apply($assessment, $data, $actingUserId);

            FamilyActivityLog::record($family->id, FamilyActivityType::ASSESSMENT_CREATED, $assessment, $actingUserId);

            return $assessment;
        });
    }
}
