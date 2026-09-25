<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organizational grouping of one or more Branches inside a Clan
 * (docs/02 §5b). May be unnamed: an administrative container is then
 * displayed through its branches' names, never an invented name.
 */
class BranchGroup extends Model
{
    use HasUuids;

    protected $fillable = ['clan_id', 'code', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Its own name, or — for an unnamed container — its branches' names
     * joined, or null when it has none yet (the UI shows a neutral label).
     */
    public function displayName(): ?string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        $names = $this->branches->pluck('name')->filter()->values();

        return $names->isEmpty() ? null : $names->implode(' / ');
    }
}
