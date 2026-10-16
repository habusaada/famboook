<?php

namespace App\Models;

use App\Enums\GazaLocationReviewIssue;
use App\Enums\GazaLocationReviewSeverity;
use App\Enums\GazaLocationReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A review finding on a Gaza location (Stage 1B decision register Q2). It
 * never alters the location: flagged records stay as supplied and stay
 * selectable (Q1). One flag per (location, issue).
 */
class GazaLocationReviewFlag extends Model
{
    use HasUuids;

    protected $fillable = [
        'gaza_location_id', 'issue_code', 'severity', 'source_reference', 'review_status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_code' => GazaLocationReviewIssue::class,
            'severity' => GazaLocationReviewSeverity::class,
            'review_status' => GazaLocationReviewStatus::class,
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(GazaLocation::class, 'gaza_location_id');
    }
}
