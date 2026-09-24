<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FamilyActivityType;
use App\Http\Controllers\Controller;
use App\Http\Resources\FamilyActivityResource;
use App\Models\Family;
use App\Models\PersonHealthRecord;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only Family Activity Log (docs/06-PERMISSIONS.md §57a, permission
 * activity-log.view). There are deliberately no write endpoints: entries
 * are created only by Domain Actions.
 */
class FamilyActivityController extends Controller
{
    public function index(Request $request, Family $family): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 50);

        $activities = $family->activities()
            // Even the broad fact that a member has a health record stays
            // behind health-record.view.
            ->when(
                ! $request->user()->can('health-record.view'),
                fn ($q) => $q->whereNotIn(
                    'event_type',
                    array_map(fn (FamilyActivityType $t) => $t->value, FamilyActivityType::healthCases()),
                ),
            )
            // Likewise, assessment events stay behind assessment.view.
            ->when(
                ! $request->user()->can('assessment.view'),
                fn ($q) => $q->whereNotIn(
                    'event_type',
                    array_map(fn (FamilyActivityType $t) => $t->value, FamilyActivityType::assessmentCases()),
                ),
            )
            ->with([
                'actor:id,name',
                'subject' => fn (MorphTo $morph) => $morph->morphWith([
                    PersonHealthRecord::class => ['person'],
                ]),
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return FamilyActivityResource::collection($activities);
    }
}
