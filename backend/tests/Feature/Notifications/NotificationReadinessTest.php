<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationHeartbeatState;
use App\Enums\NotificationMode;
use App\Enums\NotificationReadinessReason as Reason;
use App\Models\User;
use App\Support\Notifications\NotificationConfig;
use App\Support\Notifications\NotificationHeartbeat;
use App\Support\Notifications\NotificationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationReadinessFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b: NotificationConfig fails closed and NotificationReadiness
 * refuses unless the mode, the sender (PILOT), an asynchronous queue, a fresh
 * heartbeat and a valid configuration all allow it — and SYSTEM discovery
 * also needs its gate and a valid cutoff. Read-only. Synthetic data only.
 */
class NotificationReadinessTest extends TestCase
{
    use NotificationReadinessFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationDefaults();
    }

    public function test_the_shipped_configuration_is_off_with_both_gates_closed(): void
    {
        $shipped = require config_path('notifications.php');

        $this->assertSame('OFF', $shipped['mode']);
        $this->assertSame('', $shipped['pilot_sender_user_ids']);
        $this->assertFalse($shipped['system_discovery_enabled']);
        $this->assertFalse($shipped['followups_enabled']);
        $this->assertNull($shipped['system_cutoff']);
        $this->assertSame('notifications', $shipped['queue']);
        $this->assertSame(300, (int) $shipped['heartbeat_max_age_seconds']);
        $this->assertSame(3, (int) $shipped['max_publish_attempts']);
        $this->assertSame(600, (int) $shipped['stale_publishing_seconds']);
    }

    public function test_off_refuses_everything_even_when_the_infrastructure_is_ready(): void
    {
        $this->infrastructureReady();
        $sender = User::factory()->create();

        $this->assertSame(NotificationMode::OFF, NotificationConfig::mode());
        $this->assertSame([], NotificationReadiness::infrastructure());
        $this->assertSame([Reason::MODE_OFF], NotificationReadiness::forSender($sender));
        $this->assertContains(Reason::MODE_OFF, NotificationReadiness::forSystemDiscovery());
        $this->assertContains(Reason::MODE_OFF, NotificationReadiness::forFollowups());
    }

    public function test_on_is_ready_for_any_sender(): void
    {
        $this->infrastructureReady();
        config(['notifications.mode' => 'ON']);

        $this->assertSame([], NotificationReadiness::forPublication());
        $this->assertSame([], NotificationReadiness::forSender(User::factory()->create()));
    }

    public function test_pilot_admits_only_allow_listed_senders(): void
    {
        $this->infrastructureReady();
        [$listed, $other] = [User::factory()->create(), User::factory()->create()];
        config(['notifications.mode' => ' pilot ', 'notifications.pilot_sender_user_ids' => " {$listed->id} , 999999 "]);

        $this->assertSame(NotificationMode::PILOT, NotificationConfig::mode());
        $this->assertSame([], NotificationReadiness::forSender($listed));
        $this->assertSame([Reason::SENDER_NOT_IN_PILOT], NotificationReadiness::forSender($other));
    }

    public function test_an_empty_pilot_allow_list_admits_nobody(): void
    {
        $this->infrastructureReady();
        config(['notifications.mode' => 'PILOT']);

        $this->assertSame([Reason::SENDER_NOT_IN_PILOT], NotificationReadiness::forSender(User::factory()->create()));
    }

    public function test_a_malformed_pilot_allow_list_admits_nobody_and_is_invalid_configuration(): void
    {
        $this->infrastructureReady();
        $sender = User::factory()->create();
        config(['notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => "{$sender->id},abc"]);

        $this->assertNull(NotificationConfig::pilotSenderUserIds());
        $this->assertSame(['pilot_sender_user_ids'], NotificationConfig::invalidKeys());
        $this->assertSame([Reason::CONFIG_INVALID, Reason::SENDER_NOT_IN_PILOT], NotificationReadiness::forSender($sender));
    }

    public function test_an_invalid_mode_is_off_and_invalid_configuration(): void
    {
        $this->infrastructureReady();
        foreach (['yes', 'GENERAL', '', null, true] as $raw) {
            config(['notifications.mode' => $raw]);

            $this->assertSame(NotificationMode::OFF, NotificationConfig::mode());
            $this->assertSame([Reason::CONFIG_INVALID, Reason::MODE_OFF], NotificationReadiness::forPublication());
        }
    }

    public function test_every_invalid_required_value_fails_closed(): void
    {
        $this->infrastructureReady();
        $invalid = [
            'system_discovery_enabled' => '1',
            'followups_enabled' => 'yes',
            'queue' => 'Bad Queue',
            'heartbeat_max_age_seconds' => 60,
            'max_publish_attempts' => 0,
            'stale_publishing_seconds' => 'ten',
        ];
        foreach ($invalid as $key => $value) {
            $this->notificationDefaults();
            config(['notifications.mode' => 'ON', "notifications.{$key}" => $value]);

            $this->assertSame([$key], NotificationConfig::invalidKeys(), $key);
            $this->assertContains(Reason::CONFIG_INVALID, NotificationReadiness::forPublication(), $key);
        }
        // A non-boolean gate never opens.
        $this->assertFalse(NotificationConfig::systemDiscoveryEnabled());
    }

    public function test_numeric_strings_from_the_environment_are_accepted_within_bounds(): void
    {
        config([
            'notifications.heartbeat_max_age_seconds' => '120',
            'notifications.max_publish_attempts' => '10',
            'notifications.stale_publishing_seconds' => ' 86400 ',
        ]);

        $this->assertSame([], NotificationConfig::invalidKeys());
        $this->assertSame(120, NotificationConfig::heartbeatMaxAgeSeconds());
        $this->assertSame(10, NotificationConfig::maxPublishAttempts());
        $this->assertSame(86400, NotificationConfig::stalePublishingSeconds());
    }

    public function test_a_sync_or_null_or_undefined_queue_connection_is_refused(): void
    {
        $this->freshHeartbeat();
        config(['notifications.mode' => 'ON']);

        foreach (['sync', 'null', 'missing-connection'] as $connection) {
            config(['queue.default' => $connection, 'queue.connections.null' => ['driver' => 'null']]);

            $this->assertFalse(NotificationReadiness::queueIsAsynchronous(), $connection);
            $this->assertSame([Reason::QUEUE_NOT_ASYNC], NotificationReadiness::forPublication(), $connection);
        }
    }

    public function test_a_missing_heartbeat_is_refused(): void
    {
        config(['queue.default' => 'database', 'notifications.mode' => 'ON']);

        $this->assertSame(NotificationHeartbeatState::ABSENT, NotificationHeartbeat::state());
        $this->assertSame([Reason::HEARTBEAT_ABSENT], NotificationReadiness::forPublication());
    }

    public function test_a_heartbeat_is_fresh_up_to_the_maximum_age_then_stale(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->infrastructureReady();
        config(['notifications.mode' => 'ON']);

        $this->travel(300)->seconds();
        $this->assertSame(NotificationHeartbeatState::FRESH, NotificationHeartbeat::state());
        $this->assertSame([], NotificationReadiness::forPublication());

        $this->travel(1)->seconds();
        $this->assertSame(NotificationHeartbeatState::STALE, NotificationHeartbeat::state());
        $this->assertSame([Reason::HEARTBEAT_STALE], NotificationReadiness::forPublication());
    }

    public function test_a_heartbeat_from_the_future_or_a_foreign_value_is_not_fresh(): void
    {
        config(['queue.default' => 'database', 'notifications.mode' => 'ON']);

        Cache::put(NotificationHeartbeat::CACHE_KEY, now()->getTimestamp() + 60, 60);
        $this->assertSame(NotificationHeartbeatState::STALE, NotificationHeartbeat::state());

        Cache::put(NotificationHeartbeat::CACHE_KEY, (string) now()->getTimestamp(), 60);
        $this->assertSame(NotificationHeartbeatState::ABSENT, NotificationHeartbeat::state());
    }

    public function test_system_discovery_needs_its_gate_and_a_valid_cutoff(): void
    {
        $this->infrastructureReady();
        config(['notifications.mode' => 'ON']);

        $this->assertSame([Reason::SYSTEM_DISCOVERY_DISABLED, Reason::SYSTEM_CUTOFF_MISSING], NotificationReadiness::forSystemDiscovery());

        config(['notifications.system_discovery_enabled' => true]);
        $this->assertSame([Reason::SYSTEM_CUTOFF_MISSING], NotificationReadiness::forSystemDiscovery());

        foreach (['2026-11-01', '2026-02-30T00:00:00Z', '2026-11-01T25:00:00+02:00', '2026-11-01 00:00:00', 'tomorrow'] as $raw) {
            config(['notifications.system_cutoff' => $raw]);
            $this->assertSame([Reason::SYSTEM_CUTOFF_INVALID], NotificationReadiness::forSystemDiscovery(), $raw);
        }

        config(['notifications.system_cutoff' => '2026-11-01T00:00:00+02:00']);
        $this->assertSame([], NotificationReadiness::forSystemDiscovery());
        $this->assertSame('2026-10-31 22:00:00', NotificationConfig::systemCutoff()->format('Y-m-d H:i:s'));

        // The SYSTEM gate never opens anything while the mode is OFF.
        config(['notifications.mode' => 'OFF']);
        $this->assertSame([Reason::MODE_OFF], NotificationReadiness::forSystemDiscovery());
    }

    public function test_pilot_never_opens_system_discovery_but_keeps_manual_pilot_publication(): void
    {
        $this->infrastructureReady();
        [$listed, $other] = [User::factory()->create(), User::factory()->create()];
        config([
            'notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => (string) $listed->id,
            'notifications.system_discovery_enabled' => true, 'notifications.system_cutoff' => '2026-11-01T00:00:00Z',
            'notifications.followups_enabled' => true,
        ]);

        $this->assertSame([Reason::SYSTEM_DISCOVERY_REQUIRES_ON], NotificationReadiness::forSystemDiscovery());
        // Manual publication and follow-ups keep their PILOT behaviour.
        $this->assertSame([], NotificationReadiness::forSender($listed));
        $this->assertSame([Reason::SENDER_NOT_IN_PILOT], NotificationReadiness::forSender($other));
        $this->assertSame([], NotificationReadiness::forFollowups());

        config(['notifications.mode' => 'ON']);
        $this->assertSame([], NotificationReadiness::forSystemDiscovery());
    }

    public function test_off_refuses_system_discovery_even_with_its_gate_and_cutoff(): void
    {
        $this->infrastructureReady();
        config(['notifications.system_discovery_enabled' => true, 'notifications.system_cutoff' => '2026-11-01T00:00:00Z']);

        $this->assertSame([Reason::MODE_OFF], NotificationReadiness::forSystemDiscovery());
    }

    public function test_follow_ups_need_their_own_gate(): void
    {
        $this->infrastructureReady();
        config(['notifications.mode' => 'ON', 'notifications.system_discovery_enabled' => true]);

        $this->assertSame([Reason::FOLLOWUPS_DISABLED], NotificationReadiness::forFollowups());

        config(['notifications.followups_enabled' => true]);
        $this->assertSame([], NotificationReadiness::forFollowups());
    }

    public function test_readiness_publishes_nothing_dispatches_nothing_and_writes_nothing(): void
    {
        $this->infrastructureReady();
        Queue::fake();
        $sender = User::factory()->create();
        config(['notifications.mode' => 'ON', 'notifications.system_discovery_enabled' => true, 'notifications.system_cutoff' => '2026-11-01T00:00:00Z']);

        $writes = $this->writesDuring(function () use ($sender) {
            NotificationReadiness::forSender($sender);
            NotificationReadiness::forSystemDiscovery();
            NotificationReadiness::forFollowups();
        });

        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
        foreach (['notification_messages', 'notification_recipients', 'notification_followups', 'notification_recipient_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
