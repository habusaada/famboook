<?php

namespace App\Support;

use App\Enums\FamilyActivityType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Event-level visibility of Family Activity Log entries (docs/06 §57a).
 * Holding activity-log.view is not enough to see every event: the broad
 * fact that a health record, assessment, Need or nomination exists stays
 * behind that domain's view permission. Shared by the Family timeline and
 * the Operational Dashboard so neither can bypass the other.
 */
class FamilyActivityVisibility
{
    public static function apply(Builder $activities, User $user): Builder
    {
        $hidden = [];
        if (! $user->can('health-record.view')) {
            $hidden = [...$hidden, ...FamilyActivityType::healthCases()];
        }
        if (! $user->can('assessment.view')) {
            $hidden = [...$hidden, ...FamilyActivityType::assessmentCases()];
        }
        if (! $user->can('need.view')) {
            $hidden = [...$hidden, ...FamilyActivityType::needCases()];
        }
        if (! $user->can('assistance.view')) {
            $hidden = [...$hidden, ...FamilyActivityType::assistanceCases()];
        }

        return $activities->when(
            $hidden !== [],
            fn (Builder $q) => $q->whereNotIn('event_type', array_map(fn (FamilyActivityType $t) => $t->value, $hidden)),
        );
    }
}
