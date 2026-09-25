<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The large extended family / clan using Famboook, e.g. عائلة البريم
 * (docs/02-DATA-DICTIONARY.md §5a). NOT a Family: a Family is a household
 * that belongs to one Clan. Deactivated, never deleted.
 */
class Clan extends Model
{
    use HasUuids;

    public const AL_BREEM = 'AL_BREEM';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function branchGroups(): HasMany
    {
        return $this->hasMany(BranchGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function families(): HasMany
    {
        return $this->hasMany(Family::class);
    }
}
