<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The individual Civil Registry Lookup override of one user (FB-CIVIL-02A,
 * ADR §L-3): REVOKED blocks the user whatever their role, on every side. No
 * row = the role decides (Staff side only).
 *
 * FB-CIVIL-02B (ADR 02B §B-4): GRANTED is the explicit individual grant a
 * family-side context requires, for the sides it names (`sides`: FAMILY,
 * COORDINATOR or both). It never overrides a gate, a permission, a role
 * policy or the field profile.
 */
class CivilLookupUserAccess extends Model
{
    public const GRANTED = 'GRANTED';

    public const REVOKED = 'REVOKED';

    /** The family-side contexts a GRANTED row may name, in their canonical order. */
    public const SIDES = ['FAMILY', 'COORDINATOR'];

    public const UPDATED_AT = null;

    public const CREATED_AT = 'changed_at';

    public $incrementing = false;

    protected $table = 'civil_lookup_user_access';

    protected $primaryKey = 'user_id';

    protected $fillable = ['user_id', 'access', 'sides', 'changed_by', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    /** Whether this row explicitly grants the family-side context $context. */
    public function grants(string $context): bool
    {
        return $this->access === self::GRANTED && in_array($context, self::sideList($this->sides), true);
    }

    /** @return list<string> */
    public static function sideList(?string $sides): array
    {
        return $sides === null || $sides === '' ? [] : explode(',', $sides);
    }

    /** @param  list<string>  $sides  canonical order, comma-separated; NULL when empty */
    public static function sideValue(array $sides): ?string
    {
        $ordered = array_values(array_intersect(self::SIDES, $sides));

        return $ordered === [] ? null : implode(',', $ordered);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
