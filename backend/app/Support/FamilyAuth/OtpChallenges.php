<?php

namespace App\Support\FamilyAuth;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FingerprintContext;
use App\Enums\OtpFailure;
use App\Enums\OtpPurpose;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use App\Models\User;
use App\Support\Sms\SmsDeliveryException;
use App\Support\Sms\SmsDispatcher;
use App\Support\Sms\SmsMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * The one OTP service (docs/11 §30a, docs/05 §53b). Activation and password
 * reset (later slices) build on it; no controller carries OTP logic.
 *
 * A challenge is bound to ONE purpose, ONE Person and ONE mobile trust (a
 * Person + an exact number). Every step re-checks that this trust is still
 * the Person's CURRENT trusted mobile, so a code can never prove a number
 * the Person no longer has, another Person, or another purpose.
 *
 *   issue    trusted mobile → throttle → supersede the open challenge →
 *            new challenge (first send) → SMS after commit
 *   resend   same row: NEW code, the old one dead at once, the 5-minute
 *            expiry restarts, attempts are NOT reset; 60 s cooldown, at most
 *            3 sends; an expired challenge is never resurrected
 *   verify   at most 5 attempts, then LOCKED; a correct code opens the
 *            10-minute grant — it does not consume
 *   consume  inside the CALLER's transaction, within the grant
 *
 * The plaintext code exists only in memory and in the SMS: the row stores a
 * keyed hash bound to the challenge (KeyedFingerprint, context OTP_CODE),
 * and no method returns or logs it. Policy values come from
 * config('family_auth.otp'). Failure reasons are internal.
 *
 * Transactions: issue, resend and verify commit on their own and refuse to
 * run inside a caller's transaction — a failed attempt must stay counted,
 * and an SMS must never be sent for a row that is later rolled back.
 * consume is the opposite: it only runs inside the caller's transaction.
 */
final class OtpChallenges
{
    public function __construct(
        private readonly CurrentTrustedMobile $trustedMobile,
        private readonly OtpThrottle $throttle,
        private readonly SmsDispatcher $sms,
    ) {}

    public function issue(OtpPurpose $purpose, Person $person, ?User $user = null): OtpResult
    {
        $this->assertOwnTransaction();
        if ($purpose === OtpPurpose::PASSWORD_RESET && $user === null) {
            throw new InvalidArgumentException('A password reset challenge belongs to an existing account.');
        }

        $trusted = $this->trustedMobile->for($person);
        if (! $trusted->isTrusted()) {
            return OtpResult::failed(OtpFailure::TRUST_NOT_CURRENT);
        }
        if (! $this->throttle->attempt($person, $trusted->trust->mobile_fingerprint, $this->ip())) {
            return OtpResult::failed(OtpFailure::THROTTLED);
        }

        $code = $this->newCode();
        [$challenge, $destination] = DB::transaction(function () use ($purpose, $person, $user, $trusted, $code) {
            // One issue per Person at a time; the trust is re-read under the lock.
            $locked = Person::withTrashed()->whereKey($person->getKey())->lockForUpdate()->first();
            $current = $locked ? $this->trustedMobile->for($locked) : null;
            if ($current === null || ! $current->isTrusted() || ! $current->trust->is($trusted->trust)) {
                return [null, null];
            }

            // Expired or not, a previous open challenge is unusable from now.
            $this->supersede($person, $purpose);

            // The uuid is fixed BEFORE the row exists: the code hash is bound
            // to it (it is not mass-assignable, hence forceFill).
            $uuid = (string) Str::uuid();
            $challenge = (new AuthOtpChallenge)->forceFill([
                'uuid' => $uuid,
                'purpose' => $purpose,
                'person_id' => $person->getKey(),
                'user_id' => $user?->getKey(),
                'mobile_trust_id' => $current->trust->getKey(),
                'code_hash' => $this->hash($uuid, $code),
                'expires_at' => now()->addSeconds($this->setting('ttl_seconds')),
                'attempts' => 0,
                // Creating the challenge IS the first send attempt.
                'send_count' => 1,
                'last_sent_at' => now(),
                'ip' => $this->ip(),
            ]);
            $challenge->save();

            return [$challenge, $current->destination];
        });

        return $challenge === null
            ? OtpResult::failed(OtpFailure::TRUST_NOT_CURRENT)
            : $this->deliver($challenge, $destination, $code);
    }

    public function resend(string $challengeUuid, OtpPurpose $purpose, ?Person $person = null): OtpResult
    {
        $this->assertOwnTransaction();

        $code = $this->newCode();
        [$failure, $challenge, $destination] = DB::transaction(function () use ($challengeUuid, $purpose, $person, $code) {
            $challenge = $this->locked($challengeUuid);
            if ($failure = $this->unusable($challenge, $purpose, $person)) {
                return [$failure, $challenge, null];
            }
            if ($challenge->verified_at !== null) {
                return [OtpFailure::ALREADY_VERIFIED, $challenge, null];
            }
            // Never resurrected: the caller starts a fresh challenge.
            if ($this->expired($challenge)) {
                return [OtpFailure::EXPIRED, $challenge, null];
            }
            $trusted = $this->currentTrust($challenge);
            if ($trusted === null) {
                return [OtpFailure::TRUST_NOT_CURRENT, $challenge, null];
            }
            if ($challenge->send_count >= $this->setting('max_sends')) {
                return [OtpFailure::SEND_LIMIT, $challenge, null];
            }
            if (now()->lessThan($challenge->last_sent_at->copy()->addSeconds($this->setting('resend_cooldown_seconds')))) {
                return [OtpFailure::COOLDOWN, $challenge, null];
            }
            if (! $this->throttle->attempt($challenge->person()->withTrashed()->first(), $trusted->trust->mobile_fingerprint, $this->ip())) {
                return [OtpFailure::THROTTLED, $challenge, null];
            }

            // A new code: the previous one stops working with this write.
            // The expiry restarts; the attempts already used do NOT.
            $challenge->forceFill([
                'code_hash' => $this->hash($challenge->uuid, $code),
                'send_count' => $challenge->send_count + 1,
                'last_sent_at' => now(),
                'expires_at' => now()->addSeconds($this->setting('ttl_seconds')),
            ])->save();

            return [null, $challenge, $trusted->destination];
        });

        return $failure !== null ? OtpResult::failed($failure, $challenge) : $this->deliver($challenge, $destination, $code);
    }

    public function verify(string $challengeUuid, OtpPurpose $purpose, #[\SensitiveParameter] string $code, ?Person $person = null): OtpResult
    {
        $this->assertOwnTransaction();

        return DB::transaction(function () use ($challengeUuid, $purpose, $code, $person) {
            $challenge = $this->locked($challengeUuid);
            if ($failure = $this->unusable($challenge, $purpose, $person)) {
                return OtpResult::failed($failure, $challenge);
            }
            // A code is entered once: a verified challenge is only consumed.
            if ($challenge->verified_at !== null) {
                return OtpResult::failed(OtpFailure::ALREADY_VERIFIED, $challenge);
            }
            if ($this->expired($challenge)) {
                return OtpResult::failed(OtpFailure::EXPIRED, $challenge);
            }
            if ($this->currentTrust($challenge) === null) {
                return OtpResult::failed(OtpFailure::TRUST_NOT_CURRENT, $challenge);
            }

            $correct = preg_match('/\A[0-9]{'.$this->setting('digits').'}\z/', $code) === 1
                && hash_equals($challenge->code_hash, $this->hash($challenge->uuid, $code));

            if (! $correct) {
                $attempts = $challenge->attempts + 1;
                $locks = $attempts >= $this->setting('max_attempts');
                $challenge->forceFill(['attempts' => $attempts, 'locked_at' => $locks ? now() : null])->save();

                $this->record($challenge, AuthSecurityEventType::OTP_FAILED, AuthSecurityEventOutcome::FAILURE, OtpFailure::CODE_MISMATCH);
                if ($locks) {
                    $this->record($challenge, AuthSecurityEventType::OTP_LOCKED, AuthSecurityEventOutcome::DENIED, OtpFailure::LOCKED);
                }

                return OtpResult::failed(OtpFailure::CODE_MISMATCH, $challenge);
            }

            // Verified, not consumed: the later workflow has the grant window.
            $challenge->forceFill([
                'verified_at' => now(),
                'grant_expires_at' => now()->addSeconds($this->setting('grant_ttl_seconds')),
            ])->save();
            $this->record($challenge, AuthSecurityEventType::OTP_VERIFIED, AuthSecurityEventOutcome::SUCCESS);

            return OtpResult::ok($challenge);
        });
    }

    /**
     * Uses the verified grant — inside the CALLER's transaction, so the
     * consumption commits or rolls back with what the grant authorizes (the
     * account and its password, in PWA-1F). On a failure the caller rolls
     * its own work back.
     */
    public function consume(string $challengeUuid, OtpPurpose $purpose, Person $person, ?User $user = null): OtpResult
    {
        if (DB::transactionLevel() <= $this->ownLevel()) {
            throw new LogicException('An OTP grant is consumed inside the transaction of the workflow that uses it.');
        }

        $challenge = $this->locked($challengeUuid);
        if ($failure = $this->unusable($challenge, $purpose, $person)) {
            return OtpResult::failed($failure, $challenge);
        }
        if ($challenge->user_id !== $user?->getKey()) {
            return OtpResult::failed(OtpFailure::USER_MISMATCH, $challenge);
        }
        if ($challenge->verified_at === null) {
            return OtpResult::failed(OtpFailure::NOT_VERIFIED, $challenge);
        }
        if ($challenge->grant_expires_at === null || now()->greaterThanOrEqualTo($challenge->grant_expires_at)) {
            return OtpResult::failed(OtpFailure::GRANT_EXPIRED, $challenge);
        }
        if ($this->currentTrust($challenge) === null) {
            return OtpResult::failed(OtpFailure::TRUST_NOT_CURRENT, $challenge);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();
        $this->record($challenge, AuthSecurityEventType::OTP_CONSUMED, AuthSecurityEventOutcome::SUCCESS);

        return OtpResult::ok($challenge);
    }

    /**
     * Makes the open challenge of a Person and purpose unusable immediately.
     * (A trust that is revoked or becomes stale supersedes its challenges
     * through AuthOtpChallenge::supersedeOpenForTrust.) Returns the rows.
     */
    public function supersede(Person $person, OtpPurpose $purpose): int
    {
        return AuthOtpChallenge::query()->open()
            ->where('person_id', $person->getKey())
            ->where('purpose', $purpose->value)
            ->update(['superseded_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ parts

    /**
     * Sends after the challenge is committed — on the public routes AFTER the
     * HTTP response (SmsDispatcher, same process, no queue). The send was
     * already counted (send_count, throttle) and is never retried here: the
     * user's resend is the retry. The outcome is recorded when it is known —
     * the failure's class and reason as codes only. The plaintext code exists
     * only in this message, in memory.
     */
    private function deliver(AuthOtpChallenge $challenge, #[\SensitiveParameter] string $destination, #[\SensitiveParameter] string $code): OtpResult
    {
        $message = new SmsMessage($destination, $this->text($code), $challenge->purpose->value);

        $failure = $this->sms->dispatch($message, function (?SmsDeliveryException $failure) use ($challenge) {
            if ($failure === null) {
                $this->record($challenge, AuthSecurityEventType::OTP_ISSUED, AuthSecurityEventOutcome::SUCCESS);

                return;
            }
            $this->record($challenge, AuthSecurityEventType::OTP_ISSUED, AuthSecurityEventOutcome::FAILURE, OtpFailure::DELIVERY_FAILED, [
                'delivery_outcome' => $failure->outcome->value,
                'delivery_reason' => $failure->reason->value,
            ]);
        });

        // Deferred: not known yet — and the public answer never depends on it.
        return $failure === null ? OtpResult::ok($challenge) : OtpResult::failed(OtpFailure::DELIVERY_FAILED, $challenge);
    }

    private function locked(string $challengeUuid): ?AuthOtpChallenge
    {
        return Str::isUuid($challengeUuid)
            ? AuthOtpChallenge::query()->where('uuid', $challengeUuid)->lockForUpdate()->first()
            : null;
    }

    /** The checks every operation on an existing challenge shares. */
    private function unusable(?AuthOtpChallenge $challenge, OtpPurpose $purpose, ?Person $person): ?OtpFailure
    {
        return match (true) {
            $challenge === null => OtpFailure::NOT_FOUND,
            $challenge->purpose !== $purpose => OtpFailure::PURPOSE_MISMATCH,
            $person !== null && $challenge->person_id !== $person->getKey() => OtpFailure::PERSON_MISMATCH,
            $challenge->consumed_at !== null => OtpFailure::CONSUMED,
            $challenge->superseded_at !== null => OtpFailure::SUPERSEDED,
            $challenge->locked_at !== null => OtpFailure::LOCKED,
            default => null,
        };
    }

    private function expired(AuthOtpChallenge $challenge): bool
    {
        return now()->greaterThanOrEqualTo($challenge->expires_at);
    }

    /** The Person's current trusted mobile, only if it is this challenge's trust. */
    private function currentTrust(AuthOtpChallenge $challenge): ?TrustedMobileResult
    {
        $person = Person::withTrashed()->find($challenge->person_id);
        $trusted = $person ? $this->trustedMobile->for($person) : null;

        return $trusted !== null && $trusted->isTrusted() && $trusted->trust->getKey() === $challenge->mobile_trust_id
            ? $trusted
            : null;
    }

    private function newCode(): string
    {
        $digits = $this->setting('digits');

        // random_int: a cryptographically secure source.
        return str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
    }

    /** Keyed and bound to the challenge: useless for any other row. */
    private function hash(string $challengeUuid, #[\SensitiveParameter] string $code): string
    {
        return KeyedFingerprint::of(FingerprintContext::OTP_CODE, $challengeUuid.':'.$code);
    }

    private function text(#[\SensitiveParameter] string $code): string
    {
        $minutes = (int) ceil($this->setting('ttl_seconds') / 60);

        // One UCS-2 SMS part (≤ 70 units): ASCII digits, nothing else.
        return "رمز التحقق في Famboook: {$code}\nصالح {$minutes} دقائق. لا تشاركه مع أحد.";
    }

    /** @param  array<string, string>  $extra  safe codes only */
    private function record(AuthOtpChallenge $challenge, AuthSecurityEventType $type, AuthSecurityEventOutcome $outcome, ?OtpFailure $reason = null, array $extra = []): void
    {
        AuthSecurityLog::record(
            $type,
            $outcome,
            $reason,
            person: Person::withTrashed()->find($challenge->person_id),
            user: $challenge->user,
            trust: $challenge->mobileTrust,
            otpChallengeUuid: $challenge->uuid,
            metadata: ['purpose' => $challenge->purpose->value, 'attempts' => $challenge->attempts, 'send_count' => $challenge->send_count, ...$extra],
        );
    }

    private function setting(string $name): int
    {
        return (int) config("family_auth.otp.{$name}");
    }

    private function ip(): ?string
    {
        return app()->bound('request') ? request()->ip() : null;
    }

    private function assertOwnTransaction(): void
    {
        if (DB::transactionLevel() > $this->ownLevel()) {
            throw new LogicException('OTP issue, resend and verify commit on their own; do not wrap them in a transaction.');
        }
    }

    /**
     * The transaction depth that counts as "no caller transaction": 0, or 1
     * under the test suite, where RefreshDatabase wraps every test in one.
     */
    private function ownLevel(): int
    {
        return app()->runningUnitTests() ? 1 : 0;
    }
}
