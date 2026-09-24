<?php

namespace App\Models;

use App\Enums\NeedPriority;
use App\Enums\NeedStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A concrete Need of a Family, optionally targeting one of its members
 * (docs/02-DATA-DICTIONARY.md §34). Written only by the Need Domain
 * Actions. Once FULFILLED or CLOSED it is historical: the model refuses to
 * change or delete it.
 */
class FamilyNeed extends Model
{
    use HasUuids;

    protected $fillable = [
        'family_id',
        'person_id',
        'source_assessment_id',
        'need_category_id',
        'title',
        'description',
        'priority',
        'quantity',
        'unit',
        'status',
        'resolved_at',
        'resolved_by',
        'closure_reason',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (FamilyNeed $need) {
            if ($need->getRawOriginal('status') !== NeedStatus::OPEN->value) {
                throw new LogicException('Resolved needs are immutable.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Needs cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => NeedStatus::class,
            'priority' => NeedPriority::class,
            'quantity' => 'decimal:2',
            'resolved_at' => 'datetime',
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

    public function isOpen(): bool
    {
        return $this->status === NeedStatus::OPEN;
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function sourceAssessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'source_assessment_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(NeedCategory::class, 'need_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
