<?php

namespace App\Models;

use App\Enums\DisplacementStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyResidence extends Model
{
    use HasFactory;

    protected $fillable = [
        'family_id',
        'residence_type',
        'governorate',
        'city',
        'area',
        'neighborhood',
        'address_text',
        'original_residence_text',
        // FB-GEO-01 Stage 3.2: structured references (nullable, beside the text).
        'original_location_id',
        'current_location_id',
        'latitude',
        'longitude',
        'displacement_status',
        'displacement_location_text',
        'started_at',
        'ended_at',
        'is_current',
        'source',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'displacement_status' => DisplacementStatus::class,
            'started_at' => 'date',
            'ended_at' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** The residence before displacement (ADR-FB-GEO-01 §5). */
    public function originalLocation(): BelongsTo
    {
        return $this->belongsTo(GazaLocation::class, 'original_location_id');
    }

    /** Where the Family lives now — the displacement location when DISPLACED. */
    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(GazaLocation::class, 'current_location_id');
    }
}
