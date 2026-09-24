<?php

namespace App\Models;

use App\Enums\AssistanceStatus;
use App\Enums\AssistanceType;
use App\Enums\BeneficiaryStatus;
use App\Enums\ExecutionMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An assistance program/campaign (docs/02-DATA-DICTIONARY.md §36a): what
 * can be provided, to whom it is targeted, and who is nominated. It is not
 * a delivery record. Written only by the assistance Domain Actions.
 */
class Assistance extends Model
{
    use HasUuids;

    protected $fillable = [
        'title',
        'assistance_category_id',
        'assistance_type',
        'execution_mode',
        'provider_name',
        'target_beneficiaries',
        'start_date',
        'end_date',
        'description',
        'status',
        'targeting_criteria',
        'export_fields',
        'opened_at',
        'opened_by',
        'completed_at',
        'completed_by',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Assistances cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AssistanceStatus::class,
            'assistance_type' => AssistanceType::class,
            'execution_mode' => ExecutionMode::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'targeting_criteria' => 'array',
            'export_fields' => 'array',
            'opened_at' => 'datetime',
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
        return $this->status === AssistanceStatus::DRAFT;
    }

    public function isOpen(): bool
    {
        return $this->status === AssistanceStatus::OPEN;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssistanceCategory::class, 'assistance_category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssistanceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(AssistanceBeneficiary::class);
    }

    /** Current (non-removed) nominations. */
    public function currentNominees(): HasMany
    {
        return $this->beneficiaries()->where('status', '!=', BeneficiaryStatus::REMOVED);
    }

    public function isInternal(): bool
    {
        return $this->execution_mode === ExecutionMode::INTERNAL;
    }

    public function isExternal(): bool
    {
        return $this->execution_mode === ExecutionMode::EXTERNAL;
    }

    public function beneficiaryLists(): HasMany
    {
        return $this->hasMany(AssistanceBeneficiaryList::class)->latest('issued_at')->latest('id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
