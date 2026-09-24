<?php

namespace App\Models;

use App\Enums\HealthRecordType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Person-based health record (docs/02-DATA-DICTIONARY.md §22). Active while
 * ended_at is NULL; closed, never hard-deleted, in V1.
 */
class PersonHealthRecord extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'person_id',
        'type',
        'disability_type_id',
        'condition_name',
        'details',
        'started_at',
        'ended_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => HealthRecordType::class,
            'started_at' => 'date',
            'ended_at' => 'date',
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

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function disabilityType(): BelongsTo
    {
        return $this->belongsTo(DisabilityType::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }
}
