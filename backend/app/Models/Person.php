<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\LifeStatus;
use App\Enums\MaritalStatus;
use App\Support\HealthRecordRules;
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
        'marital_status',
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

            // Defense in depth for any write path (UpdatePersonRequest
            // reports the same rule as a field error first).
            if ($person->exists && $person->isDirty('gender') && $person->gender instanceof Gender) {
                HealthRecordRules::assertGenderChangeAllowed($person, $person->gender);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'marital_status' => MaritalStatus::class,
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

    public function healthRecords(): HasMany
    {
        return $this->hasMany(PersonHealthRecord::class);
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
