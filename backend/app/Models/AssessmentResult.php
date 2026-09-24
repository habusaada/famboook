<?php

namespace App\Models;

use App\Enums\AssessmentRating;
use App\Enums\AssessmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The rating of one assessment domain within one Assessment. Absence of a
 * row means the domain was not assessed. Rows of a COMPLETED assessment
 * can no longer be created, changed or removed.
 */
class AssessmentResult extends Model
{
    protected $fillable = [
        'assessment_id',
        'assessment_domain_id',
        'rating',
        'notes',
    ];

    protected static function booted(): void
    {
        $guard = function (AssessmentResult $result) {
            // Eloquent value() applies the enum cast.
            $status = Assessment::whereKey($result->assessment_id)->value('status');
            if ($status === AssessmentStatus::COMPLETED) {
                throw new LogicException('Results of a completed assessment are immutable.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'rating' => AssessmentRating::class,
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(AssessmentDomain::class, 'assessment_domain_id');
    }
}
