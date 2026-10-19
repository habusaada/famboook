<?php

namespace Tests\Feature\Notifications;

use App\Http\Middleware\EnsureNotificationPublicationReady;
use App\Models\User;
use App\Support\Notifications\NotificationHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\NotificationReadinessFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b: the `notifications.publication-ready` middleware fails
 * closed with one generic 503 code, and passing it authorizes nothing. The
 * routes exist only inside this test: the application defines no
 * notification route yet. Synthetic data only.
 */
class NotificationPublicationReadyMiddlewareTest extends TestCase
{
    use NotificationReadinessFixtures, RefreshDatabase;

    private User $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationDefaults();
        $this->infrastructureReady();
        config(['notifications.mode' => 'ON']);
        $this->sender = User::factory()->create();

        Route::middleware(['api', 'auth:sanctum', 'notifications.publication-ready'])
            ->post('/api/v1/__test/notification-ready', fn () => response()->json(['passed' => true]));
        Route::middleware(['api', 'notifications.publication-ready'])
            ->post('/api/v1/__test/notification-ready-guest', fn () => response()->json(['passed' => true]));
        Route::middleware(['api', 'auth:sanctum', 'notifications.publication-ready', 'can:notification.publish-test-only'])
            ->post('/api/v1/__test/notification-ready-authorized', fn () => response()->json(['passed' => true]));
    }

    private function publish(string $path = '/api/v1/__test/notification-ready'): TestResponse
    {
        return $this->actingAs($this->sender, 'sanctum')->postJson($path);
    }

    private function assertRefused(TestResponse $response): void
    {
        $response->assertStatus(503)
            ->assertExactJson(['message' => 'نشر الإشعارات غير متاح حاليًا.', 'code' => EnsureNotificationPublicationReady::CODE])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_alias_is_registered(): void
    {
        $this->assertSame(EnsureNotificationPublicationReady::class, app('router')->getMiddleware()['notifications.publication-ready']);
    }

    public function test_a_ready_system_lets_the_request_through(): void
    {
        $this->publish()->assertOk()->assertJson(['passed' => true]);
    }

    public function test_off_is_refused(): void
    {
        config(['notifications.mode' => 'OFF']);
        $this->assertRefused($this->publish());
    }

    public function test_pilot_refuses_a_sender_not_on_the_allow_list_and_admits_one_on_it(): void
    {
        config(['notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => '999999']);
        $this->assertRefused($this->publish());

        config(['notifications.pilot_sender_user_ids' => (string) $this->sender->id]);
        $this->publish()->assertOk();
    }

    public function test_a_sync_queue_is_refused(): void
    {
        config(['queue.default' => 'sync']);
        $this->assertRefused($this->publish());
    }

    public function test_a_stale_or_absent_heartbeat_is_refused(): void
    {
        $this->travel(301)->seconds();
        $this->assertRefused($this->publish());

        cache()->forget(NotificationHeartbeat::CACHE_KEY);
        $this->assertRefused($this->publish());
    }

    public function test_invalid_configuration_is_refused(): void
    {
        config(['notifications.automatic_publish_attempts' => 99]);
        $this->assertRefused($this->publish());
    }

    public function test_without_a_signed_in_sender_it_refuses(): void
    {
        $this->assertRefused($this->postJson('/api/v1/__test/notification-ready-guest'));
    }

    public function test_readiness_is_not_authorization(): void
    {
        $this->publish('/api/v1/__test/notification-ready-authorized')->assertForbidden();
    }

    public function test_the_middleware_dispatches_nothing_and_writes_nothing(): void
    {
        Queue::fake();
        $writes = $this->writesDuring(fn () => $this->publish()->assertOk());

        Queue::assertNothingPushed();
        $this->assertSame([], array_values(array_filter($writes, fn (string $sql) => str_contains($sql, 'notification_'))));
    }
}
