<?php

namespace Tests\Unit\FamilyAuth;

use App\Enums\FingerprintContext;
use App\Support\FamilyAuth\KeyedFingerprint;
use App\Support\NationalIdFingerprint;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * The Family Auth keyed fingerprint (docs/11 §30a). Keys here are throwaway
 * test strings set through config(); no key lives in the repository.
 */
class KeyedFingerprintTest extends TestCase
{
    private const KEY = 'test-only-family-auth-key-0123456789-abcdef';

    private const OTHER_KEY = 'another-test-only-family-auth-key-9876543210';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure(self::KEY, 1);
    }

    private function configure(?string $key, int $version, ?string $previousKey = null, ?int $previousVersion = null): void
    {
        config(['family_auth.fingerprint' => [
            'key' => $key,
            'key_version' => $version,
            'previous_key' => $previousKey,
            'previous_key_version' => $previousVersion,
        ]]);
    }

    public function test_a_fingerprint_is_64_lowercase_hex_and_deterministic(): void
    {
        $a = KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789');

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $a);
        $this->assertSame($a, KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789'));
        $this->assertNotSame($a, KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456780'));
        $this->assertStringNotContainsString('123456789', $a);
    }

    public function test_contexts_are_domain_separated(): void
    {
        $values = array_map(
            fn (FingerprintContext $context) => KeyedFingerprint::of($context, '0591234567'),
            FingerprintContext::cases(),
        );

        // LOGIN_ID, MOBILE, OTP_CODE and MEMBER_REF (FU-13).
        $this->assertCount(4, FingerprintContext::cases());
        $this->assertCount(4, array_unique($values));
    }

    public function test_it_is_independent_of_app_key_and_of_the_import_fingerprint(): void
    {
        $before = KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789');

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertSame($before, KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789'));
        $this->assertNotSame($before, NationalIdFingerprint::of('123456789'));

        // Even with APP_KEY equal to the Family Auth key the contexts differ.
        config(['app.key' => self::KEY]);
        $this->assertNotSame($before, NationalIdFingerprint::of('123456789'));
    }

    public function test_a_different_key_gives_a_different_fingerprint(): void
    {
        $before = KeyedFingerprint::of(FingerprintContext::MOBILE, '0591234567');
        $this->configure(self::OTHER_KEY, 1);

        $this->assertNotSame($before, KeyedFingerprint::of(FingerprintContext::MOBILE, '0591234567'));
    }

    public function test_base64_keys_are_decoded(): void
    {
        $raw = str_repeat('k', 32);
        $this->configure($raw, 1);
        $plain = KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789');

        $this->configure('base64:'.base64_encode($raw), 1);
        $this->assertSame($plain, KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789'));
    }

    public function test_it_fails_closed_without_a_valid_key(): void
    {
        foreach ([null, '', 'too-short', 'base64:'.base64_encode('short'), 'base64:not valid base64 !!!'] as $key) {
            $this->configure($key, 1);
            try {
                KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789');
                $this->fail('A fingerprint was produced without a valid key.');
            } catch (LogicException $e) {
                $this->assertStringNotContainsString('123456789', $e->getMessage());
            }
        }
    }

    public function test_there_is_no_fallback_to_app_key(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->configure(null, 1);

        $this->expectException(LogicException::class);
        KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789');
    }

    public function test_an_invalid_key_version_fails_closed(): void
    {
        config(['family_auth.fingerprint.key_version' => 0]);

        $this->expectException(LogicException::class);
        KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789');
    }

    public function test_an_empty_value_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '');
    }

    public function test_key_versions_support_a_rotation(): void
    {
        $old = KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789');
        $this->assertSame([1], KeyedFingerprint::versions());

        // Rotated: version 2 is current, version 1 stays readable.
        $this->configure(self::OTHER_KEY, 2, self::KEY, 1);

        $this->assertSame(2, KeyedFingerprint::currentVersion());
        $this->assertSame([2, 1], KeyedFingerprint::versions());
        $this->assertSame($old, KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789', 1));
        $this->assertNotSame($old, KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789'));
        $this->assertTrue(KeyedFingerprint::matches(FingerprintContext::LOGIN_ID, '123456789', $old, 1));
        $this->assertFalse(KeyedFingerprint::matches(FingerprintContext::LOGIN_ID, '123456789', $old));
        $this->assertFalse(KeyedFingerprint::matches(FingerprintContext::LOGIN_ID, '123456789', null));
    }

    public function test_an_unknown_or_ambiguous_version_fails_closed(): void
    {
        $this->configure(self::OTHER_KEY, 2, self::KEY, 1);
        try {
            KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789', 3);
            $this->fail('An unknown key version was accepted.');
        } catch (LogicException) {
        }

        // A previous key that claims the current version is never used.
        $this->configure(null, 2, self::KEY, 2);
        $this->expectException(LogicException::class);
        KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789');
    }

    public function test_the_application_boots_without_a_key_and_ships_no_key(): void
    {
        // phpunit sets no Family Auth key: the framework config is empty.
        $this->refreshApplication();

        $this->assertNull(config('family_auth.fingerprint.key'));
        $this->assertNull(config('family_auth.fingerprint.previous_key'));
        $this->assertSame(1, config('family_auth.fingerprint.key_version'));
        $this->assertFalse(config('family_auth.activation_enabled'));
        $this->assertSame(
            ['digits' => 6, 'ttl_seconds' => 300, 'max_attempts' => 5, 'resend_cooldown_seconds' => 60, 'max_sends' => 3, 'grant_ttl_seconds' => 600],
            config('family_auth.otp'),
        );
        $this->assertSame(8, config('family_auth.password_min_length'));
    }
}
