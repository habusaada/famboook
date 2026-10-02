<?php

namespace App\Models;

use App\Enums\CoordinatorScopeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One explicit, audited organizational scope of a coordinator (docs/02 §45b,
 * docs/04 §55b): a Clan, a Branch Group or a Branch. A coordinator may hold
 * several active assignments. Revoked, never deleted.
 *
 * Schema foundation only (PWA-1C): NOTHING authorizes by an assignment yet —
 * the coordinator scope service is PWA-1H. It is unrelated to
 * App\Support\Reporting\OrganizationalScope, which is a reporting filter.
 */
class CoordinatorScopeAssignment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'scope_type',
        'clan_id',
        'branch_group_id',
        'branch_id',
        'assigned_by',
        'assigned_at',
        'revoked_by',
        'revoked_at',
        'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => CoordinatorScopeType::class,
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** The auto-generated UUID is a public key only; `id` stays the PK. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    public function branchGroup(): BelongsTo
    {
        return $this->belongsTo(BranchGroup::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
