<?php

namespace App\Support\FamilyAuth;

use App\Enums\UserPersonLinkStatus;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\AccountSide;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Shared pieces of coordinator administration (docs/11 §8, §30a).
 */
final class Coordinators
{
    /**
     * Coordinator administration is a Staff act: an active Staff-side
     * account holding coordinator-scope.manage (SUPER_ADMIN, ADMINISTRATOR).
     * A family-side account — a Coordinator included — never passes, whatever
     * it holds.
     */
    public static function authorize(User $actor): void
    {
        if (! $actor->is_active || ! AccountSide::isStaff($actor) || ! $actor->can('coordinator-scope.manage')) {
            throw new AuthorizationException('لا تملك صلاحية إدارة المنسقين.');
        }
    }

    /**
     * The family-side account of a Person: the account of its ACTIVE or
     * SUSPENDED link, else of its most recent link (so a role can still be
     * removed after the link ended). NULL when the Person never activated.
     */
    public static function accountOf(Person $person): ?User
    {
        $link = UserPersonLink::query()
            ->where('person_id', $person->getKey())
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', [UserPersonLinkStatus::ACTIVE->value, UserPersonLinkStatus::SUSPENDED->value])
            ->orderByDesc('id')
            ->first();

        return $link?->user;
    }
}
