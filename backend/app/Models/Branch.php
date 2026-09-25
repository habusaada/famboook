<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named family branch within a Branch Group (docs/02 §5c). clan_id
 * mirrors the group's Clan (enforced by a composite foreign key) so that a
 * Family's Branch can be checked against the Family's Clan in the database.
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

    /** Selectable for a NEW assignment: the branch, its group and its clan are all active. */
    public function isSelectable(): bool
    {
        $this->loadMissing(['group', 'clan']);

        return $this->is_active && $this->group->is_active && $this->clan->is_active;
    }
}
