<?php

namespace Tests\Feature\FamilyAuth;

use App\Models\Person;
use App\Support\FamilyAuth\OtpThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

/**
 * PWA-1E: cross-challenge OTP SMS ceilings (docs/11 §30a) — per Person,
 * destination, IP and globally. Keys carry no raw identifier and the
 * throttle fails closed. Synthetic data only.
 */
class OtpThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '203.0.113.10';

    private OtpThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->throttle = app(OtpThrottle::class);
        // High everywhere; each test lowers the dimension it exercises.
        config(['family_auth.throttle' => [
            'person' => ['hour' => 1000, 'day' => 1000],
            'destination' => ['hour' => 1000, 'day' => 1000],
            'ip' => ['hour' => 1000],
            'global' => ['hour' => 1000],
        ]]);
    }

    private function fingerprint(string $seed = 'a'): string
    {
        return hash('sha256', 'synthetic-destination-'.$seed);
    }

    private function send(Person $person, ?string $fingerprint = null, ?string $ip = self::IP): bool
    {
        return $this->throttle->attempt($person, $fingerprint ?? $this->fingerprint((string) $person->id), $ip);
    }

    /** $times sends are allowed; the next one is not. */
    private function assertCeiling(int $times, callable $send): void
    {
        for ($i = 1; $i <= $times; $i++) {
            $this->assertTrue($send(), "send {$i} of {$times} was blocked");
        }
        $this->assertFalse($send(), 'the send after the ceiling was allowed');
    }

    public function test_the_approved_defaults_are_configured_and_overridable(): void
    {
        $defaults = (require base_path('config/family_auth.php'))['throttle'];

        $this->assertSame([
            'person' => ['hour' => 5, 'day' => 10],
            'destination' => ['hour' => 10, 'day' => 20],
            'ip' => ['hour' => 20],
            'global' => ['hour' => 500],
        ], $defaults);
        // Environment-overridable, not literals in code.
        $source = file_get_contents(base_path('config/family_auth.php'));
        foreach (['PERSON_HOUR', 'PERSON_DAY', 'DESTINATION_HOUR', 'DESTINATION_DAY', 'IP_HOUR', 'GLOBAL_HOUR'] as $name) {
            $this->assertStringContainsString("FAMILY_OTP_THROTTLE_{$name}", $source);
        }
    }

    public function test_person_hourly_ceiling(): void
    {
        config(['family_auth.throttle.person.hour' => 5]);
        $person = Person::factory()->create();

        $this->assertCeiling(5, fn () => $this->send($person));

        // Another Person is unaffected; the window reopens after an hour.
        $this->assertTrue($this->send(Person::factory()->create()));
        $this->travel(61)->minutes();
        $this->assertTrue($this->send($person));
    }

    public function test_person_daily_ceiling(): void
    {
        config(['family_auth.throttle.person.hour' => 5, 'family_auth.throttle.person.day' => 10]);
        $person = Person::factory()->create();

        $this->assertCeiling(5, fn () => $this->send($person));
        $this->travel(61)->minutes();
        // The hour reopened, the day did not: five more, then blocked.
        $this->assertCeiling(5, fn () => $this->send($person));
        $this->travel(61)->minutes();
        $this->assertFalse($this->send($person));

        $this->travel(24)->hours();
        $this->assertTrue($this->send($person));
    }

    public function test_destination_hourly_ceiling_counts_across_persons(): void
    {
        config(['family_auth.throttle.destination.hour' => 10]);
        $shared = $this->fingerprint('shared-phone');
        $people = Person::factory()->count(3)->create();

        // Three household heads on one phone share the destination ceiling.
        $i = 0;
        $this->assertCeiling(10, function () use ($people, $shared, &$i) {
            return $this->send($people[$i++ % 3], $shared);
        });
        foreach ($people as $person) {
            $this->assertFalse($this->send($person, $shared));
            // The same Person on another destination is fine.
            $this->assertTrue($this->send($person, $this->fingerprint('other-'.$person->id)));
        }
    }

    public function test_destination_daily_ceiling(): void
    {
        config(['family_auth.throttle.destination.hour' => 10, 'family_auth.throttle.destination.day' => 20]);
        $shared = $this->fingerprint('shared-phone');
        $send = fn () => $this->send(Person::factory()->create(), $shared);

        $this->assertCeiling(10, $send);
        $this->travel(61)->minutes();
        $this->assertCeiling(10, $send);
        $this->travel(61)->minutes();
        $this->assertFalse($send());

        $this->travel(24)->hours();
        $this->assertTrue($send());
    }

    public function test_ip_hourly_ceiling(): void
    {
        config(['family_auth.throttle.ip.hour' => 20]);
        $send = fn () => $this->send(Person::factory()->create());

        $this->assertCeiling(20, $send);
        // Another address is unaffected.
        $this->assertTrue($this->send(Person::factory()->create(), null, '198.51.100.7'));
        // Without a request IP (a console caller) the other ceilings still apply.
        $this->assertTrue($this->send(Person::factory()->create(), null, null));
    }

    public function test_global_hourly_ceiling(): void
    {
        config(['family_auth.throttle.global.hour' => 6]);
        $n = 0;

        // Different persons, destinations and addresses: only the global one binds.
        $this->assertCeiling(6, function () use (&$n) {
            $n++;

            return $this->send(Person::factory()->create(), $this->fingerprint("d{$n}"), "198.51.100.{$n}");
        });
        $this->travel(61)->minutes();
        $this->assertTrue($this->send(Person::factory()->create()));
    }

    public function test_a_blocked_request_counts_nothing(): void
    {
        config(['family_auth.throttle.person.hour' => 1, 'family_auth.throttle.destination.hour' => 3]);
        $person = Person::factory()->create();
        $shared = $this->fingerprint('shared');

        $this->assertTrue($this->send($person, $shared));
        // Blocked by the person ceiling: the destination must not be charged.
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($this->send($person, $shared));
        }
        $this->assertTrue($this->send(Person::factory()->create(), $shared));
        $this->assertTrue($this->send(Person::factory()->create(), $shared));
        $this->assertFalse($this->send(Person::factory()->create(), $shared));
    }

    public function test_keys_contain_no_raw_identifier(): void
    {
        $person = Person::factory()->create(['national_id' => '123456789', 'mobile' => '0591234567']);
        $fingerprint = $this->fingerprint('x');

        $keys = array_column($this->throttle->limits($person, $fingerprint, self::IP), 0);

        $this->assertCount(6, $keys);
        foreach ($keys as $key) {
            $this->assertStringStartsWith('family-otp|', $key);
            $this->assertStringNotContainsString('0591234567', $key);
            $this->assertStringNotContainsString('123456789', $key);
            $this->assertStringNotContainsString(self::IP, $key);
        }
        // The destination by its keyed fingerprint, the IP as a digest.
        $this->assertContains("family-otp|destination|{$fingerprint}|hour", $keys);
        $this->assertContains('family-otp|ip|'.hash('sha256', self::IP).'|hour', $keys);
        $this->assertContains("family-otp|person|{$person->id}|day", $keys);
        $this->assertContains('family-otp|global|all|hour', $keys);
    }

    public function test_a_storage_failure_fails_closed(): void
    {
        $person = Person::factory()->create();
        RateLimiter::shouldReceive('tooManyAttempts')->andThrow(new RuntimeException('cache store down'));

        $this->assertFalse($this->send($person));
    }

    public function test_a_failure_while_counting_fails_closed(): void
    {
        $person = Person::factory()->create();
        RateLimiter::shouldReceive('tooManyAttempts')->andReturn(false);
        RateLimiter::shouldReceive('hit')->andThrow(new RuntimeException('cache store down'));

        $this->assertFalse($this->send($person));
    }

    public function test_a_ceiling_below_one_blocks_every_send(): void
    {
        config(['family_auth.throttle.global.hour' => 0]);

        $this->assertFalse($this->send(Person::factory()->create()));
    }
}
