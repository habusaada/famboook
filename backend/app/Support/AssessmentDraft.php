<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\AssessmentDomain;
use App\Models\AssessmentResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Applies a validated draft payload to a DRAFT Assessment
 * (docs/03-BUSINESS-RULES.md §40a). Shared by the create, update and
 * complete Domain Actions; always called inside their transaction.
 *
 * `results`, when present, is the complete result set: submitted domains
 * are added or changed, and previously saved domains that are omitted are
 * removed (they become "not assessed"). When absent, results are untouched.
 */
class AssessmentDraft
{
    /**
     * @param  array{
     *     assessment_date?: string,
     *     general_notes?: string|null,
     *     results?: list<array{domain_code: string, rating: string, notes?: string|null}>,
     * }  $data  Already-validated payload (see AssessmentRules::draft()).
     * @return bool Whether anything actually changed.
     */
    public static function apply(Assessment $assessment, array $data, ?int $actingUserId): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Assessment drafts must be saved inside the Domain Action transaction.');
        }

        if (! $assessment->isDraft()) {
            throw new LogicException('Only DRAFT assessments can be changed.');
        }

        $assessment->fill(array_intersect_key($data, array_flip(['assessment_date', 'general_notes'])));
        $changed = ! $assessment->exists || $assessment->isDirty(['assessment_date', 'general_notes']);

        if ($changed) {
            $assessment->updated_by = $actingUserId;
            $assessment->save();
        }

        if (array_key_exists('results', $data) && self::syncResults($assessment, $data['results'])) {
            $changed = true;
            // A result-only change still marks the draft as edited.
            $assessment->updated_by = $actingUserId;
            $assessment->touch();
        }

        return $changed;
    }

    /**
     * @param  list<array{domain_code: string, rating: string, notes?: string|null}>  $submitted
     */
    private static function syncResults(Assessment $assessment, array $submitted): bool
    {
        $existing = $assessment->results()->get()->keyBy('assessment_domain_id');
        $domains = AssessmentDomain::whereIn('code', array_column($submitted, 'domain_code'))
            ->get()
            ->keyBy('code');

        $changed = false;
        $keep = [];

        foreach ($submitted as $index => $item) {
            $domain = $domains[$item['domain_code']];
            $result = $existing->get($domain->id);

            // An inactive domain may not be newly added. A result that
            // already uses it is kept (never silently dropped); completion
            // is blocked until it is removed or the domain is reactivated.
            if (! $domain->is_active && $result === null) {
                throw ValidationException::withMessages([
                    "results.{$index}.domain_code" => "مجال التقييم «{$domain->name}» غير مفعّل ولا يمكن إضافته.",
                ]);
            }

            $result ??= new AssessmentResult([
                'assessment_id' => $assessment->id,
                'assessment_domain_id' => $domain->id,
            ]);

            $result->fill([
                'rating' => $item['rating'],
                'notes' => $item['notes'] ?? null,
            ]);

            if (! $result->exists || $result->isDirty()) {
                $result->save();
                $changed = true;
            }

            $keep[] = $domain->id;
        }

        foreach ($existing as $domainId => $result) {
            if (! in_array($domainId, $keep, true)) {
                $result->delete();
                $changed = true;
            }
        }

        return $changed;
    }
}
