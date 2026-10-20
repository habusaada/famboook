<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\AcknowledgeNotificationAction;
use App\Actions\Notifications\CloseFollowupManuallyAction;
use App\Actions\Notifications\RecordFollowupContactAction;
use App\Actions\Notifications\RequestPublicationAction;
use App\Enums\FamilyStatus;
use App\Exceptions\NotificationFollowupException;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\Family;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\Notifications\NotificationFollowupEngine;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01E.3-F3.1: the follow-up management Domain Actions — contact
 * recording and manual closure — business rules, the authorization matrix
 * (central Staff; the CURRENT primary coordinator only, never for their own
 * household), validation at the domain boundary and audit atomicity.
 * Driver-agnostic (also run on PostgreSQL, where the CHECKs and the
 * append-only trigger apply); the races are in
 * FollowupManagementConcurrencyTest. Synthetic data only.
 */
class FollowupManagementActionsTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private User $staff;

    private User $head;

    private Family $family;

    private Clan $testClan;

    private BranchGroup $testGroup;

    private Branch $testBranch;

    private NotificationMessage $message;

    private NotificationRecipient $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        config(['notifications.followups_enabled' => true, 'notifications.followups_cutoff' => '2026-10-01T00:00:00Z']);
        $this->staff = $this->staffSender();
        $this->testClan = $this->clan();
        $this->testGroup = $this->group($this->testClan);
        $this->testBranch = $this->branch($this->testClan, $this->testGroup);
        $activated = $this->activatedHead('123456789');
        $this->head = $activated['user'];
        $this->family = $activated['family'];
        $this->family->forceFill(['clan_id' => $this->testClan->id, 'branch_id' => $this->testBranch->id])->save();
        [$this->message, $this->recipient] = $this->publish($this->family);
    }

    /** @return array{0: NotificationMessage, 1: NotificationRecipient} IMPORTANT, FIRST and SECOND both OPEN */
    private function publish(Family $family, string $level = 'IMPORTANT', ?int $expiresInHours = null, int $after = 80): array
    {
        $input = ['level' => $level] + ($expiresInHours === null ? [] : ['expires_at' => now()->addHours($expiresInHours)->toIso8601String()]);
        $draft = $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$family->id]], $input);
        app(RequestPublicationAction::class)->handle($this->staff, $draft);
        app(NotificationPublisher::class)->publish($draft->id);
        $this->travel($after)->hours();
        $this->freshHeartbeat();
        app(NotificationFollowupEngine::class)->run();
        $message = $draft->fresh();

        return [$message, NotificationRecipient::query()->where('message_id', $message->id)->sole()];
    }

    private function followup(string $stage = 'FIRST', ?NotificationRecipient $recipient = null): NotificationFollowup
    {
        return NotificationFollowup::query()->where('recipient_id', ($recipient ?? $this->recipient)->id)->where('stage', $stage)->sole();
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    private function contact(User $actor, ?NotificationFollowup $followup = null, string $outcome = 'CALLED_NO_ANSWER', ?string $note = null)
    {
        return app(RecordFollowupContactAction::class)->handle($actor->fresh(), ($followup ?? $this->followup())->uuid, $outcome, $note);
    }

    private function close(User $actor, ?NotificationFollowup $followup = null, string $reason = 'INFORMED_BY_PHONE', ?string $note = null): NotificationFollowup
    {
        return app(CloseFollowupManuallyAction::class)->handle($actor->fresh(), ($followup ?? $this->followup())->uuid, $reason, $note);
    }

    private function refused(string $code, callable $attempt): void
    {
        $events = NotificationRecipientEvent::query()->count();
        $rows = DB::table('notification_followups')->orderBy('id')->get()->toJson();
        try {
            $attempt();
            $this->fail("expected {$code}");
        } catch (NotificationFollowupException $e) {
            $this->assertSame($code, $e->reason);
        }
        $this->assertSame($events, NotificationRecipientEvent::query()->count(), 'a refusal writes no event');
        $this->assertSame($rows, DB::table('notification_followups')->orderBy('id')->get()->toJson(), 'a refusal changes no follow-up');
    }

    private function recipientState(): array
    {
        return $this->recipient->fresh()->only(['viewed_at', 'viewed_by_user_id', 'acknowledged_at', 'acknowledged_by_user_id', 'acknowledged_late']);
    }

    // -------------------------------------------------------------- contact

    public function test_staff_record_a_contact_without_closing_or_touching_the_family_state(): void
    {
        $before = $this->recipientState();
        $administrator = $this->withRole('ADMINISTRATOR');

        $event = $this->contact($administrator, outcome: 'CALLED_INFORMED', note: '  اتصال هاتفي مع رب الأسرة.  ');

        $this->assertSame(['CONTACT_RECORDED', 'STAFF', $administrator->id, 'CALLED_INFORMED'],
            [$event->event_type->value, $event->actor_side->value, $event->actor_user_id, $event->reason_code]);
        $this->assertSame('اتصال هاتفي مع رب الأسرة.', $event->fresh()->internal_note);
        $this->assertEquals(['stage' => 'FIRST'], $event->fresh()->metadata);
        $this->assertSame([$this->followup()->id, $this->recipient->id, $this->message->id], [$event->followup_id, $event->recipient_id, $event->message_id]);
        $this->assertSame('OPEN', $this->followup()->status->value, 'a contact never closes');
        $this->assertSame($before, $this->recipientState(), 'never a view or an acknowledgement');
        $this->assertSame(0, NotificationRecipientEvent::query()->whereIn('event_type', ['VIEWED', 'ACKNOWLEDGED', 'ACKNOWLEDGED_LATE'])->count());

        // Several contacts are allowed, each its own event.
        $this->contact($this->withRole('SUPER_ADMIN'), outcome: 'VISITED');
        $this->assertSame(2, NotificationRecipientEvent::query()->where('event_type', 'CONTACT_RECORDED')->count());
    }

    // -------------------------------------------------------------- closure

    public function test_a_manual_closure_closes_one_stage_once_and_never_touches_the_family_state(): void
    {
        $before = $this->recipientState();
        $administrator = $this->withRole('ADMINISTRATOR');

        $closed = $this->close($administrator, reason: 'FAMILY_UNREACHABLE', note: 'تعذر الوصول بعد ثلاث محاولات.');

        $this->assertSame(['CLOSED', 'CLOSED_MANUALLY', $administrator->id, 'FAMILY_UNREACHABLE'],
            [$closed->status->value, $closed->outcome->value, $closed->closed_by_user_id, $closed->close_reason_code]);
        $this->assertSame(now()->toDateTimeString(), $closed->fresh()->closed_at->toDateTimeString());
        $event = NotificationRecipientEvent::query()->where('followup_id', $closed->id)->where('event_type', 'FOLLOWUP_CLOSED')->sole();
        $this->assertSame(['STAFF', $administrator->id, 'FAMILY_UNREACHABLE', 'تعذر الوصول بعد ثلاث محاولات.'],
            [$event->actor_side->value, $event->actor_user_id, $event->reason_code, $event->internal_note]);
        $this->assertEquals(['stage' => 'FIRST', 'outcome' => 'CLOSED_MANUALLY'], $event->metadata);
        $this->assertSame($before, $this->recipientState());
        $this->assertSame('OPEN', $this->followup('SECOND')->status->value, 'SECOND is its own stage');

        // Never reopened, never closed twice.
        $this->refused(NotificationFollowupException::NOT_OPEN, fn () => $this->close($administrator));
        $this->refused(NotificationFollowupException::NOT_OPEN, fn () => $this->contact($administrator));
    }

    public function test_a_manually_closed_first_never_suppresses_the_important_second(): void
    {
        $family = $this->familyIn($this->testClan, $this->testBranch);
        [, $recipient] = $this->publish($family, after: 24);
        $this->assertSame(['FIRST'], NotificationFollowup::query()->where('recipient_id', $recipient->id)->pluck('stage')->map->value->all());
        $this->close($this->withRole('ADMINISTRATOR'), $this->followup('FIRST', $recipient), 'INFORMED_IN_PERSON');

        $this->travel(48)->hours();
        $this->freshHeartbeat();
        app(NotificationFollowupEngine::class)->run();

        $this->assertSame('CLOSED_MANUALLY', $this->followup('FIRST', $recipient)->outcome->value);
        $this->assertSame('OPEN', $this->followup('SECOND', $recipient)->status->value);
    }

    public function test_after_a_manual_closure_the_acknowledgement_satisfies_only_what_is_still_open(): void
    {
        $this->close($this->withRole('ADMINISTRATOR'));
        $context = app(FamilyAccessResolver::class)->familyContext($this->head->fresh());
        app(AcknowledgeNotificationAction::class)->handle($context, $this->message->uuid);

        $this->assertSame('CLOSED_MANUALLY', $this->followup('FIRST')->outcome->value);
        $this->assertSame('SATISFIED', $this->followup('SECOND')->outcome->value);
        $this->assertSame(2, NotificationRecipientEvent::query()->where('event_type', 'FOLLOWUP_CLOSED')->count());
    }

    public function test_expiry_wins_and_never_changes_a_manually_closed_stage(): void
    {
        $family = $this->familyIn($this->testClan, $this->testBranch);
        [, $recipient] = $this->publish($family, expiresInHours: 90);
        $administrator = $this->withRole('ADMINISTRATOR');
        $this->close($administrator, $this->followup('FIRST', $recipient));

        $this->travel(10)->hours();
        $this->refused(NotificationFollowupException::EXPIRED, fn () => $this->contact($administrator, $this->followup('SECOND', $recipient)));
        $this->refused(NotificationFollowupException::EXPIRED, fn () => $this->close($administrator, $this->followup('SECOND', $recipient)));
        $this->freshHeartbeat();
        app(NotificationFollowupEngine::class)->run();

        $this->assertSame('CLOSED_MANUALLY', $this->followup('FIRST', $recipient)->outcome->value);
        $this->assertSame('EXPIRED', $this->followup('SECOND', $recipient)->outcome->value);
    }

    // -------------------------------------------------------- authorization

    public function test_only_central_staff_with_the_central_permission_manage_as_staff(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR'] as $role) {
            $this->contact($this->withRole($role));
        }
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $user = $this->withRole($role);
            $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->contact($user));
            $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->close($user));
        }

        // An inactive administrator; an administrator whose role lost the permission.
        $inactive = $this->withRole('ADMINISTRATOR');
        $inactive->forceFill(['is_active' => false])->save();
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->close($inactive));
        Role::findByName('ADMINISTRATOR', 'web')->revokePermissionTo('notification-followup.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->close($this->withRole('ADMINISTRATOR')));
    }

    public function test_only_the_current_primary_coordinator_manages_never_another_in_scope_coordinator(): void
    {
        $primary = $this->coordinator('111111110');
        $secondary = $this->coordinator('211111110');
        $outside = $this->coordinator('222222220');
        $this->assign($primary, $this->testBranch);
        $this->assign($secondary, $this->testClan);
        $this->assign($outside, $this->clan('OTHER_CLAN'));

        $event = $this->contact($primary, outcome: 'MESSAGE_LEFT');
        $this->assertSame(['COORDINATOR', $primary->id], [$event->actor_side->value, $event->actor_user_id]);
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->contact($secondary));
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->close($secondary));
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->contact($outside));
        // The family's household head (FAMILY_USER) never manages its own follow-ups.
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->contact($this->head));

        $closed = $this->close($primary, reason: 'INFORMED_BY_PHONE');
        $this->assertSame([$primary->id, 'CLOSED_MANUALLY'], [$closed->closed_by_user_id, $closed->outcome->value]);
        $this->assertSame('COORDINATOR', NotificationRecipientEvent::query()->where('followup_id', $closed->id)->where('event_type', 'FOLLOWUP_CLOSED')->sole()->actor_side->value);
    }

    public function test_a_historical_assignment_or_a_lost_role_or_permission_grants_nothing(): void
    {
        $primary = $this->coordinator('111111110');
        $assignment = $this->assign($primary, $this->testBranch);
        $this->contact($primary);

        $assignment->forceFill(['revoked_at' => now(), 'revoked_by' => $this->withRole('SUPER_ADMIN')->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->contact($primary));

        $this->assign($primary, $this->testBranch);
        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-notification-followup.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->close($primary));
        Role::findByName('COORDINATOR', 'web')->givePermissionTo('coordinator-notification-followup.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->contact($primary);

        $primary->removeRole('COORDINATOR');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->contact($primary));
    }

    public function test_a_coordinator_never_manages_their_own_household_and_ownership_falls_to_the_next(): void
    {
        $self = $this->coordinator('111111110');
        $next = $this->coordinator('211111110');
        $own = app(FamilyAccessResolver::class)->familyContext($self->fresh())->family;
        $own->forceFill(['clan_id' => $this->testClan->id, 'branch_id' => $this->testBranch->id])->save();
        $this->assign($self, $this->testBranch);
        [, $ownRecipient] = $this->publish($own);

        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->contact($self, $this->followup('FIRST', $ownRecipient)));
        $this->assign($next, $this->testClan);
        $this->contact($next, $this->followup('FIRST', $ownRecipient));
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->close($self, $this->followup('FIRST', $ownRecipient)));
    }

    public function test_a_more_specific_assignment_moves_management_to_the_new_primary(): void
    {
        $clanLevel = $this->coordinator('111111110');
        $branchLevel = $this->coordinator('211111110');
        $this->assign($clanLevel, $this->testClan);
        $this->contact($clanLevel);

        $this->assign($branchLevel, $this->testBranch);
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->contact($clanLevel));
        $this->close($branchLevel);
    }

    public function test_an_inactive_family_is_central_and_closed_by_staff_with_family_inactive(): void
    {
        $primary = $this->coordinator('111111110');
        $this->assign($primary, $this->testBranch);
        $this->family->forceFill(['status' => FamilyStatus::INACTIVE->value])->save();

        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->close($primary));
        $closed = $this->close($this->withRole('ADMINISTRATOR'), reason: 'FAMILY_INACTIVE');
        $this->assertSame('FAMILY_INACTIVE', $closed->close_reason_code);
        $this->assertSame('OPEN', $this->followup('SECOND')->status->value, 'never EXPIRED for inactivity');
    }

    // ------------------------------------------------------------ validation

    public function test_malformed_requests_are_refused_without_any_event(): void
    {
        $administrator = $this->withRole('ADMINISTRATOR');
        foreach (['', 'called_informed', 'INFORMED_BY_PHONE', 'ACKNOWLEDGED', 'X'] as $bad) {
            $this->refused(NotificationFollowupException::REASON_INVALID, fn () => $this->contact($administrator, outcome: $bad));
        }
        foreach (['', 'CALLED_INFORMED', 'SATISFIED', 'EXPIRED', 'not_applicable'] as $bad) {
            $this->refused(NotificationFollowupException::REASON_INVALID, fn () => $this->close($administrator, reason: $bad));
        }
        // Empty, ASCII or Unicode blanks only (no-break, zero-width, ideographic space, BOM), invalid UTF-8, too long.
        foreach (['', '   ', "\t\n", "\u{00A0}\u{200B}", "\u{3000}\u{FEFF}\u{2007}", "\xC3\x28", str_repeat('ب', 1001)] as $bad) {
            $this->refused(NotificationFollowupException::NOTE_INVALID, fn () => $this->contact($administrator, note: $bad));
        }
        // A Unicode space INSIDE a note is fine (the accepted contract is unchanged).
        $this->assertSame("ملاحظة\u{00A0}قصيرة", $this->contact($administrator, note: " ملاحظة\u{00A0}قصيرة ")->fresh()->internal_note);
        foreach (['not-a-uuid', '00000000-0000-4000-8000-000000000000'] as $uuid) {
            $this->refused(NotificationFollowupException::NOT_FOUND,
                fn () => app(RecordFollowupContactAction::class)->handle($administrator, $uuid, 'OTHER'));
        }
        // A family-side account without any management permission is refused before any lock.
        $this->refused(NotificationFollowupException::ACTOR_NOT_ALLOWED, fn () => $this->close($this->head));

        // The boundary: exactly 1000 characters.
        $event = $this->contact($administrator, note: str_repeat('ب', 1000));
        $this->assertSame(1000, mb_strlen($event->fresh()->internal_note));
    }

    // -------------------------------------------------------- audit, privacy

    /** A test-database trigger refusing FOLLOWUP_CLOSED / CONTACT_RECORDED events. */
    private function refuseEvent(string $type): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION test_refuse_followup_event() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'refused'; END; \$\$");
            DB::unprepared("CREATE TRIGGER test_refuse_followup_event BEFORE INSERT ON notification_recipient_events FOR EACH ROW WHEN (NEW.event_type = '{$type}') EXECUTE FUNCTION test_refuse_followup_event()");
        } else {
            DB::unprepared("CREATE TRIGGER test_refuse_followup_event BEFORE INSERT ON notification_recipient_events WHEN NEW.event_type = '{$type}' BEGIN SELECT RAISE(ABORT, 'refused'); END");
        }
    }

    public function test_a_failed_event_rolls_the_closure_back_entirely(): void
    {
        $this->refuseEvent('FOLLOWUP_CLOSED');
        try {
            $this->close($this->withRole('ADMINISTRATOR'));
            $this->fail('the closure must fail with its event');
        } catch (QueryException) {
            // one transaction
        }

        $followup = $this->followup();
        $this->assertSame(['OPEN', null, null, null], [$followup->status->value, $followup->outcome, $followup->closed_by_user_id, $followup->close_reason_code]);
        $this->assertSame(0, NotificationRecipientEvent::query()->where('event_type', 'FOLLOWUP_CLOSED')->count());
    }

    public function test_notes_never_reach_the_logs_and_existing_events_are_never_changed(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged) {
            $logged[] = $log->message.' '.json_encode($log->context);
        });
        $history = NotificationRecipientEvent::query()->orderBy('id')->get()->toJson();
        $administrator = $this->withRole('ADMINISTRATOR');

        $this->contact($administrator, note: 'SECRET-NOTE-0599');
        $this->close($administrator, note: 'SECRET-NOTE-0598');
        $this->refused(NotificationFollowupException::NOT_OPEN, fn () => $this->close($administrator, note: 'SECRET-NOTE-0597'));

        $this->assertStringNotContainsString('SECRET-NOTE', implode("\n", $logged));
        $this->assertStringStartsWith(rtrim($history, ']'), NotificationRecipientEvent::query()->orderBy('id')->get()->toJson(), 'earlier events unchanged');
    }
}
