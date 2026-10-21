<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationFailureCode;
use App\Enums\NotificationReadinessReason;
use App\Jobs\PublishNotificationJob;
use App\Models\Branch;
use App\Models\Clan;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationConfig;
use App\Support\Notifications\NotificationPublisher;
use App\Support\Notifications\NotificationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01 pilot isolation (ADR §2h): in PILOT a manual announcement may
 * address only the pilot households (NOTIFICATIONS_PILOT_FAMILY_IDS), one by
 * one — never a whole coordinator scope, every family or a structure — on
 * every path (draft, preview, publication request, retry, worker); OFF and ON
 * are unchanged. /family/me reports whether to SHOW the family notification UI
 * (C1) and the coordinator's notification screens (C3); visibility only.
 * Synthetic data only.
 */
class NotificationPilotIsolationTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private const STAFF = '/api/v1/notifications';

    private const COORDINATOR = '/api/v1/family/coordinator/notifications';

    private User $sender;

    private Clan $pilotClan;

    private Branch $pilotBranch;

    private Family $pilotFamily;

    private Family $otherFamily;

    /** @var list<int> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $this->sender = $this->administrator();
        $this->pilotClan = $this->clan('PILOT_CLAN');
        $this->pilotBranch = $this->branch($this->pilotClan);
        $this->pilotFamily = $this->familyIn($this->pilotClan, $this->pilotBranch);
        $this->otherFamily = $this->familyIn($this->pilotClan, $this->pilotBranch);
    }

    private function pilot(array $senders = [], array $families = []): void
    {
        config([
            'notifications.mode' => 'PILOT',
            'notifications.pilot_sender_user_ids' => implode(',', array_map(fn (User $u) => $u->id, $senders)),
            'notifications.pilot_family_ids' => implode(',', array_map(fn (Family $f) => $f->id, $families)),
        ]);
    }

    private function as(User $user, string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($user->fresh())->json($method, $uri, $body);
    }

    private function staffDraft(array $audience, ?User $sender = null): TestResponse
    {
        return $this->as($sender ?? $this->sender, 'POST', self::STAFF, [
            'title' => 'إعلان التجربة', 'body' => 'نص.', 'audience' => $audience, 'client_reference' => (string) Str::uuid(),
        ]);
    }

    private function codes(Family ...$families): array
    {
        return array_map(fn (Family $f) => $f->family_code, $families);
    }

    private function runWorker(): void
    {
        foreach (array_diff($this->dispatcher->ids(), $this->ran) as $id) {
            $this->ran[] = $id;
            (new PublishNotificationJob($id))->handle(app(NotificationPublisher::class));
        }
    }

    private function message(string $uuid): NotificationMessage
    {
        return NotificationMessage::query()->where('uuid', $uuid)->sole();
    }

    // ------------------------------------------------------------- config

    public function test_the_pilot_household_list_parses_like_the_sender_list_and_fails_closed(): void
    {
        config(['notifications.pilot_family_ids' => ' 12, 7,12 ']);
        $this->assertSame([12, 7], NotificationConfig::pilotFamilyIds());
        config(['notifications.pilot_family_ids' => '']);
        $this->assertSame([], NotificationConfig::pilotFamilyIds());

        config(['notifications.pilot_family_ids' => '12,abc']);
        $this->assertNull(NotificationConfig::pilotFamilyIds());
        $this->assertContains('pilot_family_ids', NotificationConfig::invalidKeys());
        $this->assertContains(NotificationReadinessReason::CONFIG_INVALID, NotificationReadiness::forPublication(), 'a malformed list closes publication');
    }

    public function test_the_check_command_reports_the_household_list_by_count_only(): void
    {
        $this->pilot([$this->sender], [$this->pilotFamily, $this->otherFamily]);
        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Pilot household allow-list: 2 id(s)')
            ->doesntExpectOutputToContain((string) $this->pilotFamily->id.',');

        $this->pilot([$this->sender], []);
        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('Pilot household allow-list: empty')
            ->expectsOutputToContain('WARN: PILOT mode with no usable pilot household: no announcement can be addressed.');
    }

    // ----------------------------------------------------- Staff audiences

    public function test_in_pilot_staff_may_address_only_pilot_households_one_by_one(): void
    {
        $this->pilot([$this->sender], [$this->pilotFamily]);

        // Preview and draft: the pilot household is accepted.
        $this->as($this->sender, 'POST', self::STAFF.'/audience-preview', ['audience' => ['type' => 'STAFF_FAMILIES', 'family_codes' => $this->codes($this->pilotFamily)]])
            ->assertOk()->assertJsonPath('data.recipient_count', 1);
        $uuid = $this->staffDraft(['type' => 'STAFF_FAMILIES', 'family_codes' => $this->codes($this->pilotFamily)])->assertCreated()->json('data.id');

        // Any family outside the list — alone or mixed in — is refused, at preview and at draft.
        foreach ([[$this->otherFamily], [$this->pilotFamily, $this->otherFamily]] as $families) {
            $this->as($this->sender, 'POST', self::STAFF.'/audience-preview', ['audience' => ['type' => 'STAFF_FAMILIES', 'family_codes' => $this->codes(...$families)]])
                ->assertStatus(422)->assertJsonPath('code', 'NOTIFICATION_AUDIENCE_NOT_IN_PILOT');
            $this->staffDraft(['type' => 'STAFF_FAMILIES', 'family_codes' => $this->codes(...$families)])
                ->assertStatus(422)->assertJsonPath('code', 'NOTIFICATION_AUDIENCE_NOT_IN_PILOT');
        }

        // Publication reaches the pilot household only.
        $this->as($this->sender, 'POST', self::STAFF."/{$uuid}/publish")->assertStatus(202);
        $this->runWorker();
        $message = $this->message($uuid);
        $this->assertSame('PUBLISHED', $message->status->value);
        $this->assertSame([$this->pilotFamily->id], NotificationRecipient::query()->where('message_id', $message->id)->pluck('family_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_in_pilot_every_active_family_is_refused_even_with_send_all(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findByName('SUPER_ADMIN', 'web'));
        $this->pilot([$superAdmin], [$this->pilotFamily]);

        $this->as($superAdmin, 'POST', self::STAFF.'/audience-preview', ['audience' => ['type' => 'STAFF_ALL_ACTIVE']])
            ->assertStatus(422)->assertJsonPath('code', 'NOTIFICATION_AUDIENCE_NOT_IN_PILOT');
        $this->staffDraft(['type' => 'STAFF_ALL_ACTIVE'], $superAdmin)
            ->assertStatus(422)->assertJsonPath('code', 'NOTIFICATION_AUDIENCE_NOT_IN_PILOT');
        $this->assertSame(0, NotificationMessage::query()->count());
    }

    public function test_an_empty_or_malformed_household_list_addresses_nobody_in_pilot(): void
    {
        foreach (['', '5,x'] as $list) {
            config(['notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => (string) $this->sender->id, 'notifications.pilot_family_ids' => $list]);
            $response = $this->staffDraft(['type' => 'STAFF_FAMILIES', 'family_codes' => $this->codes($this->pilotFamily)]);
            $this->assertSame(422, $response->status(), "list '{$list}'");
            $this->assertSame('NOTIFICATION_AUDIENCE_NOT_IN_PILOT', $response->json('code'));
        }
    }

    // ------------------------------------------------ coordinator audiences

    public function test_in_pilot_a_coordinator_may_not_address_the_whole_scope_only_pilot_households(): void
    {
        $coordinator = $this->coordinatorSender($this->pilotBranch, '111111110', NotificationActors::COORDINATOR_CREATE, NotificationActors::COORDINATOR_SEND, 'coordinator-announcement.view');
        $this->pilot([$coordinator], [$this->pilotFamily]);

        $this->as($coordinator, 'POST', self::COORDINATOR.'/audience-preview', ['audience' => ['type' => 'COORDINATOR_SCOPE']])
            ->assertStatus(422)->assertJsonPath('code', 'NOTIFICATION_AUDIENCE_NOT_IN_PILOT');
        $this->as($coordinator, 'POST', self::COORDINATOR, ['title' => 'إعلان', 'body' => 'نص.', 'audience' => ['type' => 'COORDINATOR_SCOPE']])
            ->assertStatus(422)->assertJsonPath('code', 'NOTIFICATION_AUDIENCE_NOT_IN_PILOT');
        $this->as($coordinator, 'POST', self::COORDINATOR, ['title' => 'إعلان', 'body' => 'نص.', 'audience' => ['type' => 'COORDINATOR_SELECTION', 'family_codes' => $this->codes($this->otherFamily)]])
            ->assertStatus(422)->assertJsonPath('code', 'NOTIFICATION_AUDIENCE_NOT_IN_PILOT');

        $uuid = $this->as($coordinator, 'POST', self::COORDINATOR, ['title' => 'إعلان', 'body' => 'نص.', 'audience' => ['type' => 'COORDINATOR_SELECTION', 'family_codes' => $this->codes($this->pilotFamily)]])
            ->assertCreated()->json('data.id');
        $this->as($coordinator, 'POST', self::COORDINATOR."/{$uuid}/publish")->assertStatus(202);
        $this->runWorker();
        $this->assertSame([$this->pilotFamily->id], NotificationRecipient::query()->where('message_id', $this->message($uuid)->id)->pluck('family_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_scope_rule_still_applies_inside_the_pilot(): void
    {
        $outside = Family::factory()->create();
        $coordinator = $this->coordinatorSender($this->pilotBranch, '111111110', NotificationActors::COORDINATOR_CREATE, NotificationActors::COORDINATOR_SEND);
        $this->pilot([$coordinator], [$this->pilotFamily, $outside]);

        // A pilot household outside the coordinator's scope is still refused (scope, not pilot).
        $this->as($coordinator, 'POST', self::COORDINATOR, ['title' => 'إعلان', 'body' => 'نص.', 'audience' => ['type' => 'COORDINATOR_SELECTION', 'family_codes' => $this->codes($outside)]])
            ->assertStatus(422)->assertJsonPath('code', 'NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE');
    }

    // ------------------------------------------- request, retry and worker

    public function test_a_list_change_after_the_draft_is_caught_at_the_request_and_at_publication(): void
    {
        $this->pilot([$this->sender], [$this->pilotFamily, $this->otherFamily]);
        $requested = $this->staffDraft(['type' => 'STAFF_FAMILIES', 'family_codes' => $this->codes($this->otherFamily)])->assertCreated()->json('data.id');
        $queued = $this->staffDraft(['type' => 'STAFF_FAMILIES', 'family_codes' => $this->codes($this->otherFamily)])->assertCreated()->json('data.id');
        $this->as($this->sender, 'POST', self::STAFF."/{$queued}/publish")->assertStatus(202);

        // The household leaves the pilot.
        $this->pilot([$this->sender], [$this->pilotFamily]);

        // At the request: refused, nothing queued.
        $this->as($this->sender, 'POST', self::STAFF."/{$requested}/publish")->assertStatus(422)->assertJsonPath('code', 'NOTIFICATION_AUDIENCE_NOT_IN_PILOT');
        $this->assertSame('DRAFT', $this->message($requested)->status->value);

        // At publication (the worker re-resolves): a permanent AUDIENCE_INVALID, no recipient.
        $this->runWorker();
        $message = $this->message($queued);
        $this->assertSame('PUBLISH_FAILED', $message->status->value);
        $this->assertSame(NotificationFailureCode::AUDIENCE_INVALID->value, $message->publish_failure_code);
        $this->assertSame(0, NotificationRecipient::query()->where('message_id', $message->id)->count());
    }

    public function test_a_manual_retry_is_refused_outside_the_pilot_households(): void
    {
        config(['notifications.mode' => 'ON']);
        $uuid = $this->staffDraft(['type' => 'STAFF_FAMILIES', 'family_codes' => $this->codes($this->otherFamily)])->assertCreated()->json('data.id');
        $this->as($this->sender, 'POST', self::STAFF."/{$uuid}/publish")->assertStatus(202);
        $this->failPublication($this->message($uuid), NotificationFailureCode::AUDIENCE_EMPTY);

        $this->pilot([$this->sender], [$this->pilotFamily]);
        $this->as($this->sender, 'POST', self::STAFF."/{$uuid}/retry")->assertStatus(422)->assertJsonPath('code', 'NOTIFICATION_AUDIENCE_NOT_IN_PILOT');
        $this->assertSame('PUBLISH_FAILED', $this->message($uuid)->status->value);
    }

    // --------------------------------------------------------- OFF and ON

    public function test_on_and_off_keep_their_rules(): void
    {
        $coordinator = $this->coordinatorSender($this->pilotBranch, '111111110', NotificationActors::COORDINATOR_CREATE, NotificationActors::COORDINATOR_SEND);
        config(['notifications.pilot_family_ids' => (string) $this->pilotFamily->id]);

        foreach (['ON', 'OFF'] as $mode) {
            config(['notifications.mode' => $mode]);
            $this->staffDraft(['type' => 'STAFF_FAMILIES', 'family_codes' => $this->codes($this->otherFamily)])->assertCreated();
            $this->as($coordinator, 'POST', self::COORDINATOR, ['title' => 'إعلان', 'body' => 'نص.', 'audience' => ['type' => 'COORDINATOR_SCOPE']])->assertCreated();
        }
        // ON publishes the whole scope as before.
        config(['notifications.mode' => 'ON']);
        $uuid = $this->as($coordinator, 'POST', self::COORDINATOR, ['title' => 'إعلان', 'body' => 'نص.', 'audience' => ['type' => 'COORDINATOR_SCOPE']])->assertCreated()->json('data.id');
        $this->as($coordinator, 'POST', self::COORDINATOR."/{$uuid}/publish")->assertStatus(202);
        $this->runWorker();
        $this->assertSame(2, NotificationRecipient::query()->where('message_id', $this->message($uuid)->id)->count());
    }

    // ------------------------------------------------- visibility (C1, C3)

    private function me(User $user): array
    {
        return $this->actingAs($user->fresh())->getJson('/api/v1/family/me')->assertOk()->json('user');
    }

    public function test_family_visibility_follows_the_mode_the_household_list_and_existing_notifications(): void
    {
        $pilotHead = $this->activatedHead('123456789');
        $otherHead = $this->activatedHead('223456789');
        $historyHead = $this->activatedHead('323456789');

        // A household that legitimately received a notification while ON.
        config(['notifications.mode' => 'ON']);
        $uuid = $this->staffDraft(['type' => 'STAFF_FAMILIES', 'family_codes' => $this->codes($historyHead['family'])])->assertCreated()->json('data.id');
        $this->as($this->sender, 'POST', self::STAFF."/{$uuid}/publish")->assertStatus(202);
        $this->runWorker();

        config(['notifications.mode' => 'OFF']);
        foreach ([$pilotHead, $otherHead, $historyHead] as $head) {
            $this->assertFalse($this->me($head['user'])['notifications'], 'OFF shows nothing');
        }

        $this->pilot([$this->sender], [$pilotHead['family']]);
        $this->assertTrue($this->me($pilotHead['user'])['notifications'], 'a pilot household');
        $this->assertFalse($this->me($otherHead['user'])['notifications'], 'neither listed nor holding notifications');
        $this->assertTrue($this->me($historyHead['user'])['notifications'], 'its own published history stays reachable');

        config(['notifications.pilot_family_ids' => '1,x']);
        $this->assertFalse($this->me($pilotHead['user'])['notifications'], 'invalid configuration shows nothing');

        config(['notifications.mode' => 'ON', 'notifications.pilot_family_ids' => '']);
        $this->assertTrue($this->me($otherHead['user'])['notifications'], 'ON: every household, even with an empty inbox');
    }

    public function test_coordinator_visibility_follows_the_mode_and_the_sender_list(): void
    {
        $listed = $this->coordinatorSender($this->pilotBranch, '111111110');
        $unlisted = $this->coordinatorSender($this->pilotBranch, '211111110');
        $head = $this->activatedHead('123456789');

        config(['notifications.mode' => 'OFF']);
        $this->assertFalse($this->me($listed)['coordinator_notifications']);

        $this->pilot([$listed], [$this->pilotFamily]);
        $this->assertTrue($this->me($listed)['coordinator_notifications']);
        $this->assertFalse($this->me($unlisted)['coordinator_notifications']);
        $this->assertFalse($this->me($head['user'])['coordinator_notifications'], 'not a coordinator');

        config(['notifications.mode' => 'ON']);
        $this->assertTrue($this->me($unlisted)['coordinator_notifications']);
        $this->assertFalse($this->me($head['user'])['coordinator_notifications']);
    }

    public function test_visibility_never_replaces_authorization(): void
    {
        // Hidden UI, same API: a non-pilot household still reads its own (empty) inbox, and an
        // unlisted coordinator's publication is still refused by readiness, not by the flag.
        $head = $this->activatedHead('123456789');
        $unlisted = $this->coordinatorSender($this->pilotBranch, '111111110', NotificationActors::COORDINATOR_CREATE, NotificationActors::COORDINATOR_SEND);
        $this->pilot([$this->sender], [$this->pilotFamily]);

        $this->assertFalse($this->me($head['user'])['notifications']);
        $this->actingAs($head['user']->fresh())->getJson('/api/v1/family/notifications')->assertOk()->assertJsonPath('meta.unread_count', 0);

        $uuid = $this->as($unlisted, 'POST', self::COORDINATOR, ['title' => 'إعلان', 'body' => 'نص.', 'audience' => ['type' => 'COORDINATOR_SELECTION', 'family_codes' => $this->codes($this->pilotFamily)]])
            ->assertCreated()->json('data.id');
        $this->as($unlisted, 'POST', self::COORDINATOR."/{$uuid}/publish")->assertStatus(503);
    }
}
