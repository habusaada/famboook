<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationFailureCode;
use App\Enums\NotificationStatus;
use App\Models\Branch;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.6c: the Coordinator announcement API
 * (/api/v1/family/coordinator/notifications) behind coordinator.space and
 * coordinator-announcement.* — own announcements, the CURRENT scope for
 * drafts, previews, publication and statistics; withdrawal still possible
 * after a revocation (the one route outside coordinator.space). The
 * permissions are PROPOSED and not seeded: created and granted here, in the
 * test database only. Synthetic data only.
 */
class CoordinatorAnnouncementApiTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/coordinator/notifications';

    private Clan $clan;

    private Branch $first;

    private Branch $second;

    private Family $inFirst;

    private Family $inSecond;

    private Family $outside;

    private User $coordinator;

    private CoordinatorScopeAssignment $secondAssignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $this->clan = $this->clan('NTF_CLAN');
        [$this->first, $this->second, $third] = [$this->branch($this->clan), $this->branch($this->clan), $this->branch($this->clan)];
        $this->inFirst = $this->familyIn($this->clan, $this->first);
        $this->inSecond = $this->familyIn($this->clan, $this->second);
        $this->outside = $this->familyIn($this->clan, $third);
        $this->coordinator = $this->coordinatorSender($this->first, '111111110',
            NotificationActors::COORDINATOR_CREATE, NotificationActors::COORDINATOR_SEND, 'coordinator-announcement.view');
        $this->secondAssignment = $this->assign($this->coordinator, $this->second);
    }

    private function as(User $user, string $method, string $uri = '', array $body = []): TestResponse
    {
        return $this->actingAs($user->fresh())->json($method, self::URI.$uri, $body);
    }

    /** @return array<string, mixed> */
    private function draftBody(array $audience = ['type' => 'COORDINATOR_SCOPE'], array $o = []): array
    {
        return ['title' => 'إعلان المنسق', 'body' => 'نص إعلان المنسق.', 'audience' => $audience, ...$o];
    }

    private function draftVia(User $user, array $audience = ['type' => 'COORDINATOR_SCOPE'], array $o = []): string
    {
        return $this->as($user, 'POST', '', $this->draftBody($audience, $o))->assertCreated()->json('data.id');
    }

    private function message(string $uuid): NotificationMessage
    {
        return NotificationMessage::query()->where('uuid', $uuid)->sole();
    }

    private function revoke(CoordinatorScopeAssignment ...$assignments): void
    {
        foreach ($assignments as $assignment) {
            $assignment->forceFill(['revoked_at' => now(), 'revoked_by' => $this->staffSender()->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        }
    }

    // ------------------------------------------------------------- boundary

    public function test_only_a_coordinator_with_coordinator_announcement_permissions_reaches_the_api(): void
    {
        // Coordinators WITHOUT the permissions (the seeded role holds them; the
        // seeded baseline itself is NotificationPermissionsTest's).
        $this->withoutCoordinatorRoleAnnouncementPermissions();
        $this->getJson(self::URI)->assertUnauthorized();
        $this->as($this->staffSender(), 'GET')->assertForbidden();
        // A household head who is no coordinator: no permission to read the history,
        // no coordinator space for any operation.
        $head = $this->activatedHead('222222220')['user'];
        $this->as($head, 'GET')->assertForbidden();
        $this->as($head, 'POST', '', $this->draftBody())->assertForbidden()->assertJson(['code' => 'COORDINATOR_SPACE_UNAVAILABLE']);

        // Nothing seeded: a coordinator without the permissions fails closed;
        // Staff announcement.* never authorizes a coordinator.
        $bare = $this->coordinator('333333330');
        $this->assign($bare, $this->first);
        $withStaffPermissions = $this->grant($this->coordinator('444444440'), 'announcement.view', NotificationActors::STAFF_CREATE, NotificationActors::STAFF_SEND);
        $this->assign($withStaffPermissions, $this->first);
        $uuid = $this->draftVia($this->coordinator);
        foreach ([$bare, $withStaffPermissions] as $user) {
            foreach ([['GET', ''], ['POST', '/audience-preview'], ['POST', ''], ['GET', "/{$uuid}"], ['POST', "/{$uuid}/publish"], ['POST', "/{$uuid}/cancel"], ['POST', "/{$uuid}/retry"]] as [$method, $uri]) {
                $this->as($user, $method, $uri, $this->draftBody())->assertForbidden();
            }
        }
    }

    // --------------------------------------------------------------- scope

    public function test_drafts_and_previews_stay_within_the_current_scope(): void
    {
        $this->as($this->coordinator, 'POST', '/audience-preview', ['audience' => ['type' => 'COORDINATOR_SCOPE']])->assertOk()
            ->assertExactJson(['data' => ['recipient_count' => 2]]);
        $this->as($this->coordinator, 'POST', '/audience-preview', ['audience' => ['type' => 'COORDINATOR_SELECTION', 'branch_ids' => [$this->first->id]]])
            ->assertOk()->assertExactJson(['data' => ['recipient_count' => 1]]);

        // Outside the scope: refused as a whole, never narrowed or widened.
        $this->as($this->coordinator, 'POST', '/audience-preview', ['audience' => ['type' => 'COORDINATOR_SELECTION', 'clan_ids' => [$this->clan->id]]])
            ->assertForbidden()->assertJson(['code' => 'NOTIFICATION_AUDIENCE_NOT_AUTHORIZED']);
        $this->as($this->coordinator, 'POST', '', $this->draftBody(['type' => 'COORDINATOR_SELECTION', 'family_ids' => [$this->outside->id]]))
            ->assertUnprocessable()->assertJson(['code' => 'NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE']);
        $this->as($this->coordinator, 'POST', '', $this->draftBody(['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->inFirst->id]]))
            ->assertUnprocessable()->assertJson(['code' => 'NOTIFICATION_AUDIENCE_INVALID']);
        $this->as($this->coordinator, 'POST', '', $this->draftBody(['type' => 'STAFF_ALL_ACTIVE']))->assertForbidden();

        $created = $this->as($this->coordinator, 'POST', '', $this->draftBody())->assertCreated();
        $this->assertSame('COORDINATOR', $this->message($created->json('data.id'))->sender_side->value);
        $this->assertArrayNotHasKey('sender_scope', $created->json('data'));
    }

    public function test_statistics_count_only_the_current_scope_even_for_history(): void
    {
        $uuid = $this->draftVia($this->coordinator, ['type' => 'COORDINATOR_SCOPE'], ['level' => 'IMPORTANT']);
        $this->as($this->coordinator, 'POST', "/{$uuid}/publish")->assertStatus(202);
        app(NotificationPublisher::class)->publish($this->message($uuid)->id);
        // That family has no account to view through the API: the view is set
        // row by row, with its viewing user as chk_nr_view_actor requires.
        DB::table('notification_recipients')->where('family_id', $this->inSecond->id)
            ->update(['viewed_at' => now(), 'viewed_by_user_id' => User::factory()->familySide()->create()->id]);

        $before = $this->as($this->coordinator, 'GET', "/{$uuid}")->assertOk();
        $this->assertSame([2, 'CURRENT_COORDINATOR_SCOPE', 2, 1], [$before->json('data.recipient_count_at_publication'), $before->json('data.statistics_basis'),
            $before->json('data.statistics.recipients'), $before->json('data.statistics.viewed')]);

        // The second Branch is revoked: the history stays, its family no longer counts.
        $this->revoke($this->secondAssignment);
        $after = $this->as($this->coordinator, 'GET', "/{$uuid}")->assertOk();
        $this->assertSame(2, $after->json('data.recipient_count_at_publication'), 'the publication-time fact stays');
        $this->assertSame('CURRENT_COORDINATOR_SCOPE', $after->json('data.statistics_basis'));
        $this->assertSame(['recipients' => 1, 'with_account' => 0, 'without_account' => 1, 'viewed' => 0,
            'not_viewed' => 1, 'acknowledged' => 0, 'acknowledged_late' => 0, 'pending_acknowledgement' => 1], $after->json('data.statistics'));
        $this->assertSame([$uuid], array_column($this->as($this->coordinator, 'GET')->json('data'), 'id'));
    }

    // ------------------------------------------------- ownership and reads

    public function test_a_coordinator_reads_only_their_own_announcements(): void
    {
        $mine = $this->draftVia($this->coordinator);
        $peer = $this->grant($this->coordinator('555555550'), NotificationActors::COORDINATOR_CREATE, 'coordinator-announcement.view');
        $this->assign($peer, $this->first);
        $theirs = $this->draftVia($peer);
        $staff = $this->staffSender(NotificationActors::STAFF_CREATE, 'announcement.view');
        $staffMessage = $this->actingAs($staff)->postJson('/api/v1/notifications', [
            'title' => 'إعلان الإدارة', 'body' => 'نص.', 'audience' => ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->inFirst->id]],
        ])->assertCreated()->json('data.id');

        $this->assertSame([$mine], array_column($this->as($this->coordinator, 'GET')->assertHeader('Cache-Control', 'no-store, private')->json('data'), 'id'));
        foreach ([$theirs, $staffMessage, (string) Str::uuid()] as $uuid) {
            $this->as($this->coordinator, 'GET', "/{$uuid}")->assertNotFound()->assertJson(['code' => 'NOTIFICATION_NOT_FOUND']);
            $this->as($this->coordinator, 'POST', "/{$uuid}/cancel")->assertNotFound();
        }
        // And the Staff API never shows a coordinator's message (not owned on that side).
        $this->actingAs($staff->fresh())->getJson("/api/v1/notifications/{$mine}")->assertNotFound();
    }

    // --------------------------------------------------------- revocation

    public function test_after_revocation_only_the_own_history_and_withdrawal_remain(): void
    {
        $published = $this->draftVia($this->coordinator);
        $this->as($this->coordinator, 'POST', "/{$published}/publish")->assertStatus(202);
        app(NotificationPublisher::class)->publish($this->message($published)->id);
        $draft = $this->draftVia($this->coordinator);
        $queued = $this->draftVia($this->coordinator);
        $this->as($this->coordinator, 'POST', "/{$queued}/publish")->assertStatus(202);
        $failed = $this->draftVia($this->coordinator);
        $this->as($this->coordinator, 'POST', "/{$failed}/publish")->assertStatus(202);
        $this->failPublication($this->message($failed), NotificationFailureCode::PUBLICATION_ERROR, 3);
        $peer = $this->grant($this->coordinator('555555550'), NotificationActors::COORDINATOR_CREATE, NotificationActors::COORDINATOR_SEND, 'coordinator-announcement.view');
        $this->assign($peer, $this->first);

        $this->revoke(...CoordinatorScopeAssignment::query()->where('user_id', $this->coordinator->id)->get()->all());

        // No drafting, preview, publishing or retrying any more.
        foreach ([['POST', '/audience-preview'], ['POST', ''], ['POST', "/{$draft}/publish"], ['POST', "/{$failed}/retry"]] as [$method, $uri]) {
            $this->as($this->coordinator, $method, $uri, $this->draftBody())->assertForbidden()->assertJson(['code' => 'COORDINATOR_SPACE_UNAVAILABLE']);
        }

        // The own history stays readable — with no statistics at all.
        $list = $this->as($this->coordinator, 'GET')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertEqualsCanonicalizing([$published, $draft, $queued, $failed], array_column($list->json('data'), 'id'));
        $detail = $this->as($this->coordinator, 'GET', "/{$published}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['PUBLISHED', 2, 'NO_CURRENT_SCOPE', null],
            [$detail->json('data.status'), $detail->json('data.recipient_count_at_publication'), $detail->json('data.statistics_basis'), $detail->json('data.statistics')]);
        $raw = $detail->getContent();
        foreach ([(string) $this->inFirst->family_code, (string) $this->inSecond->family_code, '"family_id"', 'head_person', 'sender_scope'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, $secret);
        }

        // Another coordinator never reads or withdraws it.
        $this->as($peer, 'GET', "/{$published}")->assertNotFound();
        $this->assertSame([], array_column($this->as($peer, 'GET')->json('data'), 'id'));
        $this->as($peer, 'POST', "/{$draft}/cancel")->assertNotFound();

        // The original sender still withdraws each of their own messages.
        foreach ([$draft, $queued, $failed] as $uuid) {
            $this->as($this->coordinator, 'POST', "/{$uuid}/cancel", ['reason_code' => 'SCOPE_REVOKED'])->assertOk()
                ->assertJson(['data' => ['status' => 'CANCELLED', 'statistics' => null]])->assertHeader('Cache-Control', 'no-store, private');
            $this->assertSame(NotificationStatus::CANCELLED, $this->message($uuid)->status);
        }
    }

    public function test_a_reassigned_coordinator_sees_statistics_only_for_the_new_scope(): void
    {
        $uuid = $this->draftVia($this->coordinator);
        $this->as($this->coordinator, 'POST', "/{$uuid}/publish")->assertStatus(202);
        app(NotificationPublisher::class)->publish($this->message($uuid)->id);

        // Every assignment revoked, then a new one on a Branch the message never reached.
        $this->revoke(...CoordinatorScopeAssignment::query()->where('user_id', $this->coordinator->id)->get()->all());
        $this->assign($this->coordinator, $this->outside->branch);
        $detail = $this->as($this->coordinator, 'GET', "/{$uuid}")->assertOk();
        $this->assertSame([2, 'CURRENT_COORDINATOR_SCOPE', 0, 0],
            [$detail->json('data.recipient_count_at_publication'), $detail->json('data.statistics_basis'), $detail->json('data.statistics.recipients'), $detail->json('data.statistics.viewed')]);

        // Back on the first Branch: only its family counts.
        $this->assign($this->coordinator, $this->first);
        $this->assertSame(1, $this->as($this->coordinator, 'GET', "/{$uuid}")->json('data.statistics.recipients'));
    }

    // ----------------------------------------------------------- lifecycle

    public function test_publication_retry_and_readiness(): void
    {
        $uuid = $this->draftVia($this->coordinator);
        config(['notifications.mode' => 'OFF']);
        $this->as($this->coordinator, 'POST', "/{$uuid}/publish")->assertStatus(503)->assertJson(['code' => 'NOTIFICATION_PUBLICATION_UNAVAILABLE']);
        config(['notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => '999999']);
        $this->as($this->coordinator, 'POST', "/{$uuid}/publish")->assertStatus(503);
        config(['notifications.pilot_sender_user_ids' => (string) $this->coordinator->id]);
        $this->as($this->coordinator, 'POST', "/{$uuid}/publish")->assertStatus(202)->assertJson(['data' => ['status' => 'QUEUED']]);

        $this->failPublication($this->message($uuid), NotificationFailureCode::AUDIENCE_EMPTY, 1);
        $this->as($this->coordinator, 'POST', "/{$uuid}/retry")->assertStatus(202)->assertJson(['meta' => ['replayed' => false]]);
        $this->failPublication($this->message($uuid), NotificationFailureCode::AUDIENCE_EMPTY, 2);
        $this->as($this->coordinator, 'POST', "/{$uuid}/retry")->assertStatus(409)->assertJson(['code' => 'NOTIFICATION_RETRY_LIMIT_REACHED']);
    }

    // ---------------------------------------------------------- rate limits

    public function test_coordinator_writes_are_rate_limited_per_user(): void
    {
        config(['notifications.coordinator_limits.write_user_minute' => 2]);
        $this->draftVia($this->coordinator);
        $this->draftVia($this->coordinator);

        $this->as($this->coordinator, 'POST', '', $this->draftBody())->assertStatus(429)->assertJson(['code' => 'TOO_MANY_REQUESTS']);
    }
}
