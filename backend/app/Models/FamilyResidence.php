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
}
