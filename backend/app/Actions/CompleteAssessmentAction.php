<?php

namespace App\Actions;

use App\Enums\AssessmentStatus;
use App\Enums\FamilyActivityType;
use App\Models\Assessment;
use App\Models\Family;
use App\Support\AssessmentDraft;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DRAFT → COMPLETED (docs/03-BUSINESS-RULES.md §40a, permission
 * assessment.complete). Optionally saves a final draft payload first, in
 * the same transaction, so "save and complete" is atomic. There is no way
 * back: a completed assessment is never reopened or edited in V1.
 */
class CompleteAssessmentAction
{
    /**
     * @param  array<string, mixed>  $data  Optional already-validated draft payload.
     */
    public function handle(Assessment $assessment, array $data, ?int $actingUserId): Assessment
    {
        return DB::transaction(function () use ($assessment, $data, $actingUserId) {
            $assessment = UpdateAssessmentAction::lockDraft($assessment);

            if ($data !== [] && AssessmentDraft::apply($assessment, $data, $actingUserId)) {
                FamilyActivityLog::record($assessment->family_id, FamilyActivityType::ASSESSMENT_UPDATED, $assessment, $actingUserId);
            }

            // The family must still exist (soft-deleted families are gone
            // from the registry).
            abort_unless(Family::whereKey($assessment->family_id)->exists(), 404);

            $results = $assessment->results()->with('domain')->get();

            if ($results->isEmpty()) {
                throw ValidationException::withMessages([
                    'results' => 'يجب تقييم مجال واحد على الأقل قبل إكمال التقييم.',
                ]);
            }

            // A draft keeps results for domains deactivated after they were
            // rated, but cannot be completed with them.
            $inactive = $results->filter(fn ($result) => ! $result->domain->is_active);
            if ($inactive->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'results' => 'لا يمكن إكمال التقييم لأنه يتضمن مجالات غير مفعّلة: '
                        .$inactive->pluck('domain.name')->implode('، ')
                        .'. أزل تقييم هذه المجالات أولًا.',
                ]);
            }

            $assessment->status = AssessmentStatus::COMPLETED;
            $assessment->completed_at = Carbon::now();
            $assessment->completed_by = $actingUserId;
            $assessment->updated_by = $actingUserId;
            $assessment->save();

            FamilyActivityLog::record($assessment->family_id, FamilyActivityType::ASSESSMENT_COMPLETED, $assessment, $actingUserId);

            return $assessment;
        });
    }
}
