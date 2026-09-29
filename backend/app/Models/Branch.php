<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named family branch of a Clan (docs/02 §7c). The Clan is required; a
 * Branch Group is an optional organizational classification (NULL =
 * ungrouped). When grouped, the group belongs to the same Clan (composite
 * foreign key). clan_id lets a Family's Branch be checked against the
 * Family's Clan in the database.
 */
class Branch extends Model
{
    use HasUuids;

    protected $fillable = ['branch_group_id', 'clan_id', 'code', 'name', 'sort_order', 'is_active'];

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

    /** Optional: NULL while the branch is ungrouped. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(BranchGroup::class, 'branch_group_id');
    }

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    public function families(): HasMany
    {
        return $this->hasMany(Family::class);
    }

    /**
     * Selectable for a NEW assignment: the branch and its clan are active
     * and, when grouped, its group is active. An ungrouped branch needs no
     * group.
     */
    public function isSelectable(): bool
    {
        $this->loadMissing(['group', 'clan']);

        return $this->is_active
            && $this->clan->is_active
            && ($this->group === null || $this->group->is_active);
    }
}
