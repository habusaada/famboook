<?php

namespace App\Models;

use App\Enums\FamilyStatus;
use App\Enums\RegistrationSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Family extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'family_code',
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
