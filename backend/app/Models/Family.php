<?php

namespace App\Models;

use App\Enums\CredentialStatus;
use App\Enums\FamilyStatus;
use App\Enums\RegistrationSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Family extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'family_code',
        'clan_id',
        'branch_id',
        'status',
        'registration_date',
        'registration_source',
        'paper_form_no',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => FamilyStatus::class,
            'registration_source' => RegistrationSource::class,
            'registration_date' => 'date',
        ];
    }

    /** The large extended family this household belongs to (required). */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /** Optional: NULL while the household's branch is unknown. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FamilyMembership::class);
    }

    public function residences(): HasMany
    {
        return $this->hasMany(FamilyResidence::class);
    }

    public function currentResidence(): HasOne
    {
        return $this->hasOne(FamilyResidence::class)->where('is_current', true);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(FamilyActivity::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function needs(): HasMany
    {
        return $this->hasMany(FamilyNeed::class);
    }

    /** Declared Household Statistics history (docs/02 §20a). */
    public function householdDeclarations(): HasMany
    {
        return $this->hasMany(FamilyHouseholdDeclaration::class);
    }

    /** The current declaration; the Registered Household Size is derived, never this. */
    public function currentHouseholdDeclaration(): HasOne
    {
        return $this->hasOne(FamilyHouseholdDeclaration::class)->where('is_current', true);
    }

    /** Digital Family Card history (docs/11 FP-ADR-070); append-only. */
    public function digitalCredentials(): HasMany
    {
        return $this->hasMany(DigitalCredential::class);
    }

    /** The ACTIVE Digital Family Card, if any (at most one). */
    public function activeDigitalCredential(): HasOne
    {
        return $this->hasOne(DigitalCredential::class)->where('status', CredentialStatus::ACTIVE->value);
    }

    public function householdHeadMembership(): HasOne
    {
        return $this->hasOne(FamilyMembership::class)
            ->where('is_household_head', true)
            ->where('is_active', true);
    }

    public function getRouteKeyName(): string
    {
        return 'family_code';
    }
}
