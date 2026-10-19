<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RequestPublicationAction;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationStatus;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationAudienceResolver;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.6c: the Staff announcement API (/api/v1/notifications)
 * behind staff.side and announcement.* — the sender's OWN announcements
 * through the lifecycle Domain Actions. The announcement permissions are
 * PROPOSED and not seeded: they are created and granted here, in the test
 * database only. Publication is never run by these routes (the dispatcher
 * records). Synthetic data only.
 */
class StaffAnnouncementApiTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private const URI = '/api/v1/notifications';

    private User $staff;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $this->staff = $this->staffSender(NotificationActors::STAFF_CREATE, NotificationActors::STAFF_SEND, 'announcement.view');
        $this->family = Family::factory()->create();
    }

    private function as(User $user, string $method, string $uri = '', array $body = []): TestResponse
    {
        return $this->actingAs($user->fresh())->json($method, self::URI.$uri, $body);
    }

    /** @return array<string, mixed> */
    private function draftBody(array $o = []): array
    {
        return ['title' => 'إعلان الإدارة', 'body' => 'نص إعلان الإدارة.', 'audience' => ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]], ...$o];
    }

    private function draftVia(User $user, array $o = []): string
    {
        return $this->as($user, 'POST', '', $this->draftBody($o))->assertCreated()->json('data.id');
    }

    private function message(string $uuid): NotificationMessage
    {
        return NotificationMessage::query()->where('uuid', $uuid)->sole();
    }

    // ------------------------------------------------------------- boundary

    public function test_only_staff_with_the_announcement_permissions_reach_the_api(): void
    {
        $this->getJson(self::URI)->assertUnauthorized();
        $this->as($this->activatedHead('123456789')['user'], 'GET')->assertForbidden();

        // No announcement permission (the release state: nothing is seeded) fails closed everywhere.
        $bare = $this->staffSender('reference-data.view');
        $uuid = $this->draftVia($this->staff);
        foreach ([['GET', ''], ['POST', '/audience-preview'], ['POST', ''], ['GET', "/{$uuid}"], ['POST', "/{$uuid}/publish"], ['POST', "/{$uuid}/cancel"], ['POST', "/{$uuid}/retry"]] as [$method, $uri]) {
            $this->as($bare, $method, $uri, $this->draftBody())->assertForbidden();
        }

        // View alone reads, never writes; send without create never drafts.
        $viewer = $this->staffSender('announcement.view');
        $this->as($viewer, 'GET')->assertOk();
        $this->as($viewer, 'POST', '', $this->draftBody())->assertForbidden();
        $this->as($this->staffSender(NotificationActors::STAFF_SEND), 'POST', '', $this->draftBody())->assertForbidden();
    }

    public function test_a_missing_send_permission_is_refused_before_readiness_is_revealed(): void
    {
        $uuid = $this->draftVia($this->staff);
        $creator = $this->staffSender(NotificationActors::STAFF_CREATE, 'announcement.view');
        config(['notifications.mode' => 'OFF']);

        $this->as($creator, 'POST', "/{$uuid}/publish")->assertForbidden();
        $this->as($this->staff, 'POST', "/{$uuid}/publish")->assertStatus(503)->assertJson(['code' => 'NOTIFICATION_PUBLICATION_UNAVAILABLE']);
    }

    // -------------------------------------------------------------- drafts

    public function test_a_draft_is_created_201_then_replayed_200_and_a_changed_replay_conflicts(): void
    {
        $reference = (string) Str::uuid();
        $created = $this->as($this->staff, 'POST', '', $this->draftBody(['client_reference' => $reference, 'level' => 'IMPORTANT']))->assertCreated();
        $created->assertJson(['meta' => ['replayed' => false], 'data' => ['status' => 'DRAFT', 'level' => 'IMPORTANT', 'audience_type' => 'STAFF_FAMILIES',
            'audience_selection' => ['family_ids' => 1], 'statistics_basis' => null, 'statistics' => null, 'available_actions' => ['PUBLISH', 'CANCEL']]]);
        $this->assertMatchesRegularExpression('/^NTF-\d{6,}$/', $created->json('data.notice_code'));

        $this->as($this->staff, 'POST', '', $this->draftBody(['client_reference' => $reference, 'level' => 'IMPORTANT']))->assertOk()
            ->assertJson(['meta' => ['replayed' => true], 'data' => ['id' => $created->json('data.id')]]);
        $this->as($this->staff, 'POST', '', $this->draftBody(['client_reference' => $reference, 'body' => 'نص آخر.']))->assertStatus(409)
            ->assertJson(['code' => 'NOTIFICATION_IDEMPOTENCY_CONFLICT']);
        $this->assertSame(1, NotificationMessage::query()->count());
    }

    public function test_draft_input_is_validated(): void
    {
        foreach ([
            ['title' => ''], ['body' => ''], ['level' => 'URGENT'], ['audience' => 'all'], ['audience' => ['family_ids' => [1]]],
            ['expires_at' => 'soon'], ['client_reference' => 'x'], ['title' => str_repeat('ع', 151)],
        ] as $override) {
            $this->as($this->staff, 'POST', '', $this->draftBody($override))->assertUnprocessable();
        }
        $this->as($this->staff, 'POST', '', $this->draftBody(['audience' => ['type' => 'COORDINATOR_SCOPE']]))->assertUnprocessable()
            ->assertJson(['code' => 'NOTIFICATION_AUDIENCE_INVALID']);
        $inactive = Family::factory()->create(['status' => 'INACTIVE']);
        $this->as($this->staff, 'POST', '', $this->draftBody(['audience' => ['type' => 'STAFF_FAMILIES', 'family_ids' => [$inactive->id]]]))
            ->assertUnprocessable()->assertJson(['code' => 'NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE']);
        $this->assertSame(0, NotificationMessage::query()->count());
    }

    public function test_every_active_family_needs_send_all_at_the_http_layer_and_in_the_domain(): void
    {
        $global = ['audience' => ['type' => 'STAFF_ALL_ACTIVE']];
        $this->as($this->staff, 'POST', '', $this->draftBody($global))->assertForbidden();
        $this->as($this->staff, 'POST', '/audience-preview', $global)->assertForbidden();

        $this->staff->givePermissionTo(Permission::findOrCreate(NotificationAudienceResolver::SEND_ALL_PERMISSION, 'web'));
        $uuid = $this->as($this->staff, 'POST', '', $this->draftBody($global))->assertCreated()->json('data.id');
        $this->assertSame(Family::query()->where('status', 'ACTIVE')->count(), $this->as($this->staff, 'POST', '/audience-preview', $global)->json('data.recipient_count'));

        // Revoked afterwards: the domain refuses the publication request.
        $this->staff->revokePermissionTo(NotificationAudienceResolver::SEND_ALL_PERMISSION);
        $this->as($this->staff, 'POST', "/{$uuid}/publish")->assertForbidden()->assertJson(['code' => 'NOTIFICATION_AUDIENCE_NOT_AUTHORIZED']);
        $this->assertSame(NotificationStatus::DRAFT, $this->message($uuid)->status);
    }

    // ------------------------------------------------------------- preview

    public function test_the_audience_preview_counts_without_writing(): void
    {
        $other = Family::factory()->create();
        $this->as($this->staff, 'POST', '/audience-preview', ['audience' => ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id, $other->id]]])
            ->assertOk()->assertExactJson(['data' => ['recipient_count' => 2]])->assertHeader('Cache-Control', 'no-store, private');
        $this->as($this->staff, 'POST', '/audience-preview', ['audience' => ['type' => 'STAFF_FAMILIES', 'family_ids' => [999999]]])
            ->assertUnprocessable()->assertJson(['code' => 'NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE']);
        $this->as($this->staff, 'POST', '/audience-preview', ['audience' => ['type' => 'COORDINATOR_SCOPE']])->assertUnprocessable();
        $this->assertSame(0, NotificationMessage::query()->count());
    }

    // ------------------------------------------------- ownership and reads

    public function test_a_sender_lists_and_reads_only_their_own_announcements(): void
    {
        $mine = [$this->draftVia($this->staff), $this->draftVia($this->staff)];
        $theirs = $this->draftVia($this->staffSender(NotificationActors::STAFF_CREATE));
        $this->as($this->staff, 'POST', "/{$mine[0]}/cancel")->assertOk();

        $list = $this->as($this->staff, 'GET')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([$mine[1], $mine[0]], array_column($list->json('data'), 'id'));
        $this->assertSame([$mine[0]], array_column($this->as($this->staff, 'GET', '?status=CANCELLED')->json('data'), 'id'));
        $this->assertCount(1, $this->as($this->staff, 'GET', '?per_page=1')->json('data'));
        $this->as($this->staff, 'GET', '?per_page=51')->assertUnprocessable();
        $this->as($this->staff, 'GET', '?status=SENT')->assertUnprocessable();

        foreach (["/{$theirs}", '/'.Str::uuid(), '/not-a-uuid'] as $uri) {
            $this->as($this->staff, 'GET', $uri)->assertNotFound()->assertJson(['code' => 'NOTIFICATION_NOT_FOUND']);
        }
        foreach (['publish', 'cancel', 'retry'] as $action) {
            $this->as($this->staff, 'POST', "/{$theirs}/{$action}")->assertNotFound();
        }
        $this->assertSame(NotificationStatus::DRAFT, $this->message($theirs)->status);
    }

    // ----------------------------------------------------------- lifecycle

    public function test_publication_is_queued_202_and_a_repeat_is_200(): void
    {
        $uuid = $this->draftVia($this->staff);

        $this->as($this->staff, 'POST', "/{$uuid}/publish")->assertStatus(202)
            ->assertJson(['meta' => ['replayed' => false], 'data' => ['status' => 'QUEUED', 'available_actions' => ['CANCEL']]]);
        $this->as($this->staff, 'POST', "/{$uuid}/publish")->assertOk()->assertJson(['meta' => ['replayed' => true]]);
        $this->assertSame([(int) $this->message($uuid)->id], $this->dispatcher->ids());
        $this->assertNull($this->message($uuid)->published_at, 'the route never publishes');
    }

    public function test_publication_and_retry_fail_closed_when_not_ready(): void
    {
        $uuid = $this->draftVia($this->staff);
        config(['queue.default' => 'sync']);
        $this->as($this->staff, 'POST', "/{$uuid}/publish")->assertStatus(503)->assertJson(['code' => 'NOTIFICATION_PUBLICATION_UNAVAILABLE']);
        config(['queue.default' => 'database']);
        $this->travel(301)->seconds();
        $this->as($this->staff, 'POST', "/{$uuid}/publish")->assertStatus(503);
        $this->freshHeartbeat();

        $this->as($this->staff, 'POST', "/{$uuid}/publish")->assertStatus(202);
        $failed = $this->failPublication($this->message($uuid), NotificationFailureCode::PUBLICATION_ERROR, 3);
        config(['notifications.mode' => 'OFF']);
        $this->as($this->staff, 'POST', "/{$failed->uuid}/retry")->assertStatus(503);
        $this->assertSame(NotificationStatus::PUBLISH_FAILED, $failed->fresh()->status);
    }

    public function test_cancel_and_retry_follow_the_domain_rules(): void
    {
        // Cancel: .create on the route; .send once queued (the action).
        $creator = $this->staffSender(NotificationActors::STAFF_CREATE, 'announcement.view');
        $draft = $this->draftVia($creator);
        $this->as($creator, 'POST', "/{$draft}/cancel", ['reason_code' => 'WRONG_AUDIENCE'])->assertOk()
            ->assertJson(['data' => ['status' => 'CANCELLED', 'cancellation_reason_code' => 'WRONG_AUDIENCE']]);

        $queued = $this->draftVia($this->staff);
        $this->as($this->staff, 'POST', "/{$queued}/publish")->assertStatus(202);
        $this->staff->revokePermissionTo(NotificationActors::STAFF_SEND);
        $this->as($this->staff, 'POST', "/{$queued}/cancel")->assertForbidden();
        $this->staff->givePermissionTo(NotificationActors::STAFF_SEND);
        $this->as($this->staff, 'POST', "/{$queued}/cancel", ['reason_code' => 'not a code'])->assertUnprocessable();

        // Retry: transient after the automatic attempts → 202; permanent → 409.
        $transient = $this->failPublication($this->message($queued), NotificationFailureCode::STALE_PUBLISHING, 3);
        $this->as($this->staff, 'GET', "/{$transient->uuid}")->assertJson(['data' => ['available_actions' => ['CANCEL', 'RETRY'], 'publish_failure_code' => 'STALE_PUBLISHING']]);
        $this->as($this->staff, 'POST', "/{$transient->uuid}/retry")->assertStatus(202)->assertJson(['data' => ['status' => 'QUEUED']]);

        $other = $this->draftVia($this->staff);
        $this->as($this->staff, 'POST', "/{$other}/publish")->assertStatus(202);
        $permanent = $this->failPublication($this->message($other), NotificationFailureCode::SENDER_NOT_AUTHORIZED, 1);
        $this->as($this->staff, 'POST', "/{$permanent->uuid}/retry")->assertStatus(409)->assertJson(['code' => 'NOTIFICATION_RETRY_NOT_ALLOWED']);
        // A cancelled message of one's own is a conflict; another sender's is not found.
        $ownCancelled = $this->draftVia($this->staff);
        $this->as($this->staff, 'POST', "/{$ownCancelled}/cancel")->assertOk();
        $this->as($this->staff, 'POST', "/{$ownCancelled}/publish")->assertStatus(409)->assertJson(['code' => 'NOTIFICATION_INVALID_TRANSITION']);
        $this->as($this->staff, 'POST', "/{$draft}/publish")->assertNotFound();
    }

    // ---------------------------------------------------------- statistics

    public function test_statistics_are_sql_aggregates_without_any_personal_data(): void
    {
        $withAccount = $this->activatedHead('123456789');
        $other = $this->activatedHead('222222220');
        $draft = $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$withAccount['family']->id, $other['family']->id, $this->family->id]], ['level' => 'IMPORTANT']);
        app(RequestPublicationAction::class)->handle($this->staff, $draft);
        app(NotificationPublisher::class)->publish($draft->id);
        $this->actingAs($withAccount['user']->fresh())->postJson("/api/v1/family/notifications/{$draft->uuid}/acknowledge")->assertOk();
        $this->actingAs($other['user']->fresh())->postJson("/api/v1/family/notifications/{$draft->uuid}/view")->assertOk();

        $detail = $this->as($this->staff, 'GET', "/{$draft->uuid}")->assertOk();

        $this->assertSame([
            'recipients' => 3, 'with_account' => 2, 'without_account' => 1, 'viewed' => 2, 'not_viewed' => 1,
            'acknowledged' => 1, 'acknowledged_late' => 0, 'pending_acknowledgement' => 2,
        ], $detail->json('data.statistics'));
        $this->assertSame([3, 'ALL_RECIPIENTS'], [$detail->json('data.recipient_count_at_publication'), $detail->json('data.statistics_basis')]);
        $raw = $detail->getContent();
        foreach (['123456789', '222222220', $withAccount['person']->full_name, $other['person']->full_name, 'publishing_token', 'sender_scope',
            'client_reference', 'sender_user_id', '"family_id"', 'head_person', (string) $withAccount['family']->family_code] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $raw, $secret);
        }
    }

    // ---------------------------------------------------------- rate limits

    public function test_writes_and_reads_are_rate_limited_per_user(): void
    {
        config(['notifications.staff_limits.write_user_minute' => 2, 'notifications.staff_limits.read_user_minute' => 2]);
        $this->draftVia($this->staff);
        $this->draftVia($this->staff);
        $this->as($this->staff, 'POST', '', $this->draftBody())->assertStatus(429);

        $this->as($this->staff, 'GET')->assertOk();
        $this->as($this->staff, 'GET')->assertOk();
        $this->as($this->staff, 'GET')->assertStatus(429);
        // Another sender has their own budget.
        $this->as($this->staffSender(NotificationActors::STAFF_CREATE, 'announcement.view'), 'GET')->assertOk();
    }
}
