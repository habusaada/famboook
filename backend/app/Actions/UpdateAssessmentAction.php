<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\Assessment;
use App\Support\AssessmentDraft;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Saves changes to a DRAFT assessment (permission assessment.update). A
 * COMPLETED assessment is a historical snapshot and is refused with 409.
 */
class UpdateAssessmentAction
{
    /**
     * @param  array<string, mixed>  $data  Already-validated partial payload (see UpdateAssessmentRequest).
     */
    public function handle(Assessment $assessment, array $data, ?int $actingUserId): Assessment
    {
        return DB::transaction(function () use ($assessment, $data, $actingUserId) {
            $assessment = self::lockDraft($assessment);

            // A save that changes nothing is not an activity.
            if (AssessmentDraft::apply($assessment, $data, $actingUserId)) {
                FamilyActivityLog::record($assessment->family_id, FamilyActivityType::ASSESSMENT_UPDATED, $assessment, $actingUserId);
            }

            return $assessment;
        });
    }

    /**
     * Re-reads the assessment under a row lock, so two concurrent requests
     * cannot both act on a draft that one of them is completing.
     */
    public static function lockDraft(Assessment $assessment): Assessment
    {
        $assessment = Assessment::whereKey($assessment->getKey())->lockForUpdate()->firstOrFail();

        abort_unless($assessment->isDraft(), 409, 'هذا التقييم مكتمل ولا يمكن تعديله.');

        return $assessment;
    }
}
