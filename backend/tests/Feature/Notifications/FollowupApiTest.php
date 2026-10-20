<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RecordFollowupContactAction;
use App\Actions\Notifications\RequestPublicationAction;
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
use App\Support\Notifications\FollowupOwnership;
use App\Support\Notifications\NotificationFollowupEngine;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PDOException;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01E.3-F3.2: the Staff and coordinator follow-up APIs — access,
 * visibility (current scope; out of scope = the same 404 as a nonexistent
 * uuid), primary-only management, filters, pagination, set-based
 * ownership=mine, field privacy, history labels and notes, idempotent
 * contacts, validation, error mapping, throttles, no-store, and the OFF gate.
 * Synthetic data only.
 */
class FollowupApiTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private const STAFF = '/api/v1/notifications/followups';

    private const COORDINATOR = '/api/v1/family/coordinator/followups';

    private User $staffSender;

    private User $admin;

    private User $administrator;

    private User $head;

    private User $primary;

    private User $secondary;

    private User $outsider;

    private Clan $testClan;

    private BranchGroup $testGroup;

    private Branch $testBranch;

    private Family $family;

    private Family $sibling;

    private Family $foreign;

    private NotificationRecipient $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        config(['notifications.followups_enabled' => true, 'notifications.followups_cutoff' => '2026-10-01T00:00:00Z']);
        $this->staffSender = $this->staffSender();
        $this->admin = $this->withRole('SUPER_ADMIN');
        $this->administrator = $this->withRole('ADMINISTRATOR');
        $this->testClan = $this->clan();
        $this->testGroup = $this->group($this->testClan);
        $this->testBranch = $this->branch($this->testClan, $this->testGroup);
        $otherClan = $this->clan('OTHER_CLAN');

        $household = $this->activatedHead('123456789');
        $this->head = $household['user'];
        $this->family = $household['family'];
        $this->family->forceFill(['clan_id' => $this->testClan->id, 'branch_id' => $this->testBranch->id])->save();
        $this->sibling = $this->familyIn($this->testClan, $this->testBranch);
        $this->foreign = $this->familyIn($otherClan);

        $this->primary = $this->coordinator('111111110');
        $this->secondary = $this->coordinator('211111110');
        $this->outsider = $this->coordinator('222222220');
        $this->assign($this->primary, $this->testBranch);
        $this->assign($this->secondary, $this->testClan);
        $this->assign($this->outsider, $otherClan);

        $this->recipient = $this->publish($this->family, 'IMPORTANT');
        $this->publish($this->sibling, 'NORMAL');
        $this->publish($this->foreign, 'NORMAL');
        $this->travel(80)->hours();
        $this->freshHeartbeat();
        app(NotificationFollowupEngine::class)->run();
        $this->assertSame(4, NotificationFollowup::query()->count());
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function publish(Family $family, string $level): NotificationRecipient
    {
        $draft = $this->draft($this->staffSender, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$family->id]], ['level' => $level]);
        app(RequestPublicationAction::class)->handle($this->staffSender, $draft);
        app(NotificationPublisher::class)->publish($draft->id);

        return NotificationRecipient::query()->where('message_id', $draft->id)->sole();
    }

    private function followup(string $stage = 'FIRST', ?NotificationRecipient $recipient = null): NotificationFollowup
    {
        return NotificationFollowup::query()->where('recipient_id', ($recipient ?? $this->recipient)->id)->where('stage', $stage)->sole();
    }

    private function as(?User $user, string $method, string $uri, array $body = []): TestResponse
    {
        if ($user === null) {
            $this->app['auth']->forgetGuards();

            return $this->json($method, $uri, $body);
        }

        return $this->actingAs($user->fresh())->json($method, $uri, $body);
    }

    private function contactBody(array $o = []): array
    {
        return ['outcome' => 'CALLED_NO_ANSWER', 'client_reference' => (string) Str::uuid(), ...$o];
    }

    /** @return list<string> the uuids of a list response, in order */
    private function ids(TestResponse $response): array
    {
        return array_column($response->json('data'), 'id');
    }

    // ------------------------------------------------------------- access

    public function test_every_route_needs_authentication_the_side_and_the_permission(): void
    {
        $uuid = $this->followup()->uuid;
        $routes = fn (string $base) => [
            ['GET', $base], ['GET', "{$base}/summary"], ['GET', "{$base}/{$uuid}"], ['GET', "{$base}/{$uuid}/history"],
            ['POST', "{$base}/{$uuid}/contacts"], ['POST', "{$base}/{$uuid}/close"],
        ];
        $dataEntry = $this->withRole('DATA_ENTRY');
        foreach ($routes(self::STAFF) as [$method, $uri]) {
            $this->as(null, $method, $uri)->assertUnauthorized();
            $this->as($dataEntry, $method, $uri)->assertForbidden();
            $this->as($this->head, $method, $uri)->assertForbidden();
            $this->as($this->primary, $method, $uri)->assertForbidden();
        }
        foreach ($routes(self::COORDINATOR) as [$method, $uri]) {
            $this->as(null, $method, $uri)->assertUnauthorized();
            $this->as($this->administrator, $method, $uri)->assertForbidden();
            $this->as($this->head, $method, $uri)->assertForbidden();
        }
    }

    // ---------------------------------------------------------- staff lists

    public function test_staff_lists_every_open_follow_up_in_due_order_with_filters_and_bounded_pages(): void
    {
        $all = $this->as($this->administrator, 'GET', self::STAFF)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(4, $all->json('data'));
        $due = array_column($all->json('data'), 'due_at');
        $sorted = $due;
        sort($sorted);
        $this->assertSame($sorted, $due, 'OPEN lists are ordered by due_at');

        $this->assertCount(1, $this->as($this->administrator, 'GET', self::STAFF.'?stage=SECOND')->json('data'));
        $this->assertCount(2, $this->as($this->administrator, 'GET', self::STAFF.'?level=IMPORTANT')->json('data'));
        $this->assertCount(2, $this->as($this->administrator, 'GET', self::STAFF.'?family_code='.$this->family->family_code)->json('data'));
        $this->assertCount(0, $this->as($this->administrator, 'GET', self::STAFF.'?status=CLOSED')->json('data'));
        // Everything was published at the same instant: only the IMPORTANT FIRST (24 h) is due by then.
        $this->assertSame([$this->followup()->uuid], $this->ids($this->as($this->administrator, 'GET', self::STAFF.'?due_before='.urlencode(now()->subHours(56)->toIso8601String()))));
        $page = $this->as($this->administrator, 'GET', self::STAFF.'?per_page=1&page=2')->assertOk();
        $this->assertSame([1, 4, 2], [count($page->json('data')), $page->json('meta.total'), $page->json('meta.current_page')]);

        $this->as($this->administrator, 'POST', self::STAFF."/{$this->followup()->uuid}/close", ['reason' => 'NOT_APPLICABLE'])->assertOk();
        $closed = $this->as($this->administrator, 'GET', self::STAFF.'?status=CLOSED&outcome=CLOSED_MANUALLY')->assertOk();
        $this->assertSame([$this->followup()->uuid], $this->ids($closed));

        foreach (['per_page=51', 'status=DONE', 'stage=THIRD', 'level=URGENT', 'outcome=WON', 'due_before=tomorrow', 'page=0'] as $bad) {
            $this->as($this->administrator, 'GET', self::STAFF.'?'.$bad)->assertUnprocessable();
        }
    }

    public function test_list_and_detail_expose_no_internal_identifier_contact_data_or_note(): void
    {
        $this->as($this->administrator, 'POST', self::STAFF."/{$this->followup()->uuid}/contacts", $this->contactBody(['note' => 'SECRET-NOTE']))->assertCreated();
        $responses = [
            $this->as($this->administrator, 'GET', self::STAFF),
            $this->as($this->administrator, 'GET', self::STAFF."/{$this->followup()->uuid}"),
            $this->as($this->primary, 'GET', self::COORDINATOR),
            $this->as($this->primary, 'GET', self::COORDINATOR."/{$this->followup()->uuid}"),
        ];
        foreach ($responses as $response) {
            $body = $response->assertOk()->getContent();
            foreach (['recipient_id', '"family_id"', 'user_id', 'assignment', 'national_id', 'mobile', 'phone', 'SECRET-NOTE', 'internal_note', 'client_reference', 'head_person', '"note"'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, $secret);
            }
        }
        $item = $this->as($this->administrator, 'GET', self::STAFF.'?family_code='.$this->family->family_code)->json('data.0');
        $this->assertSame(['family_code', 'head_name'], array_keys($item['family']));
        $headName = app(FamilyAccessResolver::class)->familyContext($this->head->fresh())->person->full_name;
        $this->assertSame([$this->family->family_code, $headName], [$item['family']['family_code'], $item['family']['head_name']]);
    }

    // ----------------------------------------------------- coordinator lists

    public function test_a_coordinator_lists_only_the_current_scope_and_mine_is_set_based_and_exact(): void
    {
        $inScope = [$this->followup()->uuid, $this->followup('SECOND')->uuid, NotificationFollowup::query()
            ->whereHas('recipient', fn ($q) => $q->where('family_id', $this->sibling->id))->sole()->uuid];
        sort($inScope);

        foreach ([$this->primary, $this->secondary] as $coordinator) {
            $ids = $this->ids($this->as($coordinator, 'GET', self::COORDINATOR)->assertOk()->assertHeader('Cache-Control', 'no-store, private'));
            sort($ids);
            $this->assertSame($inScope, $ids);
        }
        $this->assertCount(1, $this->as($this->outsider, 'GET', self::COORDINATOR)->json('data'));

        // ownership=mine before pagination: exactly FollowupOwnership's answer.
        $mine = $this->ids($this->as($this->primary, 'GET', self::COORDINATOR.'?ownership=mine'));
        sort($mine);
        $this->assertSame($inScope, $mine);
        $this->assertSame([], $this->ids($this->as($this->secondary, 'GET', self::COORDINATOR.'?ownership=mine')));
        $paged = $this->as($this->primary, 'GET', self::COORDINATOR.'?ownership=mine&per_page=1')->assertOk();
        $this->assertSame([1, 3], [count($paged->json('data')), $paged->json('meta.total')]);

        $rows = collect($this->as($this->secondary, 'GET', self::COORDINATOR)->json('data'));
        $this->assertTrue($rows->every(fn ($row) => $row['can_manage'] === false));
        $this->assertTrue(collect($this->as($this->primary, 'GET', self::COORDINATOR)->json('data'))->every(fn ($row) => $row['can_manage'] === true));

        $this->as($this->primary, 'GET', self::COORDINATOR.'?per_page=26')->assertUnprocessable();
        $this->as($this->primary, 'GET', self::COORDINATOR.'?ownership=others')->assertUnprocessable();
    }

    public function test_mine_follows_every_ownership_rule_like_followup_ownership(): void
    {
        // The primary coordinator's own household in scope, and a more specific newcomer.
        $own = app(FamilyAccessResolver::class)->familyContext($this->primary->fresh())->family;
        $own->forceFill(['clan_id' => $this->testClan->id, 'branch_id' => $this->testBranch->id])->save();
        $ownRecipient = $this->publish($own, 'NORMAL');
        $narrow = $this->branch($this->testClan, $this->testGroup);
        $this->sibling->forceFill(['branch_id' => $narrow->id])->save();
        $newcomer = $this->coordinator('333333330');
        $this->assign($newcomer, $narrow);
        $this->travel(80)->hours();
        $this->freshHeartbeat();
        app(NotificationFollowupEngine::class)->run();

        $ownership = app(FollowupOwnership::class);
        foreach ([$this->primary, $this->secondary, $newcomer] as $coordinator) {
            $mine = $this->ids($this->as($coordinator, 'GET', self::COORDINATOR.'?ownership=mine&per_page=25'));
            $expected = NotificationFollowup::query()->with('recipient')->get()
                ->filter(fn (NotificationFollowup $f) => $f->status->value === 'OPEN'
                    && $ownership->isPrimary($coordinator->fresh(), Family::query()->findOrFail($f->recipient->family_id)))
                ->pluck('uuid')->sort()->values()->all();
            sort($mine);
            $this->assertSame($expected, $mine, "coordinator {$coordinator->id}");
        }
        // The own household's follow-up is the secondary's (the next eligible coordinator).
        $ownFollowup = $this->followup('FIRST', $ownRecipient)->uuid;
        $this->assertContains($ownFollowup, $this->ids($this->as($this->secondary, 'GET', self::COORDINATOR.'?ownership=mine')));
        $this->assertNotContains($ownFollowup, $this->ids($this->as($this->primary, 'GET', self::COORDINATOR.'?ownership=mine')));
    }

    public function test_summaries_count_in_sql_over_the_reader_scope(): void
    {
        $staff = $this->as($this->administrator, 'GET', self::STAFF.'/summary')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertSame([4, ['FIRST' => 3, 'SECOND' => 1], ['NORMAL' => 2, 'IMPORTANT' => 2], 0],
            [$staff['open'], $staff['open_by_stage'], $staff['open_by_level'], $staff['expired_open']]);
        $this->assertArrayNotHasKey('mine_open', $staff);

        $primary = $this->as($this->primary, 'GET', self::COORDINATOR.'/summary')->assertOk()->json('data');
        $this->assertSame([3, 3], [$primary['open'], $primary['mine_open']]);
        $secondary = $this->as($this->secondary, 'GET', self::COORDINATOR.'/summary')->json('data');
        $this->assertSame([3, 0], [$secondary['open'], $secondary['mine_open']]);
        $this->assertSame([1, 1], [$this->as($this->outsider, 'GET', self::COORDINATOR.'/summary')->json('data.open'),
            $this->as($this->outsider, 'GET', self::COORDINATOR.'/summary')->json('data.mine_open')]);

        $this->as($this->administrator, 'POST', self::STAFF."/{$this->followup()->uuid}/close", ['reason' => 'NOT_APPLICABLE'])->assertOk();
        $after = $this->as($this->administrator, 'GET', self::STAFF.'/summary')->json('data');
        $this->assertSame([3, 1], [$after['open'], $after['closed_last_30_days']['CLOSED_MANUALLY']]);
    }

    // ------------------------------------------------------ detail, history

    public function test_detail_shows_the_side_management_information_and_out_of_scope_is_a_plain_404(): void
    {
        $uuid = $this->followup()->uuid;
        $staff = $this->as($this->administrator, 'GET', self::STAFF."/{$uuid}")->assertOk()->json('data');
        $this->assertSame(['kind' => 'PRIMARY_COORDINATOR'], $staff['ownership']);
        $this->assertSame([false, true], [$staff['recipient']['acknowledged_late'], $staff['recipient']['had_family_account']]);
        $this->assertSame(0, $staff['contacts']);
        $this->assertTrue($this->as($this->primary, 'GET', self::COORDINATOR."/{$uuid}")->assertOk()->json('data.can_manage'));
        $this->assertFalse($this->as($this->secondary, 'GET', self::COORDINATOR."/{$uuid}")->assertOk()->json('data.can_manage'));

        // Out of scope and nonexistent: the same 404, the same body.
        $outOfScope = $this->as($this->outsider, 'GET', self::COORDINATOR."/{$uuid}")->assertNotFound();
        $missing = $this->as($this->outsider, 'GET', self::COORDINATOR.'/'.Str::uuid())->assertNotFound();
        $this->assertSame($missing->json(), $outOfScope->json());
        $this->assertSame('FOLLOWUP_NOT_FOUND', $outOfScope->json('code'));
        $outOfScope->assertHeader('Cache-Control', 'no-store, private');
        foreach (['history', 'contacts', 'close'] as $path) {
            $method = $path === 'history' ? 'GET' : 'POST';
            $this->assertSame($missing->json(), $this->as($this->outsider, $method, self::COORDINATOR."/{$uuid}/{$path}", ['reason' => 'NOT_APPLICABLE', ...$this->contactBody()])->assertNotFound()->json());
        }
        // Not a uuid: the same 404 (never a database cast error on PostgreSQL).
        $this->as($this->administrator, 'GET', self::STAFF.'/not-a-uuid')->assertNotFound()->assertJson(['code' => 'FOLLOWUP_NOT_FOUND']);
        $this->as($this->primary, 'GET', self::COORDINATOR.'/not-a-uuid')->assertNotFound()->assertJson(['code' => 'FOLLOWUP_NOT_FOUND']);
        $this->as($this->primary, 'POST', self::COORDINATOR.'/not-a-uuid/contacts', $this->contactBody())->assertNotFound();

        // A family with no eligible coordinator is CENTRAL.
        $foreignFollowup = NotificationFollowup::query()->whereHas('recipient', fn ($q) => $q->where('family_id', $this->foreign->id))->sole();
        $this->outsider->removeRole('COORDINATOR');
        $this->assertSame(['kind' => 'CENTRAL'], $this->as($this->administrator, 'GET', self::STAFF."/{$foreignFollowup->uuid}")->json('data.ownership'));
    }

    public function test_history_names_actors_for_staff_and_labels_them_for_coordinators(): void
    {
        $uuid = $this->followup()->uuid;
        $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/contacts", $this->contactBody(['outcome' => 'VISITED', 'note' => 'زيارة منزلية']))->assertCreated();
        $this->as($this->administrator, 'POST', self::STAFF."/{$uuid}/contacts", $this->contactBody(['outcome' => 'CALLED_INFORMED']))->assertCreated();

        $staff = $this->as($this->admin, 'GET', self::STAFF."/{$uuid}/history")->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertSame(['FOLLOWUP_OPENED', 'CONTACT_RECORDED', 'CONTACT_RECORDED'], array_column($staff, 'type'));
        $this->assertSame([['side' => 'SYSTEM', 'name' => null], ['side' => 'COORDINATOR', 'name' => $this->primary->name], ['side' => 'STAFF', 'name' => $this->administrator->name]],
            array_column($staff, 'actor'));
        $this->assertSame('زيارة منزلية', $staff[1]['note']);

        $asPrimary = array_column($this->as($this->primary, 'GET', self::COORDINATOR."/{$uuid}/history")->json('data'), 'actor');
        $this->assertSame([['label' => 'SYSTEM'], ['label' => 'YOU'], ['label' => 'STAFF']], $asPrimary);
        $asSecondary = $this->as($this->secondary, 'GET', self::COORDINATOR."/{$uuid}/history")->assertOk();
        $this->assertSame([['label' => 'SYSTEM'], ['label' => 'COORDINATOR'], ['label' => 'STAFF']], array_column($asSecondary->json('data'), 'actor'));
        $this->assertStringNotContainsString($this->primary->name, $asSecondary->getContent(), 'another coordinator is never named');
        $this->assertStringNotContainsString($this->administrator->name, $asSecondary->getContent());
        $this->assertSame('زيارة منزلية', $asSecondary->json('data.1.note'));
    }

    public function test_a_coordinator_sees_no_notes_on_their_own_household(): void
    {
        $own = app(FamilyAccessResolver::class)->familyContext($this->secondary->fresh())->family;
        $own->forceFill(['clan_id' => $this->testClan->id, 'branch_id' => $this->testBranch->id])->save();
        $ownRecipient = $this->publish($own, 'NORMAL');
        $this->travel(80)->hours();
        $this->freshHeartbeat();
        app(NotificationFollowupEngine::class)->run();
        $uuid = $this->followup('FIRST', $ownRecipient)->uuid;
        $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/contacts", $this->contactBody(['note' => 'ملاحظة داخلية']))->assertCreated();

        $own = $this->as($this->secondary, 'GET', self::COORDINATOR."/{$uuid}/history")->assertOk();
        $this->assertNull($own->json('data.1.note'));
        $this->assertStringNotContainsString('ملاحظة داخلية', $own->getContent());
        $this->assertFalse($this->as($this->secondary, 'GET', self::COORDINATOR."/{$uuid}")->json('data.can_manage'));
        $this->as($this->secondary, 'POST', self::COORDINATOR."/{$uuid}/contacts", $this->contactBody())->assertForbidden();
    }

    // ----------------------------------------------------- contact, closure

    public function test_only_the_current_primary_coordinator_and_central_staff_manage(): void
    {
        $uuid = $this->followup()->uuid;
        $this->as($this->secondary, 'POST', self::COORDINATOR."/{$uuid}/contacts", $this->contactBody())
            ->assertForbidden()->assertJson(['code' => 'FOLLOWUP_ACTOR_NOT_ALLOWED'])->assertHeader('Cache-Control', 'no-store, private');
        $this->as($this->secondary, 'POST', self::COORDINATOR."/{$uuid}/close", ['reason' => 'NOT_APPLICABLE'])->assertForbidden();

        $created = $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/contacts", $this->contactBody(['outcome' => 'MESSAGE_LEFT']))
            ->assertCreated()->assertJson(['meta' => ['replayed' => false]])->json('data');
        $this->assertSame(['CONTACT_RECORDED', ['label' => 'YOU'], 'MESSAGE_LEFT', 'FIRST'], [$created['type'], $created['actor'], $created['reason_code'], $created['stage']]);

        $closed = $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/close", ['reason' => 'INFORMED_BY_PHONE', 'note' => 'تم الإبلاغ'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertSame(['CLOSED', 'CLOSED_MANUALLY', 'INFORMED_BY_PHONE', 1], [$closed['status'], $closed['outcome'], $closed['close_reason'], $closed['contacts']]);
        $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/close", ['reason' => 'INFORMED_BY_PHONE'])
            ->assertStatus(409)->assertJson(['code' => 'FOLLOWUP_NOT_OPEN']);
        $this->as($this->administrator, 'POST', self::STAFF."/{$this->followup('SECOND')->uuid}/close", ['reason' => 'FAMILY_UNREACHABLE'])->assertOk();
    }

    public function test_contacts_are_idempotent_per_actor_and_follow_up(): void
    {
        $uuid = $this->followup()->uuid;
        $body = $this->contactBody(['outcome' => 'VISITED', 'note' => 'زيارة']);
        $first = $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/contacts", $body)->assertCreated();
        $replay = $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/contacts", $body)->assertOk()->assertJson(['meta' => ['replayed' => true]]);
        $this->assertSame($first->json('data'), $replay->json('data'), 'the original result');
        $this->assertSame(1, NotificationRecipientEvent::query()->where('event_type', 'CONTACT_RECORDED')->count());

        // The same reference with another payload: 409, nothing written.
        foreach ([['outcome' => 'OTHER'], ['note' => 'غير ذلك'], ['note' => null]] as $change) {
            $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/contacts", [...$body, ...$change])
                ->assertStatus(409)->assertJson(['code' => 'FOLLOWUP_CLIENT_REFERENCE_CONFLICT']);
        }
        // The key never crosses actors or follow-ups: those are new contacts.
        $this->as($this->administrator, 'POST', self::STAFF."/{$uuid}/contacts", $body)->assertCreated();
        $this->as($this->primary, 'POST', self::COORDINATOR."/{$this->followup('SECOND')->uuid}/contacts", $body)->assertCreated();
        // An uppercase spelling of the same uuid is the same key.
        $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/contacts", [...$body, 'client_reference' => strtoupper($body['client_reference'])])->assertOk();
        $this->assertSame(3, NotificationRecipientEvent::query()->where('event_type', 'CONTACT_RECORDED')->count());
    }

    public function test_writes_are_validated_at_the_boundary_and_write_nothing_when_refused(): void
    {
        $uri = self::COORDINATOR."/{$this->followup()->uuid}";
        $bad = [
            ['contacts', ['outcome' => 'CALLED_NO_ANSWER']],
            ['contacts', $this->contactBody(['client_reference' => 'not-a-uuid'])],
            ['contacts', $this->contactBody(['outcome' => 'INFORMED_BY_PHONE'])],
            ['contacts', $this->contactBody(['note' => str_repeat('ب', 1001)])],
            ['close', ['reason' => 'CALLED_INFORMED']],
            ['close', []],
            ['close', ['reason' => 'NOT_APPLICABLE', 'note' => str_repeat('ب', 1001)]],
        ];
        foreach ($bad as [$path, $body]) {
            $this->as($this->primary, 'POST', "{$uri}/{$path}", $body)->assertUnprocessable();
        }
        $this->assertSame(0, NotificationRecipientEvent::query()->whereIn('event_type', ['CONTACT_RECORDED'])->count());
        $this->assertSame('OPEN', $this->followup()->status->value);

        // A blank (Unicode) note: the HTTP layer trims it to no note at all —
        // the contact is recorded without one (the domain refuses blanks too).
        $this->as($this->primary, 'POST', "{$uri}/contacts", $this->contactBody(['note' => "\u{00A0}\u{200B}"]))->assertCreated();
        $this->assertNull(NotificationRecipientEvent::query()->where('event_type', 'CONTACT_RECORDED')->sole()->internal_note);
    }

    public function test_a_lock_timeout_or_deadlock_is_503_followup_busy_and_other_database_errors_are_not_swallowed(): void
    {
        foreach (['55P03', '40P01'] as $state) {
            $this->failingContact($state);
            $this->as($this->primary, 'POST', self::COORDINATOR."/{$this->followup()->uuid}/contacts", $this->contactBody())
                ->assertStatus(503)->assertHeader('Retry-After', '1')->assertHeader('Cache-Control', 'no-store, private')
                ->assertJson(['code' => 'FOLLOWUP_BUSY']);
        }
        $this->failingContact('23502');
        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);
        $this->as($this->primary, 'POST', self::COORDINATOR."/{$this->followup()->uuid}/contacts", $this->contactBody());
    }

    private function failingContact(string $sqlState): void
    {
        $previous = new PDOException('simulated');
        $previous->errorInfo = [$sqlState, 7, 'simulated'];
        $action = Mockery::mock(RecordFollowupContactAction::class);
        $action->shouldReceive('record')->andThrow(new QueryException('pgsql', 'select 1', [], $previous));
        $this->app->instance(RecordFollowupContactAction::class, $action);
    }

    // ----------------------------------------------------- limits, OFF gate

    public function test_per_user_throttles_are_separate_per_side_and_per_kind(): void
    {
        config(['notifications.staff_limits.followup_read_user_minute' => 2, 'notifications.coordinator_limits.followup_write_user_minute' => 1]);
        $this->as($this->administrator, 'GET', self::STAFF)->assertOk();
        $this->as($this->administrator, 'GET', self::STAFF.'/summary')->assertOk();
        $this->as($this->administrator, 'GET', self::STAFF)->assertStatus(429);
        $this->as($this->admin, 'GET', self::STAFF)->assertOk();

        $uuid = $this->followup()->uuid;
        $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/contacts", $this->contactBody())->assertCreated();
        $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/contacts", $this->contactBody())->assertStatus(429);
        $this->as($this->primary, 'GET', self::COORDINATOR)->assertOk();
    }

    public function test_the_off_gate_never_hides_or_freezes_existing_follow_ups(): void
    {
        config(['notifications.mode' => 'OFF', 'notifications.followups_enabled' => false, 'notifications.followups_cutoff' => null]);
        $uuid = $this->followup()->uuid;

        $this->assertCount(4, $this->as($this->administrator, 'GET', self::STAFF)->assertOk()->json('data'));
        $this->assertCount(3, $this->as($this->primary, 'GET', self::COORDINATOR)->assertOk()->json('data'));
        $this->as($this->primary, 'POST', self::COORDINATOR."/{$uuid}/contacts", $this->contactBody())->assertCreated();
        $this->as($this->administrator, 'POST', self::STAFF."/{$uuid}/close", ['reason' => 'NOT_APPLICABLE'])->assertOk();
        $this->assertSame('CLOSED_MANUALLY', $this->followup()->outcome->value);
    }

    public function test_family_acknowledgement_semantics_are_untouched_by_the_api(): void
    {
        $context = app(FamilyAccessResolver::class)->familyContext($this->head->fresh());
        $message = NotificationMessage::query()->findOrFail($this->recipient->message_id);
        $this->actingAs($this->head->fresh())->postJson("/api/v1/family/notifications/{$message->uuid}/acknowledge")->assertOk();
        $this->assertNotNull($context);

        $data = $this->as($this->administrator, 'GET', self::STAFF."/{$this->followup()->uuid}")->json('data');
        $this->assertSame(['CLOSED', 'SATISFIED'], [$data['status'], $data['outcome']]);
        $this->assertNotNull($data['recipient']['acknowledged_at']);
    }
}
