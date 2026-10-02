<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\UserPersonLinkStatus;
use App\Enums\UserPersonLinkSuspensionReason;
use App\Exceptions\FamilyIdentityException;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\AccountSide;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilySessions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Suspends an ACTIVE User-Person Link (docs/05 §53b): ACTIVE → SUSPENDED,
 * resumable. Requires a Staff-side actor holding user-person-link.manage.
 *
 * The link keeps its slot (no second account can be activated around it),
 * the Family Auth Identity and the User account are NOT changed, and every
 * session of the user ends. Access stops because the resolver requires an
 * ACTIVE link.
 */
class SuspendUserPersonLinkAction
{
    public function handle(User $actor, UserPersonLink $link, UserPersonLinkSuspensionReason $reason): UserPersonLink
    {
        self::authorize($actor);

        return DB::transaction(function () use ($actor, $link, $reason) {
            /** @var UserPersonLink $link */
            $link = UserPersonLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();
            if ($link->status !== UserPersonLinkStatus::ACTIVE) {
                throw new FamilyIdentityException(FamilyIdentityException::INVALID_TRANSITION);
            }

            $link->forceFill([
                'status' => UserPersonLinkStatus::SUSPENDED,
                'suspended_at' => now(),
                'suspended_by' => $actor->getKey(),
                'suspension_reason' => $reason->value,
            ])->save();

            FamilySessions::revoke($link->user, $actor, $reason);
            AuthSecurityLog::record(
                AuthSecurityEventType::LINK_SUSPENDED,
                AuthSecurityEventOutcome::SUCCESS,
                $reason,
                person: $link->person()->withTrashed()->first(), user: $link->user, actor: $actor, link: $link,
                metadata: ['status_from' => UserPersonLinkStatus::ACTIVE->value, 'status_to' => UserPersonLinkStatus::SUSPENDED->value],
            );

            return $link;
        });
    }

    /** Link administration is a Staff-side capability, never a family-side one. */
    public static function authorize(User $actor): void
    {
        if (! $actor->is_active || ! AccountSide::isStaff($actor) || ! $actor->can('user-person-link.manage')) {
            throw new AuthorizationException('لا تملك صلاحية إدارة ربط حسابات بوابة الأسرة.');
        }
    }
}
