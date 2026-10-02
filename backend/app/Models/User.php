<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\AccountSide;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Filament (System / High Administration) access: an active account
     * holding system-admin.access (docs/06 §63, AUTH-ADR-057). Filament
     * checks it on login and on every panel request.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && AccountSide::isStaff($this) && $this->can('system-admin.access');
    }

    // Family Portal identity (docs/04 §55b). Relationships only: a Staff
    // user has none of these, and none of them grants access by itself.

    public function personLinks(): HasMany
    {
        return $this->hasMany(UserPersonLink::class);
    }

    public function familyAuthIdentities(): HasMany
    {
        return $this->hasMany(FamilyAuthIdentity::class);
    }

    public function coordinatorScopeAssignments(): HasMany
    {
        return $this->hasMany(CoordinatorScopeAssignment::class);
    }
}
