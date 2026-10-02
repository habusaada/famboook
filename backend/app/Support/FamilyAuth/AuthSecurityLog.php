<?php

namespace App\Support\FamilyAuth;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Models\AuthSecurityEvent;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Models\UserPersonLink;
use BackedEnum;
use InvalidArgumentException;

/**
 * The only application writer of auth_security_events (docs/11 §30a,
 * docs/04 §55b). Inputs are typed: an event, an outcome, an optional reason
 * ENUM and references to records — never free text. An identifier is
 * accepted only as a keyed fingerprint, and metadata only as the model's
 * allow-listed codes and small counters, so a raw National ID, a raw mobile,
 * an OTP or a password cannot be recorded.
 *
 * Called from a Domain Action inside its transaction when the event belongs
 * to a state change, so the event commits or rolls back with it. Reading
 * (the resolver) never records anything.
 */
final class AuthSecurityLog
{
    /**
     * @param  array<string, bool|int|string>  $metadata
     */
    public static function record(
        AuthSecurityEventType $type,
        AuthSecurityEventOutcome $outcome,
        ?BackedEnum $reason = null,
        ?Person $person = null,
        ?User $user = null,
        User|int|null $actor = null,
        ?UserPersonLink $link = null,
        ?PersonMobileTrust $trust = null,
        ?string $otpChallengeUuid = null,
        ?string $loginKey = null,
        array $metadata = [],
    ): AuthSecurityEvent {
        if ($loginKey !== null && preg_match('/\A[0-9a-f]{64}\z/', $loginKey) !== 1) {
            throw new InvalidArgumentException('A security event accepts an identifier only as a keyed fingerprint.');
        }
        $reasonCode = $reason?->value;
        if ($reasonCode !== null && (! is_string($reasonCode) || preg_match('/\A[A-Z][A-Z0-9_]{1,59}\z/', $reasonCode) !== 1)) {
            throw new InvalidArgumentException('A security event reason must be a code.');
        }

        $request = app()->bound('request') ? request() : null;
        $agent = $request?->userAgent();

        return AuthSecurityEvent::create([
            'event_type' => $type,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'person_id' => $person?->getKey(),
            'user_id' => $user?->getKey(),
            'actor_user_id' => $actor instanceof User ? $actor->getKey() : $actor,
            'user_person_link_id' => $link?->getKey(),
            'mobile_trust_id' => $trust?->getKey(),
            'otp_challenge_uuid' => $otpChallengeUuid,
            'login_key' => $loginKey,
            'ip' => $request?->ip(),
            // A digest only: the header itself is free text.
            'user_agent_hash' => is_string($agent) && $agent !== '' ? hash('sha256', $agent) : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
