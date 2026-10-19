<?php

namespace Tests\Feature\Notifications;

use App\Enums\FamilyStatus;
use App\Enums\NotificationEventType as Type;
use App\Enums\NotificationFollowupReason;
use App\Enums\NotificationFollowupStage;
use App\Enums\NotificationFollowupStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationSenderSide;
use App\Enums\WorkflowActorSide as Side;
use App\Models\FamilyMembership;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationEventRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;
use Throwable;

/**
 * FB-NOTIFY-01B.2b.3: NotificationEventRecorder appends events only inside the
 * caller's transaction and refuses what the PostgreSQL CHECKs refuse — on
 * every driver — plus the application rules: actor sides, reason codes,
 * internal notes, note corrections and codes-and-counts metadata. Events
 * are never updated or deleted. RefreshDatabase already holds a transaction
 * open, which stands in for the Domain Action's. Synthetic data only.
 */
class NotificationEventRecorderTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private NotificationEventRecorder $recorder;

    private User $staff;

    private User $coordinator;

    private User $familyUser;

    private NotificationMessage $message;

    private NotificationRecipient $recipient;

    private NotificationFollowup $followup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $this->recorder = app(NotificationEventRecorder::class);
        $this->staff = $this->user(['ADMINISTRATOR']);
        // The recipient family's own activated household head; a coordinator heading another family.
        $head = $this->activatedHead('123456789');
        $this->familyUser = $head['user'];
        $this->coordinator = $this->activatedHead('111111110', ['FAMILY_USER', 'COORDINATOR'])['user'];
        $this->message = $this->publishedMessage();
        $this->recipient = $this->recipientOf($this->message, $head['membership']);
        $this->followup = $this->followupOf($this->recipient);
    }

    private function user(array $roles, bool $familySide = false): User
    {
        $user = ($familySide ? User::factory()->familySide() : User::factory())->create();
        foreach ($roles as $role) {
            $user->assignRole(Role::findByName($role, 'web'));
        }

        return $user;
    }

    private function publishedMessage(): NotificationMessage
    {
        $message = NotificationMessage::create([
            'kind' => NotificationKind::ANNOUNCEMENT, 'sender_side' => NotificationSenderSide::STAFF,
            'title' => 'إعلان تجريبي', 'body' => 'نص تجريبي.', 'sender_user_id' => $this->staff->id,
        ]);
        $message->forceFill([
            'status' => 'PUBLISHED', 'queued_at' => now()->subMinute(), 'publishing_started_at' => now(),
            'publishing_token' => (string) Str::uuid(), 'publish_attempts' => 1, 'published_at' => now(), 'recipient_count' => 1,
        ])->save();

        return $message;
    }

    private function recipientOf(NotificationMessage $message, ?FamilyMembership $head = null): NotificationRecipient
    {
        $head ??= FamilyMembership::factory()->householdHead()->create();
        $recipient = new NotificationRecipient;
        $recipient->forceFill([
            'message_id' => $message->id, 'family_id' => $head->family_id, 'family_status_at_publication' => FamilyStatus::ACTIVE,
            'head_person_id' => $head->person_id, 'had_family_account' => true,
        ])->save();

        return $recipient;
    }

    private function followupOf(NotificationRecipient $recipient): NotificationFollowup
    {
        $followup = new NotificationFollowup;
        $followup->forceFill([
            'recipient_id' => $recipient->id, 'stage' => NotificationFollowupStage::FIRST,
            'trigger_reason' => NotificationFollowupReason::UNVIEWED, 'due_at' => now(), 'status' => NotificationFollowupStatus::OPEN,
        ])->save();

        return $followup;
    }

    private function contact(?string $note = 'تم الاتصال وترك رسالة.', ?User $actor = null, Side $side = Side::STAFF): NotificationRecipientEvent
    {
        return $this->recorder->followup($this->followup, Type::CONTACT_RECORDED, $side, $actor ?? $this->staff, 'NO_ANSWER', $note);
    }

    private function assertInvalid(callable $call, string $why): void
    {
        try {
            $call();
            $this->fail("Expected a refusal: {$why}.");
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------------ creation

    public function test_message_recipient_and_followup_events_are_recorded_with_their_references(): void
    {
        $queued = $this->recorder->message($this->message, Type::QUEUED, Side::STAFF, $this->staff);
        $published = $this->recorder->message($this->message, Type::PUBLISHED, Side::SYSTEM, metadata: ['recipient_count' => 1]);
        $viewed = $this->recorder->recipient($this->recipient, Type::VIEWED, $this->familyUser);
        $opened = $this->recorder->followup($this->followup, Type::FOLLOWUP_OPENED, Side::SYSTEM);
        $contact = $this->contact();

        $this->assertSame([$this->message->id, null, null], [$queued->message_id, $queued->recipient_id, $queued->followup_id]);
        $this->assertSame(['recipient_count' => 1], $published->fresh()->metadata);
        $this->assertNull($published->actor_user_id);
        $this->assertSame([$this->message->id, $this->recipient->id, null], [$viewed->message_id, $viewed->recipient_id, $viewed->followup_id]);
        $this->assertSame(Side::FAMILY, $viewed->actor_side);
        $this->assertSame([$this->message->id, $this->recipient->id, $this->followup->id], [$opened->message_id, $opened->recipient_id, $opened->followup_id]);
        $this->assertSame(['NO_ANSWER', 'تم الاتصال وترك رسالة.'], [$contact->reason_code, $contact->internal_note]);
        $this->assertSame('{}', json_encode(json_decode(DB::table('notification_recipient_events')->where('id', $queued->id)->value('metadata'))));
        $this->assertSame(5, NotificationRecipientEvent::query()->count());
    }

    public function test_a_coordinator_records_a_contact_and_closes_manually_with_a_reason(): void
    {
        $this->contact('اتصال من المنسق.', $this->coordinator, Side::COORDINATOR);
        $closed = $this->recorder->followup($this->followup, Type::FOLLOWUP_CLOSED, Side::COORDINATOR, $this->coordinator, 'FAMILY_INFORMED');
        $auto = $this->recorder->followup($this->followup, Type::FOLLOWUP_CLOSED, Side::SYSTEM);

        $this->assertSame('FAMILY_INFORMED', $closed->reason_code);
        $this->assertNull($auto->reason_code);
    }

    public function test_each_type_is_recorded_only_in_its_scope(): void
    {
        $this->assertInvalid(fn () => $this->recorder->message($this->message, Type::VIEWED, Side::FAMILY, $this->familyUser), 'recipient event as message');
        $this->assertInvalid(fn () => $this->recorder->recipient($this->recipient, Type::PUBLISHED, $this->familyUser), 'message event as recipient');
        $this->assertInvalid(fn () => $this->recorder->followup($this->followup, Type::QUEUED, Side::SYSTEM), 'message event as follow-up');
        $this->assertInvalid(fn () => $this->recorder->followup($this->followup, Type::NOTE_CORRECTION, Side::STAFF, $this->staff, 'WRONG_NOTE'), 'correction via followup()');
    }

    // --------------------------------------------------------------------- actor

    public function test_actor_sides_follow_the_type_and_the_account_side(): void
    {
        $refused = [
            'staff viewing for the family' => fn () => $this->recorder->recipient($this->recipient, Type::VIEWED, $this->staff),
            'coordinator acknowledging for the family' => fn () => $this->recorder->recipient($this->recipient, Type::ACKNOWLEDGED, $this->coordinator),
            'a person publishing' => fn () => $this->recorder->message($this->message, Type::PUBLISHED, Side::STAFF, $this->staff),
            'the system cancelling' => fn () => $this->recorder->message($this->message, Type::CANCELLED, Side::SYSTEM),
            'a family contact' => fn () => $this->contact(actor: $this->familyUser, side: Side::FAMILY),
            'a SYSTEM event with a user' => fn () => $this->recorder->message($this->message, Type::PUBLISHED, Side::SYSTEM, $this->staff),
            'a STAFF event without a user' => fn () => $this->recorder->message($this->message, Type::QUEUED, Side::STAFF),
            'a STAFF event by a family account' => fn () => $this->recorder->message($this->message, Type::QUEUED, Side::STAFF, $this->familyUser),
            'a COORDINATOR event by Staff' => fn () => $this->recorder->message($this->message, Type::QUEUED, Side::COORDINATOR, $this->staff),
            'an unsaved actor' => fn () => $this->recorder->message($this->message, Type::QUEUED, Side::STAFF, User::factory()->make()),
        ];
        foreach ($refused as $why => $call) {
            $this->assertInvalid($call, $why);
        }
        $this->assertSame(0, NotificationRecipientEvent::query()->count());
    }

    public function test_only_the_recipient_familys_current_head_views_or_acknowledges(): void
    {
        $otherHead = $this->activatedHead('222222220')['user'];
        $this->assertInvalid(fn () => $this->recorder->recipient($this->recipient, Type::VIEWED, $otherHead), 'another family\'s head');

        // A head who lost the household (e.g. succession) no longer acts for it.
        $formerHead = $this->activatedHead('333333330');
        $theirRecipient = $this->recipientOf($this->message, $formerHead['membership']);
        $formerHead['membership']->forceFill(['is_active' => false, 'ended_at' => now()->toDateString(), 'end_reason' => 'OTHER'])->save();
        $this->assertInvalid(fn () => $this->recorder->recipient($theirRecipient, Type::VIEWED, $formerHead['user']), 'a former head');

        // A coordinator is still the head of their OWN household.
        $coordinatorHead = $this->activatedHead('444444440', ['FAMILY_USER', 'COORDINATOR']);
        $own = $this->recipientOf($this->message, $coordinatorHead['membership']);
        $this->assertSame(Side::FAMILY, $this->recorder->recipient($own, Type::VIEWED, $coordinatorHead['user'])->actor_side);
    }

    // ------------------------------------------------------- reason codes, notes

    public function test_reason_codes_are_codes_and_required_where_a_person_acts(): void
    {
        $refused = [
            'free text' => fn () => $this->recorder->message($this->message, Type::CANCELLED, Side::STAFF, $this->staff, 'cancelled by me'),
            'lowercase' => fn () => $this->recorder->message($this->message, Type::CANCELLED, Side::STAFF, $this->staff, 'wrong_audience'),
            'a failure without a code' => fn () => $this->recorder->message($this->message, Type::PUBLISH_FAILED, Side::SYSTEM),
            'a contact without a code' => fn () => $this->recorder->followup($this->followup, Type::CONTACT_RECORDED, Side::STAFF, $this->staff),
            'a manual closure without a code' => fn () => $this->recorder->followup($this->followup, Type::FOLLOWUP_CLOSED, Side::STAFF, $this->staff),
        ];
        foreach ($refused as $why => $call) {
            $this->assertInvalid($call, $why);
        }

        $failed = $this->recorder->message($this->message, Type::PUBLISH_FAILED, Side::SYSTEM, reasonCode: 'SENDER_NOT_AUTHORIZED');
        $this->assertSame('SENDER_NOT_AUTHORIZED', $failed->reason_code);
    }

    public function test_internal_notes_belong_to_staff_or_coordinator_contacts_and_closures(): void
    {
        $refused = [
            'on an automatic closure' => fn () => $this->recorder->followup($this->followup, Type::FOLLOWUP_CLOSED, Side::SYSTEM, internalNote: 'ملاحظة'),
            'on the opening' => fn () => $this->recorder->followup($this->followup, Type::FOLLOWUP_OPENED, Side::SYSTEM, internalNote: 'ملاحظة'),
            'blank' => fn () => $this->contact('   '),
            'too long' => fn () => $this->contact(str_repeat('ن', NotificationEventRecorder::MAX_NOTE + 1)),
        ];
        foreach ($refused as $why => $call) {
            $this->assertInvalid($call, $why);
        }

        $this->assertSame(NotificationEventRecorder::MAX_NOTE, mb_strlen($this->contact(str_repeat('ن', NotificationEventRecorder::MAX_NOTE))->internal_note));
    }

    // --------------------------------------------------------------- correction

    public function test_a_note_correction_references_an_earlier_noted_event_of_the_same_followup(): void
    {
        $contact = $this->contact('ملاحظة غير صحيحة.');
        $correction = $this->recorder->correction($contact, $this->staff, 'INCORRECT_NOTE');

        $this->assertSame(Type::NOTE_CORRECTION, $correction->event_type);
        $this->assertSame($contact->id, $correction->corrects_event_id);
        $this->assertSame([$contact->message_id, $contact->recipient_id, $contact->followup_id],
            [$correction->message_id, $correction->recipient_id, $correction->followup_id]);
        $this->assertGreaterThan($contact->id, $correction->id);
        // The original is never changed: suppression is the views' job.
        $this->assertSame('ملاحظة غير صحيحة.', $contact->fresh()->internal_note);
    }

    public function test_a_correction_is_refused_without_a_note_or_by_anyone_but_staff(): void
    {
        $noted = $this->contact();
        $refused = [
            'no note' => fn () => $this->recorder->correction($this->contact(null), $this->staff, 'INCORRECT_NOTE'),
            'a message event' => fn () => $this->recorder->correction($this->recorder->message($this->message, Type::QUEUED, Side::STAFF, $this->staff), $this->staff, 'INCORRECT_NOTE'),
            'a correction' => fn () => $this->recorder->correction($this->recorder->correction($noted, $this->staff, 'INCORRECT_NOTE'), $this->staff, 'INCORRECT_NOTE'),
            'a coordinator' => fn () => $this->recorder->correction($noted, $this->coordinator, 'INCORRECT_NOTE'),
            'free-text reason' => fn () => $this->recorder->correction($noted, $this->staff, 'wrong'),
            'an unsaved event' => fn () => $this->recorder->correction(new NotificationRecipientEvent, $this->staff, 'INCORRECT_NOTE'),
        ];
        foreach ($refused as $why => $call) {
            $this->assertInvalid($call, $why);
        }
    }

    // ------------------------------------------------------------------ metadata

    public function test_metadata_is_a_json_object_of_codes_and_counts(): void
    {
        $refused = [
            'a list' => ['NO_ANSWER'],
            'nested' => ['outcome' => ['code' => 'X']],
            'free text' => ['note' => 'محمد اتصل'],
            'lowercase text' => ['outcome' => 'no answer'],
            'a national-id-sized number' => ['count' => 123456789],
            'a mobile-sized number' => ['count' => 591234567],
            'negative' => ['count' => -1],
            'a float' => ['ratio' => 0.5],
            'a camelCase key' => ['recipientCount' => 1],
            'too many keys' => array_combine(array_map(fn ($i) => "k{$i}", range(1, 21)), array_fill(0, 21, 1)),
        ];
        foreach ($refused as $why => $metadata) {
            $this->assertInvalid(fn () => $this->recorder->message($this->message, Type::QUEUED, Side::SYSTEM, metadata: $metadata), $why);
        }

        $event = $this->recorder->message($this->message, Type::PUBLISHED, Side::SYSTEM,
            metadata: ['recipient_count' => 9_999_999, 'outcome' => 'PUBLISHED_OK', 'retried' => false]);
        // jsonb stores keys in its own order: compare them sorted.
        $stored = $event->fresh()->metadata;
        ksort($stored);
        $this->assertSame(['outcome' => 'PUBLISHED_OK', 'recipient_count' => 9_999_999, 'retried' => false], $stored);
    }

    // --------------------------------------------------------------- transactions

    public function test_it_runs_only_inside_the_callers_transaction_and_never_opens_one(): void
    {
        $began = 0;
        Event::listen(TransactionBeginning::class, function () use (&$began) {
            $began++;
        });
        $this->recorder->message($this->message, Type::QUEUED, Side::STAFF, $this->staff);
        $this->assertSame(0, $began);

        // The caller's rollback takes the event with it.
        try {
            DB::transaction(function () {
                $this->recorder->message($this->message, Type::REQUEUED, Side::STAFF, $this->staff);
                throw new RuntimeException('the Domain Action failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, NotificationRecipientEvent::query()->where('event_type', Type::REQUEUED->value)->count());

        // With no transaction at all it refuses (RefreshDatabase's is lifted briefly).
        $level = DB::transactionLevel();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        try {
            $this->recorder->message(new NotificationMessage, Type::QUEUED, Side::SYSTEM);
            $this->fail('Expected a refusal outside a transaction.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('inside the calling Domain Action transaction', $e->getMessage());
        } finally {
            while (DB::transactionLevel() < $level) {
                DB::beginTransaction();
            }
        }
    }

    // ------------------------------------------------- integrity and append-only

    public function test_references_are_derived_so_they_cannot_disagree_and_must_exist(): void
    {
        $other = $this->recipientOf($this->publishedMessage());
        $otherFollowup = $this->followupOf($other);

        $event = $this->recorder->followup($otherFollowup, Type::FOLLOWUP_OPENED, Side::SYSTEM);
        $this->assertSame([$other->message_id, $other->id], [$event->message_id, $event->recipient_id]);

        $this->assertInvalid(fn () => $this->recorder->message(new NotificationMessage, Type::QUEUED, Side::SYSTEM), 'unsaved message');
        $this->assertInvalid(fn () => $this->recorder->recipient(new NotificationRecipient, Type::VIEWED, $this->familyUser), 'unsaved recipient');
        $this->assertInvalid(fn () => $this->recorder->followup(new NotificationFollowup, Type::FOLLOWUP_OPENED, Side::SYSTEM), 'unsaved follow-up');
    }

    public function test_the_database_refuses_a_recipient_of_another_message(): void
    {
        $other = $this->publishedMessage();

        $this->expectException(QueryException::class);
        DB::table('notification_recipient_events')->insert([
            'message_id' => $other->id, 'recipient_id' => $this->recipient->id, 'event_type' => 'VIEWED',
            'actor_user_id' => $this->familyUser->id, 'actor_side' => 'FAMILY', 'metadata' => '{}', 'created_at' => now(),
        ]);
    }

    public function test_events_are_never_updated_or_deleted(): void
    {
        $event = $this->contact();

        foreach ([fn () => $event->forceFill(['reason_code' => 'CHANGED'])->save(), fn () => $event->delete()] as $change) {
            try {
                $change();
                $this->fail('Expected the model to refuse.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach ([fn () => DB::table('notification_recipient_events')->where('id', $event->id)->update(['reason_code' => 'CHANGED']),
                fn () => DB::table('notification_recipient_events')->where('id', $event->id)->delete()] as $change) {
                try {
                    DB::transaction($change);
                    $this->fail('Expected the append-only trigger to refuse.');
                } catch (Throwable $e) {
                    $this->assertStringContainsString('append-only', $e->getMessage());
                }
            }
        }
        $this->assertSame('NO_ANSWER', $event->fresh()->reason_code);
    }
}
