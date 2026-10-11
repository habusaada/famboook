<?php

namespace App\Actions\ContactUpdate;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\ContactUpdateDenial;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Models\AuthOtpChallenge;
use App\Models\ChangeRequest;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use App\Support\FamilyAuth\AuthSecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * The SYSTEM closes a CONTACT_UPDATE that can no longer be verified (docs/11
 * FP-ADR-079): its window passed, a code was locked or became unusable, the
 * registry changed under it, or a newer request for the same member
 * replaced it. SUBMITTED → CANCELLED by SYSTEM (no canceller), its open
 * codes superseded, and CONTACT_MOBILE_CHANGED DENIED with the internal
 * reason. Nothing in the registry changes. Idempotent: a request that is no
 * longer SUBMITTED is left as it is.
 *
 * Its own small transaction — call it after the failed work rolled back.
 */
class CloseContactUpdateAction
{
    public function handle(ChangeRequest $request, ContactUpdateDenial $reason): bool
    {
        return DB::transaction(function () use ($request, $reason) {
            $locked = ChangeRequestWorkflow::lock($request);
            if ($locked->type !== ChangeRequestType::CONTACT_UPDATE || $locked->status !== ChangeRequestStatus::SUBMITTED) {
                return false;
            }

            $locked->forceFill(['status' => ChangeRequestStatus::CANCELLED, 'cancelled_by' => null, 'cancelled_at' => now()])->save();
            WorkflowEventRecorder::record($locked, ChangeRequestStatus::SUBMITTED, WorkflowEventType::CANCELLED, WorkflowActorSide::SYSTEM, null);
            AuthOtpChallenge::supersedeOpenForChangeRequest($locked->getKey());

            AuthSecurityLog::record(
                AuthSecurityEventType::CONTACT_MOBILE_CHANGED,
                AuthSecurityEventOutcome::DENIED,
                $reason,
                person: Person::withTrashed()->find($locked->person_id),
                user: User::find($locked->submitted_by),
                metadata: ['self' => (bool) ($locked->submitted_data['self'] ?? false)],
            );

            return true;
        });
    }

    /**
     * Closes every open CONTACT_UPDATE of one member of one Family — a newer
     * request replaces them (SUPERSEDED). Returns how many were closed.
     */
    public function closeOpenFor(FamilyMembership $membership, ContactUpdateDenial $reason = ContactUpdateDenial::SUPERSEDED): int
    {
        $closed = 0;
        ChangeRequest::query()
            ->where('family_id', $membership->family_id)
            ->where('target_membership_id', $membership->getKey())
            ->where('type', ChangeRequestType::CONTACT_UPDATE->value)
            ->where('status', ChangeRequestStatus::SUBMITTED->value)
            ->orderBy('id')
            ->get()
            ->each(function (ChangeRequest $open) use ($reason, &$closed) {
                $closed += $this->handle($open, $reason) ? 1 : 0;
            });

        return $closed;
    }

    /** Closes every CONTACT_UPDATE whose verification window passed. */
    public function closeExpired(): int
    {
        $closed = 0;
        ChangeRequest::query()
            ->where('type', ChangeRequestType::CONTACT_UPDATE->value)
            ->where('status', ChangeRequestStatus::SUBMITTED->value)
            ->where('submitted_at', '<=', now()->subSeconds(self::ttl()))
            ->orderBy('id')
            ->get()
            ->each(function (ChangeRequest $open) use (&$closed) {
                $closed += $this->handle($open, ContactUpdateDenial::EXPIRED) ? 1 : 0;
            });

        return $closed;
    }

    public static function expired(ChangeRequest $request): bool
    {
        return $request->submitted_at === null || now()->greaterThanOrEqualTo($request->submitted_at->copy()->addSeconds(self::ttl()));
    }

    private static function ttl(): int
    {
        return (int) config('family_auth.contact_update.request_ttl_seconds');
    }
}
