<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\LifeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'notes',
        'is_active',
        'created_by',
        'updated_by',
    ];

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
}
