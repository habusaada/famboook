<?php

namespace Tests\Feature\Notifications;

use App\Enums\FamilyStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFollowupOutcome;
use App\Enums\NotificationFollowupReason;
use App\Enums\NotificationFollowupStage;
use App\Enums\NotificationFollowupStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationLevel;
use App\Enums\NotificationSenderSide;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Models\FamilyMembership;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2a domain foundation: the four models, their casts,
 * relationships and guards, the notice code, and the unique keys that hold
 * on BOTH drivers (SQLite suite and phpunit.pgsql.xml). The PostgreSQL-only
 * CHECKs and trigger are NotificationSchemaTest. No publication behaviour
 * exists yet: lifecycle columns are set directly. Synthetic data only.
 */
class NotificationModelTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $familyUser;

    private FamilyMembership $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
        $this->familyUser = User::factory()->familySide()->create();
        $this->head = FamilyMembership::factory()->householdHead()->create();
    }

    private function draft(array $o = []): NotificationMessage
    {
        return NotificationMessage::create([
            'kind' => NotificationKind::ANNOUNCEMENT, 'sender_side' => NotificationSenderSide::STAFF,
            'title' => 'إعلان تجريبي', 'body' => 'نص إعلان تجريبي.', 'sender_user_id' => $this->staff->id, ...$o,
        ]);
    }

    private function publishedWithRecipient(): NotificationRecipient
    {
        $message = $this->draft(['level' => NotificationLevel::IMPORTANT]);
        $message->forceFill([
            'status' => NotificationStatus::PUBLISHED, 'queued_at' => now()->subMinute(), 'publishing_started_at' => now(),
            'publishing_token' => (string) Str::uuid(), 'publish_attempts' => 1, 'published_at' => now(), 'recipient_count' => 1,
        ])->save();

        $recipient = new NotificationRecipient;
        $recipient->forceFill([
            'message_id' => $message->id, 'family_id' => $this->head->family_id, 'family_status_at_publication' => FamilyStatus::ACTIVE,
            'head_person_id' => $this->head->person_id, 'had_family_account' => true,
        ])->save();

        return $recipient;
    }

    private function followup(NotificationRecipient $recipient, NotificationFollowupStage $stage = NotificationFollowupStage::FIRST): NotificationFollowup
    {
        $followup = new NotificationFollowup;
        $followup->forceFill([
            'recipient_id' => $recipient->id, 'stage' => $stage, 'trigger_reason' => NotificationFollowupReason::UNACKNOWLEDGED,
            'due_at' => now(), 'status' => NotificationFollowupStatus::OPEN,
        ])->save();

        return $followup;
    }

    public function test_a_draft_gets_a_uuid_a_sequential_notice_code_and_the_defaults(): void
    {
        $first = $this->draft();
        $second = $this->draft();

        $this->assertTrue(Str::isUuid($first->uuid));
        $this->assertMatchesRegularExpression('/^NTF-\d{6}$/', $first->notice_code);
        $this->assertSame((int) substr($first->notice_code, 4) + 1, (int) substr($second->notice_code, 4));

        $fresh = $first->fresh();
        $this->assertSame(NotificationStatus::DRAFT, $fresh->status);
        $this->assertSame(NotificationLevel::NORMAL, $fresh->level);
        $this->assertSame(NotificationKind::ANNOUNCEMENT, $fresh->kind);
        $this->assertSame([], $fresh->audience);
        $this->assertSame(0, $fresh->publish_attempts);
        $this->assertNull($fresh->published_at);
        $this->assertSame('uuid', $fresh->getRouteKeyName());
        $this->assertTrue($fresh->sender->is($this->staff));
    }

    public function test_lifecycle_columns_are_not_mass_assignable(): void
    {
        $message = $this->draft(['status' => 'PUBLISHED', 'published_at' => now(), 'recipient_count' => 9, 'notice_code' => 'NTF-999999']);

        $fresh = $message->fresh();
        $this->assertSame(NotificationStatus::DRAFT, $fresh->status);
        $this->assertNull($fresh->published_at);
        $this->assertNull($fresh->recipient_count);
        $this->assertNotSame('NTF-999999', $fresh->notice_code);
    }

    public function test_the_family_label_is_derived_from_the_sender_side(): void
    {
        $this->assertSame('إدارة Famboook', NotificationSenderSide::SYSTEM->familyLabel());
        $this->assertSame('إدارة Famboook', NotificationSenderSide::STAFF->familyLabel());
        $this->assertSame('منسق العائلة', NotificationSenderSide::COORDINATOR->familyLabel());
    }

    public function test_serialization_hides_internal_columns(): void
    {
        $message = $this->draft([
            'sender_side' => NotificationSenderSide::COORDINATOR, 'sender_user_id' => $this->familyUser->id,
            'sender_scope' => ['assignments' => [['scope_type' => 'CLAN']]],
        ])->fresh();
        $array = $message->toArray();

        foreach (['id', 'sender_user_id', 'sender_scope', 'audience', 'origin_workflow_event_id', 'publishing_token', 'cancelled_by_user_id'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $array);
        }
        $this->assertArrayHasKey('notice_code', $array);
    }

    public function test_relationships_and_casts(): void
    {
        $recipient = $this->publishedWithRecipient();
        $followup = $this->followup($recipient);
        $event = NotificationRecipientEvent::create([
            'message_id' => $recipient->message_id, 'recipient_id' => $recipient->id, 'followup_id' => $followup->id,
            'event_type' => NotificationEventType::FOLLOWUP_OPENED, 'actor_side' => WorkflowActorSide::SYSTEM,
        ]);

        $message = $recipient->message;
        $this->assertSame(NotificationStatus::PUBLISHED, $message->status);
        $this->assertSame(1, $message->recipients()->count());
        $this->assertSame(1, $message->events()->count());
        $this->assertSame(FamilyStatus::ACTIVE, $recipient->fresh()->family_status_at_publication);
        $this->assertTrue($recipient->family->is($this->head->family));
        $this->assertTrue($recipient->headPerson->is($this->head->person));
        $this->assertTrue($recipient->followups->first()->is($followup));
        $this->assertTrue($followup->recipient->is($recipient));
        $this->assertSame(NotificationFollowupStage::FIRST, $followup->fresh()->stage);
        $this->assertSame([], $event->fresh()->metadata);
        $this->assertTrue($event->followup->is($followup));
    }

    public function test_events_are_immutable_and_family_actions_are_the_family_alone(): void
    {
        $recipient = $this->publishedWithRecipient();
        $event = NotificationRecipientEvent::create([
            'message_id' => $recipient->message_id, 'recipient_id' => $recipient->id, 'event_type' => NotificationEventType::VIEWED,
            'actor_side' => WorkflowActorSide::FAMILY, 'actor_user_id' => $this->familyUser->id,
        ]);

        try {
            $event->update(['reason_code' => 'CHANGED']);
            $this->fail('An event was updated.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            $event->delete();
            $this->fail('An event was deleted.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        foreach ([WorkflowActorSide::STAFF, WorkflowActorSide::COORDINATOR] as $side) {
            try {
                NotificationRecipientEvent::create([
                    'message_id' => $recipient->message_id, 'recipient_id' => $recipient->id, 'event_type' => NotificationEventType::ACKNOWLEDGED,
                    'actor_side' => $side, 'actor_user_id' => $this->staff->id,
                ]);
                $this->fail("{$side->value} acknowledged for the family.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, NotificationRecipientEvent::count());
    }

    public function test_closed_followups_never_change_and_nothing_is_deleted(): void
    {
        $recipient = $this->publishedWithRecipient();
        $followup = $this->followup($recipient);
        $followup->forceFill(['status' => NotificationFollowupStatus::CLOSED, 'outcome' => NotificationFollowupOutcome::EXPIRED, 'closed_at' => now()])->save();

        try {
            $followup->forceFill(['status' => NotificationFollowupStatus::OPEN, 'outcome' => null, 'closed_at' => null])->save();
            $this->fail('An expired follow-up was reopened.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(NotificationFollowupStatus::CLOSED, $followup->fresh()->status);

        foreach ([$followup, $recipient, $recipient->message] as $model) {
            try {
                $model->delete();
                $this->fail($model::class.' was deleted.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_json_columns_are_always_stored_as_objects(): void
    {
        // An empty PHP array is an empty OBJECT here, never a JSON list.
        $message = $this->draft(['audience' => []]);
        $this->assertSame('{}', $this->raw('notification_messages', $message->id, 'audience'));
        $this->assertSame([], $message->fresh()->audience);

        $message = $this->draft(['audience' => ['mode' => 'FAMILIES', 'families' => []]]);
        $this->assertJsonStringEqualsJsonString('{"mode":"FAMILIES","families":[]}', $this->raw('notification_messages', $message->id, 'audience'));
        $this->assertSame(['mode' => 'FAMILIES', 'families' => []], $message->fresh()->audience);
        $this->assertNull($message->fresh()->sender_scope);

        $coordinator = $this->draft(['sender_side' => NotificationSenderSide::COORDINATOR, 'sender_user_id' => $this->familyUser->id, 'sender_scope' => []]);
        $this->assertSame('{}', $this->raw('notification_messages', $coordinator->id, 'sender_scope'));

        $recipient = $this->publishedWithRecipient();
        $event = NotificationRecipientEvent::create([
            'message_id' => $recipient->message_id, 'event_type' => NotificationEventType::PUBLISHED,
            'actor_side' => WorkflowActorSide::SYSTEM, 'metadata' => [],
        ]);
        $this->assertSame('{}', $this->raw('notification_recipient_events', $event->id, 'metadata'));

        // A JSON list or a scalar is refused before it reaches the database.
        foreach ([['FAMILIES'], 'ALL', 5] as $invalid) {
            try {
                $this->draft(['audience' => $invalid]);
                $this->fail('A non-object audience was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function raw(string $table, int $id, string $column): ?string
    {
        $value = DB::table($table)->where('id', $id)->value($column);

        return $value === null ? null : json_encode(json_decode($value));
    }

    public function test_unique_keys_hold_on_every_driver(): void
    {
        $recipient = $this->publishedWithRecipient();
        $this->followup($recipient);

        $duplicateRecipient = (new NotificationRecipient)->forceFill([
            'message_id' => $recipient->message_id, 'family_id' => $recipient->family_id,
            'family_status_at_publication' => FamilyStatus::ACTIVE, 'had_family_account' => false,
        ]);
        $this->assertRefused(fn () => $duplicateRecipient->save());
        $this->assertRefused(fn () => $this->followup($recipient));
        // D12: FIRST and SECOND coexist.
        $this->assertSame(NotificationFollowupStage::SECOND, $this->followup($recipient, NotificationFollowupStage::SECOND)->stage);

        $reference = (string) Str::uuid();
        $this->draft(['client_reference' => $reference]);
        $this->assertRefused(fn () => $this->draft(['client_reference' => $reference]));
        $this->draft();
        $this->draft();
    }

    private function assertRefused(callable $write): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The database accepted a duplicate.');
    }
}
