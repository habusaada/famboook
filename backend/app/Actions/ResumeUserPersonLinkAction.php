<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\UserPersonLinkStatus;
use App\Exceptions\FamilyIdentityException;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\AuthSecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Resumes a SUSPENDED User-Person Link (docs/05 §53b): SUSPENDED → ACTIVE.
 * Requires a Staff-side actor holding user-person-link.manage. Only a
 * suspension is resumable — an ENDED link is terminal.
 *
 * Resuming restores the link only. Eligibility is still decided by the
 * access resolver on every request, and no session is created: the user
 * signs in again.
 */
class ResumeUserPersonLinkAction
{
    public function handle(User $actor, UserPersonLink $link): UserPersonLink
    {
        SuspendUserPersonLinkAction::authorize($actor);

        return DB::transaction(function () use ($actor, $link) {
            /** @var UserPersonLink $link */
            $link = UserPersonLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();
            if ($link->status !== UserPersonLinkStatus::SUSPENDED) {
                throw new FamilyIdentityException(FamilyIdentityException::INVALID_TRANSITION);
            }

            // The suspension itself stays in auth_security_events.
            $link->forceFill([
                'status' => UserPersonLinkStatus::ACTIVE,
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ])->save();

            AuthSecurityLog::record(
                AuthSecurityEventType::LINK_RESUMED,
                AuthSecurityEventOutcome::SUCCESS,
                person: $link->person()->withTrashed()->first(), user: $link->user, actor: $actor, link: $link,
                metadata: ['status_from' => UserPersonLinkStatus::SUSPENDED->value, 'status_to' => UserPersonLinkStatus::ACTIVE->value],
            );

            return $link;
        });
    }
}
