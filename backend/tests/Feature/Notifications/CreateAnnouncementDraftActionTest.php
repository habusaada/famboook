<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\CreateAnnouncementDraftAction;
use App\Enums\NotificationKind;
use App\Enums\NotificationLevel;
use App\Enums\NotificationSenderSide;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationAudienceException;
use App\Exceptions\NotificationPublicationException;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.4: CreateAnnouncementDraftAction — WHO (side from the
 * account, the create permission, a coordinator's CURRENT context), input
 * validation, server-set kind / side / sender / scope snapshot, and
 * client_reference idempotency. A draft writes no event, no recipient and
 * dispatches nothing. Synthetic data only.
 */
class CreateAnnouncementDraftActionTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->family = Family::factory()->create();
    }

    private function create(User $user, array $input): NotificationMessage
    {
        return app(CreateAnnouncementDraftAction::class)->handle($user, $input)->message;
    }

    /** @return array<string, mixed> */
    private function input(array $o = []): array
    {
        return ['title' => 'إعلان', 'body' => 'نص الإعلان.', 'audience' => ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]], ...$o];
    }

    private function assertActorRefused(User $user, array $input): void
    {
        try {
            $this->create($user, $input);
            $this->fail('Expected NOTIFICATION_ACTOR_NOT_ALLOWED.');
        } catch (NotificationPublicationException $e) {
            $this->assertSame(NotificationPublicationException::ACTOR_NOT_ALLOWED, $e->reason);
        }
    }

    public function test_staff_creates_a_draft_with_server_set_kind_side_and_sender(): void
    {
        $staff = $this->staffSender(NotificationActors::STAFF_CREATE);
        $message = $this->create($staff, $this->input([
            // Client attempts to choose what the server decides: all ignored.
            'kind' => 'SYSTEM', 'sender_side' => 'COORDINATOR', 'status' => 'QUEUED', 'sender_user_id' => 1, 'sender_scope' => ['x' => 1],
        ]));

        $this->assertSame(NotificationKind::ANNOUNCEMENT, $message->kind);
        $this->assertSame(NotificationSenderSide::STAFF, $message->sender_side);
        $this->assertSame(NotificationStatus::DRAFT, $message->fresh()->status);
        $this->assertSame(NotificationLevel::NORMAL, $message->fresh()->level);
        $this->assertSame((int) $staff->id, (int) $message->sender_user_id);
        $this->assertNull($message->sender_scope);
        $this->assertMatchesRegularExpression('/^NTF-\d{6,}$/', $message->notice_code);
        $this->assertSame(['type' => 'STAFF_FAMILIES', 'family_ids' => [(int) $this->family->id]], $message->fresh()->audience);
        $this->assertSame(0, NotificationRecipientEvent::query()->count());
        $this->assertSame(0, DB::table('notification_recipients')->count());
        $this->assertSame([], $this->dispatcher->dispatched);
    }

    public function test_a_coordinator_draft_snapshots_the_current_scope(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $branch = $this->branch($clan);
        $this->familyIn($clan, $branch);
        $coordinator = $this->coordinatorSender($branch, '111111110', NotificationActors::COORDINATOR_CREATE);

        $message = $this->create($coordinator, $this->input(['audience' => ['type' => 'COORDINATOR_SCOPE']]));

        $this->assertSame(NotificationSenderSide::COORDINATOR, $message->sender_side);
        // jsonb stores object keys in its own order: compare each assignment's keys sorted.
        $stored = $message->fresh()->sender_scope;
        $this->assertSame(['assignments'], array_keys($stored));
        $assignment = $stored['assignments'][0];
        ksort($assignment);
        $this->assertCount(1, $stored['assignments']);
        $this->assertSame(['branch_group_id' => null, 'branch_id' => (int) $branch->id, 'clan_id' => (int) $clan->id, 'type' => 'BRANCH'], $assignment);
    }

    public function test_only_an_authorized_sender_of_the_accounts_own_side_creates(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $inactive = $this->staffSender(NotificationActors::STAFF_CREATE);
        $inactive->forceFill(['is_active' => false])->save();
        $noScope = $this->grant($this->coordinator('222222220'), NotificationActors::COORDINATOR_CREATE);
        $revoked = $this->grant($this->coordinator('333333330'), NotificationActors::COORDINATOR_CREATE);
        $this->assign($revoked, $clan, revoked: true);
        $staffWithCoordinatorPermission = $this->staffSender(NotificationActors::COORDINATOR_CREATE);
        $headWithStaffPermission = $this->grant($this->activatedHead('444444440')['user'], NotificationActors::STAFF_CREATE);

        foreach ([
            $this->staffSender(NotificationActors::STAFF_SEND), // send without create
            $inactive,
            $staffWithCoordinatorPermission,
            $headWithStaffPermission,
            $this->activatedHead('555555550')['user'],
            $noScope,
            $revoked,
        ] as $user) {
            $this->assertActorRefused($user, $this->input());
        }
        $this->assertSame(0, NotificationMessage::query()->count());
    }

    public function test_the_audience_must_be_of_the_senders_side_and_authorized(): void
    {
        $staff = $this->staffSender();
        foreach ([['type' => 'COORDINATOR_SCOPE'], ['type' => 'SYSTEM_ORIGIN']] as $audience) {
            try {
                $this->create($staff, $this->input(['audience' => $audience]));
                $this->fail('Expected an invalid audience.');
            } catch (NotificationAudienceException $e) {
                $this->assertSame(NotificationAudienceException::INVALID, $e->reason);
            }
        }
        try {
            $this->create($staff, $this->input(['audience' => ['type' => 'STAFF_ALL_ACTIVE']]));
            $this->fail('Expected send-all to be required.');
        } catch (NotificationAudienceException $e) {
            $this->assertSame(NotificationAudienceException::NOT_AUTHORIZED, $e->reason);
        }
    }

    public function test_title_body_level_expiry_and_reference_are_validated(): void
    {
        $staff = $this->staffSender();
        $invalid = [
            'blank title' => ['title' => '   '],
            'long title' => ['title' => str_repeat('ع', 151)],
            'blank body' => ['body' => "\n "],
            'long body' => ['body' => str_repeat('ن', 2001)],
            'level' => ['level' => 'URGENT'],
            'past expiry' => ['expires_at' => now()->subMinute()->toIso8601String()],
            'expiry text' => ['expires_at' => 'tomorrow-ish'],
            'reference' => ['client_reference' => 'not-a-uuid'],
            'audience' => ['audience' => 'STAFF_ALL_ACTIVE'],
        ];
        foreach ($invalid as $why => $override) {
            try {
                $this->create($staff, $this->input($override));
                $this->fail("Expected a validation error: {$why}.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $message = $this->create($staff, $this->input([
            'title' => '  '.str_repeat('ع', 150).'  ', 'body' => str_repeat('ن', 2000), 'level' => 'IMPORTANT',
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ]));
        $this->assertSame(150, mb_strlen($message->title));
        $this->assertSame(NotificationLevel::IMPORTANT, $message->fresh()->level);
    }

    public function test_a_duplicate_client_reference_replays_or_conflicts(): void
    {
        $staff = $this->staffSender();
        $reference = (string) Str::uuid();
        $first = app(CreateAnnouncementDraftAction::class)->handle($staff, $this->input(['client_reference' => $reference]));
        $again = app(CreateAnnouncementDraftAction::class)->handle($staff, $this->input(['client_reference' => $reference]));

        $this->assertFalse($first->replayed);
        $this->assertTrue($again->replayed);
        $this->assertSame($first->message->id, $again->message->id);
        $this->assertSame(1, NotificationMessage::query()->count());

        try {
            $this->create($staff, $this->input(['client_reference' => $reference, 'body' => 'نص مختلف.']));
            $this->fail('Expected an idempotency conflict.');
        } catch (NotificationPublicationException $e) {
            $this->assertSame(NotificationPublicationException::IDEMPOTENCY_CONFLICT, $e->reason);
        }

        // The key is per sender: another sender's same key is another draft.
        $this->create($this->staffSender(), $this->input(['client_reference' => $reference]));
        $this->assertSame(2, NotificationMessage::query()->count());
    }
}
