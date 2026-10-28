<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The individual Civil Registry Lookup override of one user (FB-CIVIL-02A,
 * ADR §L-3): REVOKED blocks the user whatever their role; GRANTED is reserved
 * for the family side (02B). No row = the role decides.
 */
class CivilLookupUserAccess extends Model
{
    public const GRANTED = 'GRANTED';

    public const REVOKED = 'REVOKED';

    public const UPDATED_AT = null;

    public const CREATED_AT = 'changed_at';

    public $incrementing = false;

    protected $table = 'civil_lookup_user_access';

    protected $primaryKey = 'user_id';

    protected $fillable = ['user_id', 'access', 'changed_by', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
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
