<?php

namespace App\Models;

use App\Enums\GazaLocationLevel;
use App\Support\Geography\FamilyLandmarkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ramsey\Uuid\Uuid;

/**
 * A node of the official Gaza geographic reference (ADR-FB-GEO-01; source:
 * Palestinian Ministry of Social Development workbook). Written only by
 * ImportGazaGeographyAction; never edited by hand (Stage 1B G1, G10).
 *
 * The id is a deterministic UUID derived from (level, source_code), so the
 * same official record has the same id in every environment and a re-import
 * can never create a second row for it. `name` is the source value as
 * supplied — use displayName() for presentation (Stage 1B G5).
 */
class GazaLocation extends Model
{
    /** Fixed namespace for the deterministic ids — never change it. */
    public const ID_NAMESPACE = '6f1c2f2e-5d0b-4f6e-9a57-0c1b6a7d2b31';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'level', 'source_code', 'name', 'parent_id', 'parent_level',
        'landmark_type_code', 'landmark_type_name', 'is_active', 'sort_order',
    ];

    /**
     * FB-GEO-03: the internal batch-loaded flag of FamilyLandmarkType is never
     * serialized (toArray / toJson), whatever path renders a location.
     */
    protected $hidden = [FamilyLandmarkType::BLOCKED_ATTRIBUTE];

    protected function casts(): array
    {
        return [
            'level' => GazaLocationLevel::class,
            'parent_level' => GazaLocationLevel::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function idFor(GazaLocationLevel $level, string $sourceCode): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, "gaza-location:{$level->value}:{$sourceCode}")->toString();
    }

    /** Presentation only: leading/trailing spaces removed; the stored value is unchanged. */
    public function displayName(): string
    {
        return trim($this->name);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function reviewFlags(): HasMany
    {
        return $this->hasMany(GazaLocationReviewFlag::class);
    }
}
