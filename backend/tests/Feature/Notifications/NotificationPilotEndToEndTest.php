<?php

namespace Tests\Feature\Notifications;

use App\Actions\RevokeCoordinatorScopeAction;
use App\Enums\CoordinatorRevokeReason;
use App\Jobs\PublishNotificationJob;
use App\Models\Family;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationExpirySweeper;
use App\Support\Notifications\NotificationFollowupEngine;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01E.4 (PILOT readiness): the notification and follow-up system
 * chained end to end through the real entry points — the Staff and
 * Coordinator HTTP APIs, the publication job as the worker runs it, the
 * family HTTP view / acknowledgement, the expiry sweep and the follow-up
 * engine under a controlled clock, and the follow-up management APIs. Each
 * stage starts from the previous stage's real output (no hand-built rows).
 * Synthetic data only.
 */
class NotificationPilotEndToEndTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private const STAFF_NOTIFICATIONS = '/api/v1/notifications';

    private const COORDINATOR_NOTIFICATIONS = '/api/v1/family/coordinator/notifications';

    private const STAFF_FOLLOWUPS = '/api/v1/notifications/followups';

    private const COORDINATOR_FOLLOWUPS = '/api/v1/family/coordinator/followups';

    private const FAMILY_NOTIFICATIONS = '/api/v1/family/notifications';

    private User $administrator;

    /** @var list<int> publication jobs already run */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        config(['notifications.followups_enabled' => true, 'notifications.followups_cutoff' => '2026-10-01T00:00:00Z']);
        $this->administrator = User::factory()->create();
        $this->administrator->assignRole('ADMINISTRATOR');
    }

    private function as(User $user, string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($user->fresh())->json($method, $uri, $body);
    }

    /** Every job the dispatcher received since the last call, run as the worker would. */
    private function runWorker(): void
    {
        foreach (array_diff($this->dispatcher->ids(), $this->ran) as $id) {
            $this->ran[] = $id;
            (new PublishNotificationJob($id))->handle(app(NotificationPublisher::class));
        }
    }

    /** The scheduler's minute: the heartbeat, the expiry sweep, then the follow-up engine. */
    private function schedulerTick(): array
    {
        $this->freshHeartbeat();
        app(NotificationExpirySweeper::class)->run();

        return app(NotificationFollowupEngine::class)->run();
    }

    /** A Staff announcement through the HTTP API, published by the worker. */
    private function staffAnnouncement(string $level, array $families, string $expiresIn = '+10 days'): string
    {
        // The worker's heartbeat, as the scheduler keeps it fresh (publication needs it).
        $this->freshHeartbeat();
        $uuid = $this->as($this->administrator, 'POST', self::STAFF_NOTIFICATIONS, [
            'title' => 'إعلان تجريبي للتجربة',
            'body' => 'نص إعلان تجريبي.',
            'level' => $level,
            'audience' => ['type' => 'STAFF_FAMILIES', 'family_codes' => array_map(fn (Family $f) => $f->family_code, $families)],
            'expires_at' => now()->modify($expiresIn)->toIso8601String(),
            'client_reference' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        $this->as($this->administrator, 'POST', self::STAFF_NOTIFICATIONS."/{$uuid}/publish")->assertStatus(202);
        $this->runWorker();
        $this->as($this->administrator, 'GET', self::STAFF_NOTIFICATIONS."/{$uuid}")->assertOk()->assertJsonPath('data.status', 'PUBLISHED');

        return $uuid;
    }

    private function followup(string $messageUuid, Family $family, string $stage): ?NotificationFollowup
    {
        $message = NotificationMessage::query()->where('uuid', $messageUuid)->sole();
        $recipient = NotificationRecipient::query()->where('message_id', $message->id)->where('family_id', $family->id)->sole();

        return NotificationFollowup::query()->where('recipient_id', $recipient->id)->where('stage', $stage)->first();
    }

    private function contact(string $outcome = 'CALLED_NO_ANSWER'): array
    {
        return ['outcome' => $outcome, 'client_reference' => (string) Str::uuid()];
    }

    // ---------------------------------------------------------------- Staff

    public function test_staff_important_announcement_through_family_acknowledgement_both_stages_and_staff_management(): void
    {
        $reached = $this->activatedHead('123456789');
        $unreached = Family::factory()->create();
        $uuid = $this->staffAnnouncement('IMPORTANT', [$reached['family'], $unreached]);
        $published = now()->toImmutable();

        // The family receives it and views it: viewing never satisfies IMPORTANT.
        $inbox = $this->as($reached['user'], 'GET', self::FAMILY_NOTIFICATIONS)->assertOk();
        $this->assertSame([$uuid], array_column($inbox->json('data'), 'id'));
        $this->as($reached['user'], 'POST', self::FAMILY_NOTIFICATIONS."/{$uuid}/view")->assertOk();

        // +24h − 1s: nothing; +24h: FIRST opens for both (viewed is not acknowledged).
        $this->travel(24 * 3600 - 1)->seconds();
        $this->assertSame(0, $this->schedulerTick()['opened']);
        $this->travel(1)->seconds();
        $this->assertSame(2, $this->schedulerTick()['opened']);
        $this->assertSame('OPEN', $this->followup($uuid, $reached['family'], 'FIRST')->status->value);

        // The family acknowledges over HTTP: its OPEN FIRST is SATISFIED.
        $this->as($reached['user'], 'POST', self::FAMILY_NOTIFICATIONS."/{$uuid}/acknowledge")->assertOk();
        $this->assertSame('SATISFIED', $this->followup($uuid, $reached['family'], 'FIRST')->outcome->value);

        // +72h: SECOND opens only for the family that is still unacknowledged.
        $this->travelTo($published->addHours(72));
        $this->assertSame(1, $this->schedulerTick()['opened']);
        $this->assertNull($this->followup($uuid, $reached['family'], 'SECOND'));
        $second = $this->followup($uuid, $unreached, 'SECOND');
        $this->assertSame('OPEN', $second->status->value);

        // Staff manage it through the API: a contact, then a manual closure.
        $listed = $this->as($this->administrator, 'GET', self::STAFF_FOLLOWUPS.'?stage=SECOND')->assertOk();
        $this->assertSame([$second->uuid], array_column($listed->json('data'), 'id'));
        $this->as($this->administrator, 'POST', self::STAFF_FOLLOWUPS."/{$second->uuid}/contacts", $this->contact())->assertCreated();
        $this->as($this->administrator, 'POST', self::STAFF_FOLLOWUPS."/{$second->uuid}/close", ['reason' => 'FAMILY_UNREACHABLE'])
            ->assertOk()->assertJsonPath('data.outcome', 'CLOSED_MANUALLY');

        $history = $this->as($this->administrator, 'GET', self::STAFF_FOLLOWUPS."/{$second->uuid}/history")->assertOk();
        $this->assertSame(['FOLLOWUP_OPENED', 'CONTACT_RECORDED', 'FOLLOWUP_CLOSED'], array_column($history->json('data'), 'type'));
        // A manual closure never touches the recipient.
        $this->assertNull(NotificationRecipient::query()->findOrFail($second->recipient_id)->acknowledged_at);

        // Summary: one satisfied and one closed manually in the last 30 days.
        $this->as($this->administrator, 'GET', self::STAFF_FOLLOWUPS.'/summary')->assertOk()
            ->assertJsonPath('data.open', 1) // the unreached family's FIRST stays OPEN
            ->assertJsonPath('data.closed_last_30_days.SATISFIED', 1)
            ->assertJsonPath('data.closed_last_30_days.CLOSED_MANUALLY', 1);
    }

    public function test_normal_announcement_opens_at_72_hours_when_unviewed_and_a_family_http_view_satisfies_it(): void
    {
        $early = $this->activatedHead('123456789');
        $late = $this->activatedHead('223456789');
        $uuid = $this->staffAnnouncement('NORMAL', [$early['family'], $late['family']]);
        $published = now()->toImmutable();

        // One family views before the deadline: nothing ever opens for it.
        $this->travel(10)->hours();
        $this->as($early['user'], 'POST', self::FAMILY_NOTIFICATIONS."/{$uuid}/view")->assertOk();

        $this->travelTo($published->addHours(72)->subSecond());
        $this->assertSame(0, $this->schedulerTick()['opened']);
        $this->travel(1)->seconds();
        $this->assertSame(1, $this->schedulerTick()['opened']);
        $this->assertNull($this->followup($uuid, $early['family'], 'FIRST'));
        $this->assertNull($this->followup($uuid, $late['family'], 'SECOND'), 'NORMAL has no SECOND stage');

        // The late family's own HTTP view closes the OPEN NORMAL follow-up as SATISFIED.
        $this->as($late['user'], 'POST', self::FAMILY_NOTIFICATIONS."/{$uuid}/view")->assertOk();
        $followup = $this->followup($uuid, $late['family'], 'FIRST');
        $this->as($this->administrator, 'GET', self::STAFF_FOLLOWUPS."/{$followup->uuid}")->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')->assertJsonPath('data.outcome', 'SATISFIED');
    }

    // ---------------------------------------------------------- Coordinator

    public function test_coordinator_publishes_to_the_scope_manages_as_primary_and_loses_management_on_revocation(): void
    {
        $clan = $this->clan('E2E_CLAN');
        $branch = $this->branch($clan);
        $first = $this->familyIn($clan, $branch);
        $second = $this->familyIn($clan, $branch);
        $outside = Family::factory()->create();
        $coordinator = $this->coordinator('111111110');
        $assignment = $this->assign($coordinator, $branch);
        $this->grant($coordinator, NotificationActors::COORDINATOR_CREATE, NotificationActors::COORDINATOR_SEND, 'coordinator-announcement.view');

        // Outside the scope is refused at the draft; the scope publishes through the worker.
        $this->as($coordinator, 'POST', self::COORDINATOR_NOTIFICATIONS, [
            'title' => 'إعلان المنسق', 'body' => 'نص.', 'audience' => ['type' => 'COORDINATOR_SELECTION', 'family_codes' => [$outside->family_code]],
        ])->assertStatus(422);
        $uuid = $this->as($coordinator, 'POST', self::COORDINATOR_NOTIFICATIONS, [
            'title' => 'إعلان المنسق', 'body' => 'نص إعلان المنسق.', 'audience' => ['type' => 'COORDINATOR_SCOPE'],
            'expires_at' => now()->addDays(10)->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $this->as($coordinator, 'POST', self::COORDINATOR_NOTIFICATIONS."/{$uuid}/publish")->assertStatus(202);
        $this->runWorker();
        $message = NotificationMessage::query()->where('uuid', $uuid)->sole();
        $this->assertSame(['PUBLISHED', 'COORDINATOR', 2], [$message->status->value, $message->sender_side->value, $message->recipient_count]);

        // +72h: both families' NORMAL follow-ups open; the coordinator is their primary.
        $this->travel(72)->hours();
        $this->assertSame(2, $this->schedulerTick()['opened']);
        $mine = $this->as($coordinator, 'GET', self::COORDINATOR_FOLLOWUPS.'?ownership=mine')->assertOk();
        $this->assertCount(2, $mine->json('data'));
        $this->assertSame([true, true], array_column($mine->json('data'), 'can_manage'));
        $this->as($coordinator, 'GET', self::COORDINATOR_FOLLOWUPS.'/summary')->assertOk()->assertJsonPath('data.mine_open', 2);

        // The primary coordinator records a contact and closes one.
        $managed = $this->followup($uuid, $first, 'FIRST');
        $this->as($coordinator, 'POST', self::COORDINATOR_FOLLOWUPS."/{$managed->uuid}/contacts", $this->contact('VISITED'))->assertCreated();
        $this->as($coordinator, 'POST', self::COORDINATOR_FOLLOWUPS."/{$managed->uuid}/close", ['reason' => 'INFORMED_IN_PERSON'])
            ->assertOk()->assertJsonPath('data.outcome', 'CLOSED_MANUALLY');
        $labels = array_map(fn ($e) => $e['actor']['label'], $this->as($coordinator, 'GET', self::COORDINATOR_FOLLOWUPS."/{$managed->uuid}/history")->json('data'));
        $this->assertSame(['SYSTEM', 'YOU', 'YOU'], $labels);

        // Scope revocation: the coordinator can no longer reach or manage the other one; it becomes CENTRAL for Staff.
        $other = $this->followup($uuid, $second, 'FIRST');
        app(RevokeCoordinatorScopeAction::class)->handle($this->administratorWithScopeManagement(), $assignment, CoordinatorRevokeReason::ADMINISTRATIVE);
        $this->as($coordinator, 'GET', self::COORDINATOR_FOLLOWUPS)->assertForbidden();
        $this->as($coordinator, 'POST', self::COORDINATOR_FOLLOWUPS."/{$other->uuid}/contacts", $this->contact())->assertForbidden();
        $this->assertSame(0, NotificationRecipientEvent::query()->where('followup_id', $other->id)->where('event_type', 'CONTACT_RECORDED')->count());
        $this->as($this->administrator, 'GET', self::STAFF_FOLLOWUPS."/{$other->uuid}")->assertOk()->assertJsonPath('data.ownership.kind', 'CENTRAL');
        $this->as($this->administrator, 'POST', self::STAFF_FOLLOWUPS."/{$other->uuid}/close", ['reason' => 'NOT_APPLICABLE'])->assertOk();
    }

    private function administratorWithScopeManagement(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('SUPER_ADMIN');

        return $admin;
    }

    // ----------------------------------------------------- expiry and gates

    public function test_expiry_archives_the_announcement_and_closes_its_open_follow_ups_visible_through_the_api(): void
    {
        $household = $this->activatedHead('123456789');
        $uuid = $this->staffAnnouncement('IMPORTANT', [$household['family']], '+30 hours');

        $this->travel(24)->hours();
        $this->assertSame(1, $this->schedulerTick()['opened']);
        $followup = $this->followup($uuid, $household['family'], 'FIRST');

        $this->travel(6)->hours();
        $counts = $this->schedulerTick();
        $this->assertSame(1, $counts['expired']);
        $this->assertSame('ARCHIVED', NotificationMessage::query()->where('uuid', $uuid)->sole()->status->value);

        $this->as($this->administrator, 'GET', self::STAFF_FOLLOWUPS."/{$followup->uuid}")->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')->assertJsonPath('data.outcome', 'EXPIRED');
        $this->as($this->administrator, 'POST', self::STAFF_FOLLOWUPS."/{$followup->uuid}/contacts", $this->contact())
            ->assertStatus(409)->assertJsonPath('code', 'FOLLOWUP_NOT_OPEN');
        $this->as($this->administrator, 'GET', self::STAFF_FOLLOWUPS.'?status=CLOSED&outcome=EXPIRED')->assertOk()->assertJsonPath('data.0.id', $followup->uuid);
        // SECOND never opens for an expired message.
        $this->travel(3)->days();
        $this->assertSame(0, $this->schedulerTick()['opened']);
    }

    public function test_off_gates_stop_new_publication_and_new_follow_ups_but_existing_ones_stay_manageable(): void
    {
        $household = $this->activatedHead('123456789');
        $other = Family::factory()->create();
        $opened = $this->staffAnnouncement('NORMAL', [$household['family']]);
        $this->travel(12)->hours();
        $pending = $this->staffAnnouncement('NORMAL', [$other]);
        $this->travel(60)->hours();
        $this->assertSame(1, $this->schedulerTick()['opened']);
        $existing = $this->followup($opened, $household['family'], 'FIRST');

        // Everything OFF.
        config(['notifications.mode' => 'OFF', 'notifications.followups_enabled' => false]);

        // No new publication.
        $draft = $this->as($this->administrator, 'POST', self::STAFF_NOTIFICATIONS, [
            'title' => 'إعلان أثناء الإيقاف', 'body' => 'نص.', 'audience' => ['type' => 'STAFF_FAMILIES', 'family_codes' => [$other->family_code]],
        ])->assertCreated()->json('data.id');
        $this->as($this->administrator, 'POST', self::STAFF_NOTIFICATIONS."/{$draft}/publish")->assertStatus(503);
        $this->assertSame('DRAFT', NotificationMessage::query()->where('uuid', $draft)->sole()->status->value);

        // No new follow-up, although the second message's deadline passes now.
        $this->travel(13)->hours();
        $counts = $this->schedulerTick();
        $this->assertFalse($counts['opening_ready']);
        $this->assertSame(0, $counts['opened']);
        $this->assertNull($this->followup($pending, $other, 'FIRST'));

        // The existing follow-up stays readable and manageable.
        $this->as($this->administrator, 'GET', self::STAFF_FOLLOWUPS)->assertOk()->assertJsonPath('data.0.id', $existing->uuid);
        $this->as($this->administrator, 'POST', self::STAFF_FOLLOWUPS."/{$existing->uuid}/contacts", $this->contact())->assertCreated();
        $this->as($this->administrator, 'POST', self::STAFF_FOLLOWUPS."/{$existing->uuid}/close", ['reason' => 'INFORMED_BY_PHONE'])->assertOk();

        // Back ON: the deferred opening happens on the next tick (publication-based due time, no loss).
        config(['notifications.mode' => 'ON', 'notifications.followups_enabled' => true]);
        $this->assertSame(1, $this->schedulerTick()['opened']);
        $this->assertNotNull($this->followup($pending, $other, 'FIRST'));
    }
}
