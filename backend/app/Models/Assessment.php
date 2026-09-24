<?php

namespace App\Models;

use App\Enums\AssessmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Family-level, point-in-time assessment (docs/02-DATA-DICTIONARY.md §26).
 * Written only by the assessment Domain Actions. A COMPLETED assessment is
 * a historical snapshot: the model itself refuses to change or delete it.
 */
class Assessment extends Model
{
    use HasUuids;

    protected $fillable = [
        'family_id',
        'assessment_date',
        'status',
        'general_notes',
        'created_by',
        'updated_by',
        'completed_at',
        'completed_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (Assessment $assessment) {
            if ($assessment->getRawOriginal('status') === AssessmentStatus::COMPLETED->value) {
                throw new LogicException('Completed assessments are immutable.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Assessments cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AssessmentStatus::class,
            'assessment_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /** The auto-generated UUID is a public key only; `id` stays the PK. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isDraft(): bool
    {
        return $this->status === AssessmentStatus::DRAFT;
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(AssessmentResult::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
