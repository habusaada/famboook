<?php

namespace App\Console\Commands;

use App\Enums\AuthIdentityStatus;
use App\Enums\FingerprintContext;
use App\Enums\MobileTrustStatus;
use App\Models\FamilyAuthIdentity;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\FamilyMobile;
use App\Support\FamilyAuth\FamilyNationalId;
use App\Support\FamilyAuth\KeyedFingerprint;
use App\Support\Sms\TweetsSmsSender;
use Illuminate\Console\Command;
use LogicException;

/**
 * Family Auth readiness (docs/08 §16a, PWA-1I) — READ-ONLY. It changes no
 * row, creates no challenge, sends nothing and prints counts and YES/NO
 * values only: never a National ID, a mobile, a fingerprint, a key or a
 * secret.
 *
 * Above all it detects a WRONG fingerprint key (not only a missing one): a
 * key that does not reproduce the stored fingerprints fails silently in use
 * — every login "invalid", every trusted mobile "stale", every reset a
 * decoy. Here it shows as ACTIVE identities and TRUSTED mobiles that no
 * longer match their Person's current National ID / mobile.
 *
 * Exit code 1 when any warning is reported.
 */
class FamilyAuthCheck extends Command
{
    protected $signature = 'famboook:family-auth-check';

    protected $description = 'Read-only Family Auth readiness check (counts and YES/NO only).';

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $keyUsable = $this->fingerprint();
        $this->identities($keyUsable);
        $this->trusts($keyUsable);
        $this->runtime();

        $this->newLine();
        if ($this->warnings === []) {
            $this->info('Family Auth readiness: no warnings.');

            return self::SUCCESS;
        }
        foreach ($this->warnings as $warning) {
            $this->warn("WARN: {$warning}");
        }
        $this->error('Family Auth readiness: '.count($this->warnings).' warning(s).');

        return self::FAILURE;
    }

    /** A. The fingerprint secret — whether it is usable, never what it is. */
    private function fingerprint(): bool
    {
        $configured = is_string(config('family_auth.fingerprint.key')) && config('family_auth.fingerprint.key') !== '';
        $version = config('family_auth.fingerprint.key_version');
        $versionOk = is_int($version) && $version >= 1;
        $keyOk = self::usable(fn () => KeyedFingerprint::of(FingerprintContext::LOGIN_ID, 'readiness-probe'));
        $hasPrevious = config('family_auth.fingerprint.previous_key') !== null;
        $previousOk = ! $hasPrevious || self::usable(fn () => KeyedFingerprint::versions());

        $this->line('[Fingerprint]');
        $this->line('  Key configured:         '.self::yesNo($configured));
        $this->line('  Key valid (>= 32 bytes): '.self::yesNo($keyOk));
        $this->line('  Current version valid:  '.self::yesNo($versionOk));
        $this->line('  Previous key:           '.($hasPrevious ? ($previousOk ? 'configured, valid' : 'configured, INVALID') : 'none'));

        if (! $configured) {
            $this->warnings[] = 'FAMILY_AUTH_FINGERPRINT_KEY is not configured: no Family login, activation or reset can work.';
        } elseif (! $keyOk) {
            $this->warnings[] = 'The fingerprint key or its version is not valid (at least 32 bytes, version >= 1).';
        }
        if (! $versionOk) {
            $this->warnings[] = 'FAMILY_AUTH_FINGERPRINT_KEY_VERSION is not a valid version.';
        }
        if (! $previousOk) {
            $this->warnings[] = 'The previous fingerprint key is configured but not usable (key or version).';
        }

        return $keyOk;
    }

    /** B. ACTIVE identities that no longer match their Person's current National ID. */
    private function identities(bool $keyUsable): void
    {
        $total = $mismatched = $unverifiable = $unlinked = 0;
        FamilyAuthIdentity::query()->where('status', AuthIdentityStatus::ACTIVE->value)
            ->chunkById(500, function ($identities) use (&$total, &$mismatched, &$unverifiable, &$unlinked) {
                foreach ($identities as $identity) {
                    $total++;
                    $link = UserPersonLink::query()->current()->where('user_id', $identity->user_id)->first();
                    $person = $link === null ? null : Person::withTrashed()->find($link->person_id);
                    if ($person === null) {
                        $unlinked++;

                        continue;
                    }
                    $digits = FamilyNationalId::normalize($person->national_id);
                    try {
                        $matches = $digits !== null
                            && KeyedFingerprint::matches(FingerprintContext::LOGIN_ID, $digits, $identity->login_key, $identity->key_version);
                    } catch (LogicException) {
                        $unverifiable++;

                        continue;
                    }
                    if (! $matches) {
                        $mismatched++;
                    }
                }
            });

        $this->newLine();
        $this->line('[Identities]');
        $this->line("  ACTIVE identities:      {$total}");
        $this->line("  Not matching:           {$mismatched}");
        $this->line("  Key version unusable:   {$unverifiable}");
        $this->line("  Without a current link: {$unlinked}");

        if ($mismatched > 0) {
            $this->warnings[] = "{$mismatched} ACTIVE identit".($mismatched === 1 ? 'y does' : 'ies do').' not match the Person\'s current National ID under the configured key — a WRONG fingerprint key makes every one of these logins fail.';
        }
        if ($unverifiable > 0 && $keyUsable) {
            $this->warnings[] = "{$unverifiable} ACTIVE identit".($unverifiable === 1 ? 'y uses' : 'ies use').' a key version that is not configured.';
        } elseif ($unverifiable > 0) {
            $this->warnings[] = "{$unverifiable} ACTIVE identit".($unverifiable === 1 ? 'y' : 'ies').' cannot be checked without a usable fingerprint key.';
        }
        if ($unlinked > 0) {
            $this->warnings[] = "{$unlinked} ACTIVE identit".($unlinked === 1 ? 'y has' : 'ies have').' no current User-Person Link.';
        }
    }

    /** C. TRUSTED mobiles that no longer match their Person's current mobile. */
    private function trusts(bool $keyUsable): void
    {
        $total = $mismatched = $unverifiable = 0;
        PersonMobileTrust::query()->where('status', MobileTrustStatus::TRUSTED->value)
            ->chunkById(500, function ($trusts) use (&$total, &$mismatched, &$unverifiable) {
                foreach ($trusts as $trust) {
                    $total++;
                    $mobile = FamilyMobile::normalize(Person::withTrashed()->find($trust->person_id)?->mobile);
                    try {
                        $matches = $mobile !== null
                            && KeyedFingerprint::matches(FingerprintContext::MOBILE, $mobile, $trust->mobile_fingerprint, $trust->key_version);
                    } catch (LogicException) {
                        $unverifiable++;

                        continue;
                    }
                    if (! $matches) {
                        $mismatched++;
                    }
                }
            });

        $this->newLine();
        $this->line('[Mobile trust]');
        $this->line("  TRUSTED mobiles:        {$total}");
        $this->line("  Not matching:           {$mismatched}");
        $this->line("  Key version unusable:   {$unverifiable}");

        if ($mismatched > 0) {
            $this->warnings[] = "{$mismatched} TRUSTED mobile".($mismatched === 1 ? ' does' : 's do').' not match the Person\'s current mobile under the configured key — a wrong fingerprint key, or a number changed without the trust becoming STALE. No OTP can reach them.';
        }
        if ($unverifiable > 0) {
            $this->warnings[] = "{$unverifiable} TRUSTED mobile".($unverifiable === 1 ? '' : 's').($keyUsable ? ' use a key version that is not configured.' : ' cannot be checked without a usable fingerprint key.');
        }
    }

    /** D + E. Runtime configuration and what would make Family Auth unsafe or nonfunctional. */
    private function runtime(): void
    {
        $activation = config('family_auth.activation_enabled') === true;
        $login = config('family_auth.login_enabled') === true;
        $reset = config('family_auth.password_reset_enabled') === true;
        $driver = (string) config('family_auth.sms.driver');
        $tweetsms = TweetsSmsSender::fromConfig((array) config('family_auth.sms.tweetsms'))->configured();
        $production = app()->environment('production');
        $sameSite = config('session.same_site');

        $this->newLine();
        $this->line('[Runtime]');
        $this->line('  APP_ENV:                '.app()->environment());
        $this->line('  APP_DEBUG:              '.self::yesNo(config('app.debug') === true));
        $this->line('  Activation enabled:     '.self::yesNo($activation));
        $this->line('  Login enabled:          '.self::yesNo($login));
        $this->line('  Password reset enabled: '.self::yesNo($reset));
        $this->line('  SMS driver:             '.($driver !== '' ? $driver : '(none)'));
        $this->line('  TweetsMS configured:    '.self::yesNo($tweetsms));
        $this->line('  SESSION_DRIVER:         '.config('session.driver'));
        $this->line('  SESSION_SECURE_COOKIE:  '.self::yesNo(config('session.secure') === true));
        $this->line('  SESSION_HTTP_ONLY:      '.self::yesNo(config('session.http_only') === true));
        $this->line('  SESSION_SAME_SITE:      '.($sameSite ?? '(none)'));
        $this->line('  SESSION_ENCRYPT:        '.self::yesNo(config('session.encrypt') === true));
        $this->line('  CACHE_STORE:            '.config('cache.default'));

        $sends = $activation || $reset;
        $smsReady = ($driver === 'tweetsms' && $tweetsms) || ($driver === 'log' && ! $production);
        if ($sends && ! $smsReady) {
            $this->warnings[] = 'Activation or password reset is enabled but no SMS can be delivered (FAMILY_SMS_DRIVER / TweetsMS configuration).';
        }
        if ($production && $driver === 'log') {
            $this->warnings[] = 'FAMILY_SMS_DRIVER=log is a local development driver; it refuses to send in production.';
        }
        if ($activation && ! $login) {
            $this->warnings[] = 'Activation is enabled without login: activated users cannot return after their session ends.';
        }
        if (config('session.driver') !== 'database') {
            $this->warnings[] = 'SESSION_DRIVER is not database: a password reset or a revocation cannot delete the account\'s other sessions.';
        }
        if (config('session.http_only') !== true) {
            $this->warnings[] = 'SESSION_HTTP_ONLY is not true.';
        }
        if (! in_array($sameSite, ['lax', 'strict'], true)) {
            $this->warnings[] = 'SESSION_SAME_SITE is not lax or strict.';
        }
        if ($production && config('session.secure') !== true) {
            $this->warnings[] = 'SESSION_SECURE_COOKIE is not true in production.';
        }
        if ($production && config('session.encrypt') !== true) {
            $this->warnings[] = 'SESSION_ENCRYPT is not true in production.';
        }
        if ($production && config('app.debug') === true) {
            $this->warnings[] = 'APP_DEBUG is on in production.';
        }
        if (in_array(config('cache.default'), ['array', 'null'], true)) {
            $this->warnings[] = 'CACHE_STORE keeps nothing between requests: rate limits, lockouts and decoy references cannot work.';
        }
    }

    private static function usable(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (LogicException) {
            return false;
        }
    }

    private static function yesNo(bool $value): string
    {
        return $value ? 'YES' : 'NO';
    }
}
