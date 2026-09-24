<?php

namespace App\Models;

use App\Enums\FamilyActivityType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One entry of the Family Activity Log (docs/02-DATA-DICTIONARY.md §61a).
 * System-generated and append-only: written only through
 * App\Support\FamilyActivityLog from inside a Domain Action, never
 * updated or deleted.
 */
class FamilyActivity extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'family_id',
        'actor_user_id',
        'event_type',
        'subject_type',
        'subject_id',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Family activity records are immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Family activity records cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'event_type' => FamilyActivityType::class,
            'metadata' => 'array',
        ];
    }

    /** The auto-generated UUID is a public key only; `id` stays the PK. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
