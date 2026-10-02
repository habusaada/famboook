<?php

namespace Tests\Feature\FamilyAuth;

use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\FamilyAuthIdentity;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\UserPersonLink;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PWA-1E: retention of OTP challenges (docs/11 §30a). Only FINISHED rows
 * older than 90 days are purged; nothing else is ever deleted. Synthetic
 * data only.
 */
class PurgeOtpChallengesTest extends TestCase
{
    use RefreshDatabase;

    /** A challenge created $daysAgo days ago with the given state. */
    private function challenge(int $daysAgo, string $state = 'open'): AuthOtpChallenge
    {
        $at = now()->subDays($daysAgo);
        $person = Person::factory()->create();
        $trust = PersonMobileTrust::factory()->trusted()->create(['person_id' => $person->id]);

        return AuthOtpChallenge::factory()->create([
            'person_id' => $person->id,
            'mobile_trust_id' => $trust->id,
            'last_sent_at' => $at,
            'expires_at' => $at->copy()->addSeconds(300),
            'created_at' => $at,
            ...match ($state) {
                'consumed' => ['verified_at' => $at, 'grant_expires_at' => $at->copy()->addSeconds(600), 'consumed_at' => $at],
                'superseded' => ['superseded_at' => $at],
                'locked' => ['attempts' => 5, 'locked_at' => $at],
                'verified' => ['verified_at' => $at, 'grant_expires_at' => $at->copy()->addSeconds(600)],
                default => [],
            },
        ]);
    }

    private function purge(array $options = []): void
    {
        $this->artisan('famboook:purge-otp-challenges', $options)->assertExitCode(0);
    }

    public function test_finished_challenges_older_than_ninety_days_are_removed(): void
    {
        $old = [
            $this->challenge(91, 'consumed'),
            $this->challenge(91, 'superseded'),
            $this->challenge(91, 'locked'),
            // Expired without any other outcome, and expired after a verification.
            $this->challenge(91, 'open'),
            $this->challenge(91, 'verified'),
            $this->challenge(400, 'consumed'),
        ];

        $this->artisan('famboook:purge-otp-challenges')
            ->expectsOutputToContain('Purged 6 finished OTP challenge(s) older than 90 day(s).')
            ->assertExitCode(0);

        foreach ($old as $challenge) {
            $this->assertNull(AuthOtpChallenge::find($challenge->id));
        }
    }

    public function test_recent_finished_challenges_are_retained(): void
    {
        $recent = [
            $this->challenge(89, 'consumed'),
            $this->challenge(89, 'superseded'),
            $this->challenge(89, 'locked'),
            $this->challenge(89, 'open'),       // expired 89 days ago
            $this->challenge(89, 'verified'),
            $this->challenge(1, 'consumed'),
        ];

        $this->purge();

        $this->assertSame(count($recent), AuthOtpChallenge::count());
    }

    public function test_an_open_unexpired_challenge_is_never_removed(): void
    {
        $open = AuthOtpChallenge::factory()->create();
        $verified = AuthOtpChallenge::factory()->create(['verified_at' => now(), 'grant_expires_at' => now()->addSeconds(600)]);
        $this->challenge(91, 'consumed');

        $this->purge();

        $this->assertSame([$open->id, $verified->id], AuthOtpChallenge::orderBy('id')->pluck('id')->all());
    }

    public function test_the_terminal_moment_decides_not_the_creation_date(): void
    {
        // Created long ago, finished recently: kept.
        $challenge = $this->challenge(200, 'open');
        $challenge->forceFill(['superseded_at' => now()->subDays(10)])->save();

        $this->purge();

        $this->assertNotNull(AuthOtpChallenge::find($challenge->id));

        Carbon::setTestNow(now()->addDays(81));
        $this->purge();
        $this->assertNull(AuthOtpChallenge::find($challenge->id));
    }

    public function test_nothing_but_otp_challenges_is_deleted(): void
    {
        $challenge = $this->challenge(120, 'consumed');
        $trust = PersonMobileTrust::find($challenge->mobile_trust_id);
        PersonMobileTrust::factory()->stale()->create(['created_at' => now()->subDays(500), 'stale_at' => now()->subDays(400)]);
        PersonMobileTrust::factory()->revoked()->create(['created_at' => now()->subDays(500), 'revoked_at' => now()->subDays(400)]);
        UserPersonLink::factory()->ended()->create(['created_at' => now()->subDays(500), 'ended_at' => now()->subDays(400)]);
        FamilyAuthIdentity::factory()->superseded()->create(['created_at' => now()->subDays(500), 'superseded_at' => now()->subDays(400)]);
        $event = AuthSecurityEvent::factory()->create([
            'created_at' => now()->subDays(500), 'person_id' => $challenge->person_id, 'otp_challenge_uuid' => $challenge->uuid,
        ]);
        $counts = fn () => [PersonMobileTrust::count(), UserPersonLink::count(), FamilyAuthIdentity::count(), AuthSecurityEvent::count(), Person::count()];
        $before = $counts();

        $this->purge();

        $this->assertSame(0, AuthOtpChallenge::count());
        $this->assertSame($before, $counts());
        $this->assertNotNull($trust->fresh());
        // The event survives its challenge and keeps the reference.
        $this->assertSame($challenge->uuid, $event->fresh()->otp_challenge_uuid);
    }

    public function test_the_retention_period_is_configurable(): void
    {
        $this->assertSame(90, (require base_path('config/family_auth.php'))['retention']['otp_challenge_days']);
        $this->challenge(40, 'consumed');
        $this->challenge(20, 'consumed');

        config(['family_auth.retention.otp_challenge_days' => 30]);
        $this->purge();
        $this->assertSame(1, AuthOtpChallenge::count());

        // An explicit option overrides the configuration.
        $this->purge(['--days' => 10]);
        $this->assertSame(0, AuthOtpChallenge::count());
    }

    public function test_an_invalid_retention_period_deletes_nothing(): void
    {
        $this->challenge(400, 'consumed');

        foreach (['0', '-5', 'abc'] as $days) {
            $this->artisan('famboook:purge-otp-challenges', ['--days' => $days])->assertExitCode(1);
        }
        config(['family_auth.retention.otp_challenge_days' => 0]);
        $this->artisan('famboook:purge-otp-challenges')->assertExitCode(1);

        $this->assertSame(1, AuthOtpChallenge::count());
    }

    public function test_the_purge_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'famboook:purge-otp-challenges'));

        $this->assertCount(1, $events);
        // Daily at midnight.
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }
}
