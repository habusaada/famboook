<?php

namespace App\Support\FamilyAuth;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAuthError;
use App\Enums\LoginDenial;
use App\Exceptions\FamilyAuthException;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use LogicException;

/**
 * Family Portal login (docs/11 §30a, docs/06 §22b): National ID + password.
 *
 *   National ID → strict normalizer → keyed LOGIN_ID fingerprint
 *   → ACTIVE Family Auth Identity → User → password
 *   → FamilyAccessResolver::familyContext()
 *
 * persons.national_id is never the login lookup. A correct password is NOT
 * enough: the account must have a Family context right now — an active
 * family-side User, an ACTIVE link, a Person who is not deleted, active and
 * ALIVE, an identity consistent with the Person's current National ID, an
 * active household-head membership and an ACTIVE Family. Those rules live in
 * the resolver, not here.
 *
 * ANTI-ENUMERATION. Every failure — unknown identifier, wrong password,
 * inactive or Staff account, ended link, death, moved headship — is the same
 * INVALID_CREDENTIALS. The password is verified for an unknown identifier
 * too, against a dummy hash of the same cost, so "unknown" and "wrong
 * password" cost the same; the context is evaluated only after a correct
 * password. The reason goes to auth_security_events, never to the browser.
 *
 * LOCKOUT, two tiers, both keyed by the fingerprint and both applied to any
 * identifier, known or not. Every attempt is counted atomically BEFORE the
 * password check (so parallel attempts cannot overrun a tier) and a
 * successful login clears both — in effect they count failures:
 *   identifier + IP      a guesser at one address
 *   identifier, any IP   a distributed guesser — set higher, so that knowing
 *                        someone's National ID is not enough to lock them out
 *                        from a single address
 * A successful login clears both. (The per-IP ceiling on every attempt is
 * the `family-login` route limiter.)
 */
final class FamilyLogin
{
    public function __construct(
        private readonly FamilyAuthIdentities $identities,
        private readonly FamilyAccessResolver $resolver,
    ) {}

    /**
     * The family-side User these credentials sign in — or INVALID_CREDENTIALS.
     *
     * @param  string  $nationalId  nine digits, already through FamilyNationalId::normalize()
     */
    public function attempt(#[\SensitiveParameter] string $nationalId, #[\SensitiveParameter] string $password, ?string $ip): User
    {
        try {
            $loginKey = $this->identities->keyFor($nationalId);
        } catch (LogicException) {
            // No fingerprint key: nobody can sign in.
            Log::error('Family login unavailable: the Family Auth fingerprint key is missing.');

            throw new FamilyAuthException(FamilyAuthError::FAMILY_AUTH_UNAVAILABLE);
        }

        // The attempt is COUNTED before the password is verified, with an
        // atomic increment, and an attempt beyond a ceiling is refused
        // without any verification: of parallel attempts, at most the
        // ceiling reach the password check (PWA-1I). A success clears.
        $counters = self::counters($loginKey, $ip);
        $decay = (int) config('family_auth.login.limits.decay_seconds');
        foreach ($counters as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->throttled($loginKey);
            }
        }
        foreach ($counters as [$key, $max]) {
            if (RateLimiter::increment($key, $decay) > $max) {
                $this->throttled($loginKey);
            }
        }

        $identity = $this->identities->findByNationalIdInput($nationalId);
        $user = $identity?->user;

        // Always one password verification, whether the identifier exists or not.
        $correct = Hash::check($password, $user?->password ?? self::dummyHash());

        $denial = match (true) {
            $user === null => LoginDenial::UNKNOWN_IDENTIFIER,
            ! $correct => LoginDenial::WRONG_PASSWORD,
            default => null,
        };
        $context = $denial === null ? $this->resolver->familyContext($user) : null;
        if ($context !== null) {
            $denial = match (true) {
                ! $context->hasFamilyContext() => $context->denial,
                // The identity that was looked up IS the linked Person's current one.
                ! $context->authIdentity->is($identity) => LoginDenial::IDENTITY_MISMATCH,
                default => null,
            };
        }

        if ($denial !== null) {
            // Already counted above.
            $this->failed($denial, $loginKey, $user);

            throw new FamilyAuthException(FamilyAuthError::INVALID_CREDENTIALS);
        }

        foreach ($counters as [$key]) {
            RateLimiter::clear($key);
        }
        AuthSecurityLog::record(
            AuthSecurityEventType::LOGIN_SUCCEEDED,
            AuthSecurityEventOutcome::SUCCESS,
            person: $context->person, user: $user, link: $context->link,
        );

        return $user;
    }

    /**
     * A bcrypt hash of a random value at the CONFIGURED cost, made once and
     * kept: verifying against it costs what verifying a real password costs.
     * Never generated per request.
     */
    public static function dummyHash(): string
    {
        $cost = (int) config('hashing.bcrypt.rounds', 12);

        return Cache::rememberForever(
            "family-login|dummy-hash|{$cost}",
            fn () => Hash::make(Str::random(40)),
        );
    }

    /**
     * The two failure counters, as [key, ceiling].
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function counters(string $loginKey, ?string $ip): array
    {
        return [
            ["family-login|identifier-ip|{$loginKey}|".hash('sha256', (string) $ip), (int) config('family_auth.login.limits.identifier_ip_failures')],
            ["family-login|identifier|{$loginKey}", (int) config('family_auth.login.limits.identifier_failures')],
        ];
    }

    private function throttled(string $loginKey): never
    {
        AuthSecurityLog::record(AuthSecurityEventType::LOGIN_FAILED, AuthSecurityEventOutcome::DENIED, LoginDenial::THROTTLED, loginKey: $loginKey);

        throw new FamilyAuthException(FamilyAuthError::TOO_MANY_REQUESTS);
    }

    private function failed(BackedEnum $reason, string $loginKey, ?User $user): void
    {
        AuthSecurityLog::record(
            AuthSecurityEventType::LOGIN_FAILED,
            AuthSecurityEventOutcome::FAILURE,
            $reason,
            user: $user,
            loginKey: $loginKey,
        );
    }
}
