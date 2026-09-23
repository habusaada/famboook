<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\LifeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Person extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'persons';

    protected $fillable = [
        'person_code',
        'full_name',
        'national_id',
        'gender',
        'birth_date',
        'life_status',
        'death_date',
        'mobile',
        'alternate_mobile',
        'alternate_mobile_owner_relation',
        'notes',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        // The owner/relation describes the alternate mobile; it has no
        // meaning once that number is removed, whichever route removes it.
        static::saving(function (Person $person) {
            if (blank($person->alternate_mobile)) {
                $person->alternate_mobile_owner_relation = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'life_status' => LifeStatus::class,
            'birth_date' => 'date',
            'death_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FamilyMembership::class);
    }

    public function activeMembership(): HasOne
    {
        return $this->hasOne(FamilyMembership::class)->where('is_active', true);
    }

    public function getRouteKeyName(): string
    {
        return 'person_code';
    }
}
