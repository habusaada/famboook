<?php

namespace App\Models;

use App\Enums\RegistrationSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Declared Household Statistics (docs/02 §20a): household size and living
 * sons/daughters as declared by a source at a point in time. Declarations
 * only — never Persons, never the Registered Household Size (which is
 * derived from active memberships), never a replacement for registered
 * SON/DAUGHTER counts. Written through RecordHouseholdDeclarationAction.
 */
class FamilyHouseholdDeclaration extends Model
{
    use HasFactory;

    protected $fillable = [
        'family_id',
        'declared_household_size',
        'declared_living_sons',
        'declared_living_daughters',
        'declared_at',
        'source',
        'is_current',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'declared_household_size' => 'integer',
            'declared_living_sons' => 'integer',
            'declared_living_daughters' => 'integer',
            'declared_at' => 'date',
            'source' => RegistrationSource::class,
            'is_current' => 'boolean',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }
}
