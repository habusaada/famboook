<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\AcknowledgeNotificationAction;
use App\Actions\Notifications\CancelPublicationAction;
use App\Actions\Notifications\RecordNotificationViewAction;
use App\Actions\Notifications\RequestPublicationAction;
use App\Enums\NotificationPublicationResult;
use App\Exceptions\NotificationInboxException;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\Notifications\NotificationExpirySweeper;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.6b: the family notifications API — inbox, archive,
 * details, explicit view and IMPORTANT acknowledgement — behind auth:sanctum,
 * family.side, can:family-portal.access and family.context. Everything goes
 * through the context Family's own recipient rows; another family's
 * notification is 404; reading never records a view. Messages are published
 * through the real pipeline (draft, request, worker). Driver-agnostic (also
 * run on PostgreSQL). Synthetic data only.
 */
class FamilyNotificationApiTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/notifications';

    private User $staff;

    /** @var array{user: User, family: Family} */
    private array $head;

    /** @var array{user: User, family: Family} */
    private array $otherHead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $this->staff = $this->staffSender();
        $this->head = $this->activatedHead('123456789');
        $this->otherHead = $this->activatedHead('222222220');
    }

    /** A message PUBLISHED to $family through the real pipeline. */
    private function publishedTo(Family $family, string $level = 'NORMAL', ?int $expiresInMinutes = null, string $title = 'إعلان تجريبي'): NotificationMessage
    {
        $draft = $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$family->id]], array_filter([
            'level' => $level, 'title' => $title,
            'expires_at' => $expiresInMinutes === null ? null : now()->addMinutes($expiresInMinutes)->toIso8601String(),
        ]));
        app(RequestPublicationAction::class)->handle($this->staff, $draft);
        $this->assertSame(NotificationPublicationResult::PUBLISHED, app(NotificationPublisher::class)->publish($draft->id));

        return $draft->fresh();
    }

    private function as(User $user, string $method, string $uri = ''): TestResponse
    {
        return $this->actingAs($user->fresh())->json($method, self::URI.$uri);
    }

    private function recipientOf(NotificationMessage $message, Family $family): NotificationRecipient
    {
        return NotificationRecipient::query()->where('message_id', $message->id)->where('family_id', $family->id)->sole();
    }

    /** @return list<string> */
    private function familyEvents(NotificationMessage $message): array
    {
        return NotificationRecipientEvent::query()->where('message_id', $message->id)->whereNotNull('recipient_id')
            ->orderBy('id')->pluck('event_type')->map(fn ($t) => $t->value)->all();
    }

    // ------------------------------------------------------------- boundary

    public function test_only_a_family_side_head_in_family_context_reaches_the_inbox(): void
    {
        $this->getJson(self::URI)->assertUnauthorized();
        $this->as($this->staff, 'GET')->assertForbidden();
        $this->as($this->familyUser(), 'GET')->assertForbidden()->assertJson(['code' => 'FAMILY_CONTEXT_UNAVAILABLE']);
        $this->as($this->head['user'], 'GET')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    // ------------------------------------------------------- inbox contract

    public function test_the_inbox_lists_this_familys_published_notifications_newest_first_with_safe_fields(): void
    {
        $older = $this->publishedTo($this->head['family'], title: 'الأقدم');
        $this->travel(1)->minutes();
        $newer = $this->publishedTo($this->head['family'], 'IMPORTANT', title: 'الأحدث');
        $this->publishedTo($this->otherHead['family'], title: 'لأسرة أخرى');
        // Not published: never visible.
        $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->head['family']->id]], ['title' => 'مسودة']);
        $cancelled = $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->head['family']->id]], ['title' => 'ملغى']);
        app(CancelPublicationAction::class)->handle($this->staff, $cancelled);

        $response = $this->as($this->head['user'], 'GET')->assertOk();

        $this->assertSame([$newer->uuid, $older->uuid], array_column($response->json('data'), 'id'));
        $this->assertSame(2, $response->json('meta.unread_count'));
        $this->assertSame(2, $response->json('meta.total'));
        $item = $response->json('data.0');
        $this->assertSame([
            'id', 'notice_code', 'kind', 'source', 'level', 'title', 'action_path', 'published_at', 'expires_at', 'expired',
            'viewed_at', 'requires_acknowledgement', 'acknowledged_at', 'acknowledged_late', 'available_actions',
        ], array_keys($item));
        $this->assertSame(['ANNOUNCEMENT', 'إدارة Famboook', 'IMPORTANT', 'الأحدث', ['VIEW', 'ACKNOWLEDGE']],
            [$item['kind'], $item['source'], $item['level'], $item['title'], $item['available_actions']]);
        // Nothing about the sender, the audience, other recipients or this family's internals.
        $raw = $response->getContent();
        foreach ([$this->staff->name, (string) $this->staff->email, 'sender', 'audience', 'family_id', 'head_person', 'recipient_count', 'لأسرة أخرى', 'مسودة', 'ملغى'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, $secret);
        }
    }

    public function test_the_inbox_is_paginated_up_to_50(): void
    {
        foreach (range(1, 3) as $i) {
            $this->publishedTo($this->head['family']);
        }

        $page = $this->as($this->head['user'], 'GET', '?per_page=2&page=2')->assertOk();
        $this->assertCount(1, $page->json('data'));
        $this->assertSame([3, 2, 2], [$page->json('meta.total'), $page->json('meta.per_page'), $page->json('meta.current_page')]);
        $this->assertSame(3, $page->json('meta.unread_count'));
        $this->as($this->head['user'], 'GET', '?per_page=51')->assertUnprocessable();
        $this->as($this->head['user'], 'GET', '/archive?per_page=0')->assertUnprocessable();
    }

    public function test_another_familys_notification_is_not_found_and_details_record_no_view(): void
    {
        $mine = $this->publishedTo($this->head['family']);
        $theirs = $this->publishedTo($this->otherHead['family']);

        $detail = $this->as($this->head['user'], 'GET', "/{$mine->uuid}")->assertOk();
        $this->assertSame('نص إعلان تجريبي.', $detail->json('data.body'));
        $this->assertNull($this->recipientOf($mine, $this->head['family'])->viewed_at, 'reading must not record a view');

        foreach (["/{$theirs->uuid}", '/'.Str::uuid(), '/not-a-uuid'] as $uri) {
            $this->as($this->head['user'], 'GET', $uri)->assertNotFound()->assertJson(['code' => 'NOTIFICATION_NOT_FOUND']);
        }
        foreach (['view', 'acknowledge'] as $action) {
            $this->as($this->head['user'], 'POST', "/{$theirs->uuid}/{$action}")->assertNotFound();
        }
        $this->assertNull($this->recipientOf($theirs, $this->otherHead['family'])->viewed_at);
    }

    // ------------------------------------------------- expiry and archive

    public function test_expiry_moves_a_notification_from_the_inbox_to_the_archive_at_expires_at(): void
    {
        $expiring = $this->publishedTo($this->head['family'], expiresInMinutes: 120);
        $this->publishedTo($this->head['family']);

        $this->travel(120 * 60 - 1)->seconds();
        $this->assertCount(2, $this->as($this->head['user'], 'GET')->json('data'));
        $this->assertCount(0, $this->as($this->head['user'], 'GET', '/archive')->json('data'));

        $this->travel(1)->seconds();
        $inbox = $this->as($this->head['user'], 'GET')->assertOk();
        $this->assertCount(1, $inbox->json('data'));
        $this->assertSame(1, $inbox->json('meta.unread_count'), 'an expired notification never counts as unread');
        $archive = $this->as($this->head['user'], 'GET', '/archive')->assertOk();
        $this->assertSame([$expiring->uuid], array_column($archive->json('data'), 'id'));
        $this->assertTrue($archive->json('data.0.expired'));
        $this->assertArrayNotHasKey('unread_count', $archive->json('meta'));

        // Once the sweep archives it, it stays in the archive and readable.
        app(NotificationExpirySweeper::class)->run();
        $this->assertSame([$expiring->uuid], array_column($this->as($this->head['user'], 'GET', '/archive')->json('data'), 'id'));
        $this->as($this->head['user'], 'GET', "/{$expiring->uuid}")->assertOk()->assertJson(['data' => ['expired' => true]]);
    }

    // ------------------------------------------------------------------ view

    public function test_view_is_explicit_recorded_once_and_lowers_the_unread_count(): void
    {
        $message = $this->publishedTo($this->head['family']);

        $first = $this->as($this->head['user'], 'POST', "/{$message->uuid}/view")->assertOk()
            ->assertJson(['meta' => ['replayed' => false], 'data' => ['available_actions' => []]]);
        $viewedAt = $first->json('data.viewed_at');
        $this->travel(5)->minutes();
        $this->as($this->head['user'], 'POST', "/{$message->uuid}/view")->assertOk()
            ->assertJson(['meta' => ['replayed' => true], 'data' => ['viewed_at' => $viewedAt]]);

        $recipient = $this->recipientOf($message, $this->head['family']);
        $this->assertSame((int) $this->head['user']->id, (int) $recipient->viewed_by_user_id);
        $this->assertSame(['VIEWED'], $this->familyEvents($message));
        $event = NotificationRecipientEvent::query()->where('message_id', $message->id)->where('event_type', 'VIEWED')->sole();
        $this->assertSame(['FAMILY', (int) $this->head['user']->id], [$event->actor_side->value, (int) $event->actor_user_id]);
        $this->assertSame(0, $this->as($this->head['user'], 'GET')->json('meta.unread_count'));
    }

    // ---------------------------------------------------------- acknowledge

    public function test_an_important_notification_is_acknowledged_once_and_acknowledging_includes_viewing(): void
    {
        $message = $this->publishedTo($this->head['family'], 'IMPORTANT');

        $first = $this->as($this->head['user'], 'POST', "/{$message->uuid}/acknowledge")->assertOk()
            ->assertJson(['meta' => ['replayed' => false], 'data' => ['acknowledged_late' => false, 'available_actions' => []]]);
        $this->assertSame($first->json('data.viewed_at'), $first->json('data.acknowledged_at'));
        $this->assertSame(['VIEWED', 'ACKNOWLEDGED'], $this->familyEvents($message));

        $this->travel(1)->minutes();
        $this->as($this->head['user'], 'POST', "/{$message->uuid}/acknowledge")->assertOk()
            ->assertJson(['meta' => ['replayed' => true], 'data' => ['acknowledged_at' => $first->json('data.acknowledged_at')]]);
        $this->as($this->head['user'], 'POST', "/{$message->uuid}/view")->assertOk()->assertJson(['meta' => ['replayed' => true]]);
        $this->assertSame(['VIEWED', 'ACKNOWLEDGED'], $this->familyEvents($message));
    }

    public function test_a_viewed_notification_acknowledged_later_records_only_the_acknowledgement(): void
    {
        $message = $this->publishedTo($this->head['family'], 'IMPORTANT');
        $this->as($this->head['user'], 'POST', "/{$message->uuid}/view")->assertOk();
        $this->travel(3)->minutes();
        $this->as($this->head['user'], 'POST', "/{$message->uuid}/acknowledge")->assertOk();

        $recipient = $this->recipientOf($message, $this->head['family']);
        $this->assertTrue($recipient->acknowledged_at->greaterThan($recipient->viewed_at));
        $this->assertSame(['VIEWED', 'ACKNOWLEDGED'], $this->familyEvents($message));
    }

    public function test_a_normal_notification_is_never_acknowledged(): void
    {
        $message = $this->publishedTo($this->head['family']);

        $this->as($this->head['user'], 'POST', "/{$message->uuid}/acknowledge")->assertStatus(409)
            ->assertJson(['code' => 'NOTIFICATION_NOT_ACKNOWLEDGEABLE']);
        $recipient = $this->recipientOf($message, $this->head['family']);
        $this->assertNull($recipient->acknowledged_at);
        $this->assertNull($recipient->viewed_at, 'a refused acknowledgement records no view either');
        $this->assertSame([], $this->familyEvents($message));
    }

    public function test_a_late_acknowledgement_after_expiry_and_archiving(): void
    {
        $expired = $this->publishedTo($this->head['family'], 'IMPORTANT', expiresInMinutes: 90);
        $archived = $this->publishedTo($this->head['family'], 'IMPORTANT', expiresInMinutes: 90);
        $this->travel(90)->minutes();
        app(NotificationExpirySweeper::class)->run(limit: 1);

        foreach ([$expired, $archived] as $message) {
            $this->as($this->head['user'], 'POST', "/{$message->uuid}/acknowledge")->assertOk()
                ->assertJson(['data' => ['acknowledged_late' => true, 'expired' => true]]);
            $this->assertSame(['VIEWED', 'ACKNOWLEDGED_LATE'], $this->familyEvents($message));
        }
        $this->assertSame(1, NotificationMessage::query()->where('status', 'ARCHIVED')->count());
    }

    // ------------------------------------------------------ impersonation

    public function test_every_notification_response_is_no_store_private(): void
    {
        $important = $this->publishedTo($this->head['family'], 'IMPORTANT');
        $normal = $this->publishedTo($this->head['family']);
        $theirs = $this->publishedTo($this->otherHead['family']);

        $responses = [
            'inbox' => $this->as($this->head['user'], 'GET')->assertOk(),
            'archive' => $this->as($this->head['user'], 'GET', '/archive')->assertOk(),
            'details' => $this->as($this->head['user'], 'GET', "/{$important->uuid}")->assertOk(),
            'view' => $this->as($this->head['user'], 'POST', "/{$important->uuid}/view")->assertOk(),
            'acknowledge' => $this->as($this->head['user'], 'POST', "/{$important->uuid}/acknowledge")->assertOk(),
            'replay' => $this->as($this->head['user'], 'POST', "/{$important->uuid}/acknowledge")->assertOk(),
            'not found' => $this->as($this->head['user'], 'GET', "/{$theirs->uuid}")->assertNotFound(),
            'not acknowledgeable' => $this->as($this->head['user'], 'POST', "/{$normal->uuid}/acknowledge")->assertStatus(409),
        ];
        foreach ($responses as $what => $response) {
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'), $what);
        }
    }

    public function test_nobody_but_the_recipient_familys_head_records_a_view_or_acknowledgement(): void
    {
        $message = $this->publishedTo($this->head['family'], 'IMPORTANT');
        // A coordinator heading ANOTHER household (in scope or not) is that household's head only.
        $coordinator = $this->activatedHead('333333330', ['FAMILY_USER', 'COORDINATOR'])['user'];

        foreach ([$this->otherHead['user'], $coordinator] as $user) {
            foreach (['view', 'acknowledge'] as $action) {
                $this->as($user, 'POST', "/{$message->uuid}/{$action}")->assertNotFound();
            }
        }
        $this->as($this->staff, 'POST', "/{$message->uuid}/view")->assertForbidden();

        // The actions themselves refuse another family's context (defence in depth).
        $otherContext = app(FamilyAccessResolver::class)->familyContext($this->otherHead['user']);
        foreach ([RecordNotificationViewAction::class, AcknowledgeNotificationAction::class] as $action) {
            try {
                app($action)->handle($otherContext, $message->uuid);
                $this->fail("{$action} accepted another family.");
            } catch (NotificationInboxException $e) {
                $this->assertSame(NotificationInboxException::NOT_FOUND, $e->reason);
            }
        }
        $this->assertSame([], $this->familyEvents($message));
        $this->assertSame(0, DB::table('notification_recipients')->whereNotNull('viewed_at')->count());
    }
}
