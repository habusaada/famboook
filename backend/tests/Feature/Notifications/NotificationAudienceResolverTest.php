<?php

namespace Tests\Feature\Notifications;

use App\Enums\ChangeRequestType;
use App\Enums\FamilyStatus;
use App\Enums\NotificationAudienceType;
use App\Enums\NotificationKind;
use App\Enums\NotificationSenderSide;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationAudienceException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\Notifications\NotificationAudience;
use App\Support\Notifications\NotificationAudienceResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.3: NotificationAudienceResolver turns a requested audience
 * into a family query — Staff selections and send-all, the coordinator's
 * CURRENT scope (never widened by the selection), the SYSTEM origin — and
 * refuses invalid, unauthorized, ineligible and empty audiences. Nothing is
 * published or written. The proposed announcement.send-all permission exists
 * only inside these tests (never seeded). Synthetic data only.
 */
class NotificationAudienceResolverTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private NotificationAudienceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $this->resolver = app(NotificationAudienceResolver::class);
    }

    private function staff(bool $sendAll = false): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('ADMINISTRATOR', 'web'));
        if ($sendAll) {
            $user->givePermissionTo(Permission::findOrCreate(NotificationAudienceResolver::SEND_ALL_PERMISSION, 'web'));
        }

        return $user;
    }

    private function audience(array $raw): NotificationAudience
    {
        return NotificationAudience::fromArray($raw);
    }

    /** @return list<int> */
    private function ids(Builder $families): array
    {
        return $families->pluck('families.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** @return list<int> */
    private function idsOf(Family ...$families): array
    {
        return collect($families)->map(fn (Family $f) => (int) $f->id)->sort()->values()->all();
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$reason}.");
        } catch (NotificationAudienceException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    // ------------------------------------------------------------ audience shape

    public function test_the_audience_is_parsed_strictly(): void
    {
        $invalid = [
            null, 'STAFF_ALL_ACTIVE', [], ['type' => 'EVERYONE'], ['type' => 'STAFF_ALL_ACTIVE', 'family_ids' => [1]],
            ['type' => 'STAFF_FAMILIES'], ['type' => 'STAFF_FAMILIES', 'family_ids' => []],
            ['type' => 'STAFF_FAMILIES', 'family_ids' => ['1']], ['type' => 'STAFF_FAMILIES', 'family_ids' => [0]],
            ['type' => 'STAFF_FAMILIES', 'family_ids' => ['a' => 1]], ['type' => 'STAFF_FAMILIES', 'family_ids' => [1], 'clan_ids' => [1]],
            ['type' => 'STAFF_FAMILIES', 'family_ids' => range(1, NotificationAudience::MAX_SELECTED + 1)],
            ['type' => 'COORDINATOR_SELECTION', 'clan_ids' => [], 'branch_ids' => []],
            ['type' => 'COORDINATOR_SCOPE', 'scope' => 'ALL'],
        ];
        foreach ($invalid as $raw) {
            $this->assertRefused(NotificationAudienceException::INVALID, fn () => NotificationAudience::fromArray($raw));
        }

        $audience = $this->audience(['type' => 'COORDINATOR_SELECTION', 'family_ids' => [9, 3, 9], 'clan_ids' => [2], 'branch_ids' => []]);
        $this->assertSame(['type' => 'COORDINATOR_SELECTION', 'family_ids' => [3, 9], 'clan_ids' => [2]], $audience->toArray());
        $this->assertSame(NotificationSenderSide::COORDINATOR, $audience->type->senderSide());
    }

    // --------------------------------------------------------------------- Staff

    public function test_staff_may_address_one_or_several_selected_active_families_once_each(): void
    {
        $staff = $this->staff();
        [$a, $b] = [Family::factory()->create(), Family::factory()->create()];

        $one = $this->audience(['type' => 'STAFF_FAMILIES', 'family_ids' => [$a->id]]);
        $this->resolver->validateRequest($staff, $one);
        $this->assertSame($this->idsOf($a), $this->ids($this->resolver->families($staff, $one)));

        $several = $this->audience(['type' => 'STAFF_FAMILIES', 'family_ids' => [$b->id, $a->id, $b->id]]);
        $this->resolver->validateRequest($staff, $several);
        $this->assertSame($this->idsOf($a, $b), $this->ids($this->resolver->families($staff, $several)));
    }

    public function test_an_explicitly_selected_family_that_is_not_active_is_refused_at_request(): void
    {
        $staff = $this->staff();
        $active = Family::factory()->create();
        $inactive = Family::factory()->create(['status' => FamilyStatus::INACTIVE->value]);
        $archived = Family::factory()->create(['status' => FamilyStatus::ARCHIVED->value]);
        $deleted = Family::factory()->create();
        $deleted->delete();

        foreach ([$inactive->id, $archived->id, $deleted->id, 999999] as $id) {
            $this->assertRefused(NotificationAudienceException::FAMILY_NOT_ELIGIBLE, fn () => $this->resolver
                ->validateRequest($staff, $this->audience(['type' => 'STAFF_FAMILIES', 'family_ids' => [$active->id, $id]])));
        }
    }

    public function test_eligibility_is_re_resolved_at_publication(): void
    {
        $staff = $this->staff();
        [$a, $b] = [Family::factory()->create(), Family::factory()->create()];
        $audience = $this->audience(['type' => 'STAFF_FAMILIES', 'family_ids' => [$a->id, $b->id]]);
        $this->resolver->validateRequest($staff, $audience);

        $b->forceFill(['status' => FamilyStatus::INACTIVE->value])->save();
        $this->assertSame($this->idsOf($a), $this->ids($this->resolver->families($staff, $audience)));

        $a->delete();
        $this->assertFalse($this->resolver->families($staff, $audience)->exists());
    }

    public function test_all_active_families_require_send_all(): void
    {
        Family::factory()->count(2)->create();
        Family::factory()->create(['status' => FamilyStatus::INACTIVE->value]);
        Family::factory()->create()->delete();
        $all = $this->audience(['type' => 'STAFF_ALL_ACTIVE']);

        $this->assertRefused(NotificationAudienceException::NOT_AUTHORIZED, fn () => $this->resolver->validateRequest($this->staff(), $all));

        $sender = $this->staff(sendAll: true);
        $this->resolver->validateRequest($sender, $all);
        $expected = Family::query()->where('status', FamilyStatus::ACTIVE->value)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame($expected, $this->ids($this->resolver->families($sender, $all)));
        // A query over families, not a list of ids loaded into PHP.
        $this->assertStringNotContainsString(' in (', strtolower($this->resolver->families($sender, $all)->toSql()));
    }

    public function test_staff_audiences_need_an_active_staff_side_sender(): void
    {
        $family = Family::factory()->create();
        $audience = $this->audience(['type' => 'STAFF_FAMILIES', 'family_ids' => [$family->id]]);
        $inactive = $this->staff();
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$inactive, $this->familyUser(), User::factory()->create(), $this->coordinator()] as $sender) {
            $this->assertRefused(NotificationAudienceException::NOT_AUTHORIZED, fn () => $this->resolver->validateRequest($sender, $audience));
        }
    }

    // --------------------------------------------------------------- Coordinator

    public function test_a_coordinator_reaches_the_active_families_of_the_current_scope_only(): void
    {
        $clan = $this->clan('NTF_CLAN');
        [$inScope, $outOfScope] = [$this->branch($clan), $this->branch($clan)];
        $a = $this->familyIn($clan, $inScope);
        $b = $this->familyIn($clan, $inScope);
        $this->familyIn($clan, $inScope, attributes: ['status' => FamilyStatus::INACTIVE->value]);
        $this->familyIn($clan, $outOfScope);
        $this->familyIn($this->clan('OTHER_CLAN'));
        $coordinator = $this->coordinator();
        $this->assign($coordinator, $inScope);

        $audience = $this->audience(['type' => 'COORDINATOR_SCOPE']);
        $this->resolver->validateRequest($coordinator, $audience);
        $this->assertSame($this->idsOf($a, $b), $this->ids($this->resolver->families($coordinator, $audience)));
    }

    public function test_a_coordinator_selection_is_always_intersected_with_the_current_scope(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $group = $this->group($clan);
        [$grouped, $other] = [$this->branch($clan, $group), $this->branch($clan)];
        $a = $this->familyIn($clan, $grouped);
        $b = $this->familyIn($clan, $grouped);
        $outside = $this->familyIn($clan, $other);
        $this->familyIn($clan, $grouped, attributes: ['status' => FamilyStatus::INACTIVE->value]);
        $coordinator = $this->coordinator();
        $this->assign($coordinator, $group);

        // Group, Branch and Family criteria are a union inside the scope.
        $mixed = $this->audience(['type' => 'COORDINATOR_SELECTION', 'branch_group_ids' => [$group->id], 'branch_ids' => [$grouped->id], 'family_ids' => [$a->id]]);
        $this->resolver->validateRequest($coordinator, $mixed);
        $this->assertSame($this->idsOf($a, $b), $this->ids($this->resolver->families($coordinator, $mixed)));
        $single = $this->audience(['type' => 'COORDINATOR_SELECTION', 'family_ids' => [$b->id]]);
        $this->resolver->validateRequest($coordinator, $single);
        $this->assertSame($this->idsOf($b), $this->ids($this->resolver->families($coordinator, $single)));

        // An explicitly selected family outside the scope: refused, never added.
        $this->assertRefused(NotificationAudienceException::FAMILY_NOT_ELIGIBLE, fn () => $this->resolver->validateRequest(
            $coordinator, $this->audience(['type' => 'COORDINATOR_SELECTION', 'family_ids' => [$a->id, $outside->id]])));
    }

    public function test_every_selected_structure_must_lie_wholly_inside_the_scope(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $otherClan = $this->clan('OTHER_CLAN');
        $group = $this->group($clan);
        $otherGroup = $this->group($clan);
        [$grouped, $ungrouped, $foreign] = [$this->branch($clan, $group), $this->branch($clan), $this->branch($otherClan)];
        $inactive = $this->branch($clan, $group, active: false);
        $this->familyIn($clan, $grouped);
        $this->familyIn($clan, $ungrouped);
        $coordinator = $this->coordinator();
        $this->assign($coordinator, $group);
        $this->assign($coordinator, $ungrouped);
        $selection = fn (array $criteria) => $this->audience(['type' => 'COORDINATOR_SELECTION', ...$criteria]);

        // Covered: the assigned Group, a Branch of it, the assigned Branch.
        foreach ([['branch_group_ids' => [$group->id]], ['branch_ids' => [$grouped->id, $ungrouped->id]]] as $criteria) {
            $this->resolver->validateRequest($coordinator, $selection($criteria));
        }

        // One structure outside the scope refuses the WHOLE request — mixed
        // with authorized ones, never silently dropped.
        $refused = [
            'a Clan held only partly' => ['clan_ids' => [$clan->id], 'branch_group_ids' => [$group->id]],
            'another Group of the Clan' => ['branch_group_ids' => [$group->id, $otherGroup->id]],
            'a Branch of another Clan' => ['branch_ids' => [$grouped->id, $foreign->id]],
            'an inactive Branch of the assigned Group' => ['branch_ids' => [$grouped->id, $inactive->id]],
            'an unknown Branch' => ['branch_ids' => [$grouped->id, 999999]],
            'an unknown Clan' => ['branch_ids' => [$grouped->id], 'clan_ids' => [999999]],
        ];
        foreach ($refused as $why => $criteria) {
            $this->assertRefused(NotificationAudienceException::NOT_AUTHORIZED, fn () => $this->resolver->validateRequest($coordinator, $selection($criteria)));
            // Re-checked at publication too.
            $this->assertRefused(NotificationAudienceException::NOT_AUTHORIZED, fn () => $this->resolver->families($coordinator, $selection($criteria)));
        }

        // A CLAN assignment covers the Clan and everything in it.
        $clanCoordinator = $this->coordinator('444444440');
        $this->assign($clanCoordinator, $clan);
        $this->resolver->validateRequest($clanCoordinator, $selection([
            'clan_ids' => [$clan->id], 'branch_group_ids' => [$otherGroup->id], 'branch_ids' => [$ungrouped->id],
        ]));
        $this->assertRefused(NotificationAudienceException::NOT_AUTHORIZED, fn () => $this->resolver->validateRequest(
            $clanCoordinator, $selection(['clan_ids' => [$clan->id, $otherClan->id]])));
    }

    public function test_a_structure_inside_the_scope_with_no_active_family_is_an_empty_audience(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $branch = $this->branch($clan);
        $this->familyIn($clan, $branch, attributes: ['status' => FamilyStatus::INACTIVE->value]);
        $coordinator = $this->coordinator();
        $this->assign($coordinator, $branch);

        $this->assertRefused(NotificationAudienceException::EMPTY, fn () => $this->resolver->validateRequest(
            $coordinator, $this->audience(['type' => 'COORDINATOR_SELECTION', 'branch_ids' => [$branch->id]])));
    }

    public function test_a_revoked_assignment_takes_effect_on_the_next_resolution(): void
    {
        $clan = $this->clan('NTF_CLAN');
        [$first, $second] = [$this->branch($clan), $this->branch($clan)];
        $a = $this->familyIn($clan, $first);
        $b = $this->familyIn($clan, $second);
        $coordinator = $this->coordinator();
        $keep = $this->assign($coordinator, $first);
        $revoke = $this->assign($coordinator, $second);
        $audience = $this->audience(['type' => 'COORDINATOR_SCOPE']);
        $this->assertSame($this->idsOf($a, $b), $this->ids($this->resolver->families($coordinator, $audience)));

        $revoke->forceFill(['revoked_at' => now(), 'revoked_by' => $this->staff()->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->assertSame($this->idsOf($a), $this->ids($this->resolver->families($coordinator, $audience)));

        $keep->forceFill(['revoked_at' => now(), 'revoked_by' => $this->staff()->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->assertRefused(NotificationAudienceException::NOT_AUTHORIZED, fn () => $this->resolver->families($coordinator, $audience));
    }

    public function test_coordinator_audiences_need_an_effective_coordinator_and_sides_never_mix(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $family = $this->familyIn($clan);
        $withoutScope = $this->coordinator();
        $revokedOnly = $this->coordinator('222222220');
        $this->assign($revokedOnly, $clan, revoked: true);
        $audience = $this->audience(['type' => 'COORDINATOR_SCOPE']);

        foreach ([$withoutScope, $revokedOnly, $this->staff(sendAll: true)] as $sender) {
            $this->assertRefused(NotificationAudienceException::NOT_AUTHORIZED, fn () => $this->resolver->validateRequest($sender, $audience));
        }

        $scoped = $this->coordinator('333333330');
        $this->assign($scoped, $clan);
        $this->assertRefused(NotificationAudienceException::NOT_AUTHORIZED, fn () => $this->resolver->validateRequest(
            $scoped, $this->audience(['type' => 'STAFF_FAMILIES', 'family_ids' => [$family->id]])));
        $this->assertRefused(NotificationAudienceException::INVALID, fn () => $this->resolver->families(
            $scoped, NotificationAudience::systemOrigin()));
    }

    // -------------------------------------------------------------------- SYSTEM

    /** A workflow event of $type with its resulting status (SYSTEM actor). */
    private function workflowEvent(ChangeRequest $request, string $type = 'RETURNED', string $workflowable = 'change_request'): WorkflowEvent
    {
        $to = match ($type) {
            'RETURNED' => 'RETURNED_FOR_CLARIFICATION',
            'REVIEW_STARTED' => 'UNDER_REVIEW',
            default => $type,
        };

        return WorkflowEvent::create([
            'workflowable_type' => $workflowable, 'workflowable_id' => $request->id, 'from_status' => null,
            'to_status' => $to, 'event_type' => $type, 'actor_user_id' => null, 'actor_side' => 'SYSTEM',
        ]);
    }

    public function test_system_resolves_the_originating_requests_own_family_whatever_its_status(): void
    {
        $request = ChangeRequest::factory()->create();
        foreach (['RETURNED', 'REJECTED', 'APPLIED'] as $type) {
            $this->assertSame([(int) $request->family_id], $this->ids($this->resolver->systemFamilies($this->workflowEvent($request, $type)->id)), $type);
        }
        $event = $this->workflowEvent($request, 'APPLIED');

        // R8: a family that is no longer ACTIVE still receives it.
        Family::query()->whereKey($request->family_id)->update(['status' => FamilyStatus::ARCHIVED->value]);
        $this->assertSame([(int) $request->family_id], $this->ids($this->resolver->systemFamilies($event->id)));

        // A deleted family receives nothing.
        Family::query()->findOrFail($request->family_id)->delete();
        $this->assertFalse($this->resolver->systemFamilies($event->id)->exists());
    }

    public function test_only_returned_rejected_and_applied_change_request_events_are_a_system_origin(): void
    {
        $request = ChangeRequest::factory()->create();
        foreach (['SUBMITTED', 'REVIEW_STARTED', 'RESUBMITTED', 'APPROVED', 'CANCELLED'] as $type) {
            $event = $this->workflowEvent($request, $type);
            $this->assertRefused(NotificationAudienceException::INVALID, fn () => $this->resolver->systemFamilies($event->id));
        }
        $this->assertRefused(NotificationAudienceException::INVALID, fn () => $this->resolver->systemFamilies(999999));

        // Not a change request (PostgreSQL's chk_workflow_event_workflowable refuses the row itself).
        if (DB::getDriverName() !== 'pgsql') {
            $foreign = $this->workflowEvent($request, 'RETURNED', workflowable: 'family');
            $this->assertRefused(NotificationAudienceException::INVALID, fn () => $this->resolver->systemFamilies($foreign->id));
        }
    }

    public function test_a_request_without_a_family_resolves_to_nobody(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $coordinator = $this->coordinator();
        $request = ChangeRequest::factory()->create([
            'family_id' => null, 'type' => ChangeRequestType::REGISTER_FAMILY, 'submission_channel' => 'COORDINATOR',
            'proposed_clan_id' => $clan->id, 'submitted_by' => $coordinator->id,
            'submitted_by_person_id' => Person::factory()->create()->id,
        ]);
        $this->familyIn($clan);

        $this->assertFalse($this->resolver->systemFamilies($this->workflowEvent($request)->id)->exists());
    }

    public function test_a_stored_message_is_re_resolved_and_re_authorized(): void
    {
        $family = Family::factory()->create();
        $sender = $this->staff(sendAll: true);
        $message = NotificationMessage::create([
            'kind' => NotificationKind::ANNOUNCEMENT, 'sender_side' => NotificationSenderSide::STAFF, 'title' => 'إعلان تجريبي',
            'body' => 'نص تجريبي.', 'sender_user_id' => $sender->id, 'audience' => ['type' => 'STAFF_ALL_ACTIVE'],
        ]);
        $this->assertContains((int) $family->id, $this->ids($this->resolver->forMessage($message)));

        $sender->revokePermissionTo(NotificationAudienceResolver::SEND_ALL_PERMISSION);
        $this->assertRefused(NotificationAudienceException::NOT_AUTHORIZED, fn () => $this->resolver->forMessage($message->fresh()));

        $mismatch = $message->replicate(['uuid', 'notice_code']);
        $mismatch->audience = ['type' => NotificationAudienceType::COORDINATOR_SCOPE->value];
        $this->assertRefused(NotificationAudienceException::INVALID, fn () => $this->resolver->forMessage($mismatch));
    }

    public function test_a_stored_system_message_resolves_its_origin(): void
    {
        $request = ChangeRequest::factory()->create();
        $message = new NotificationMessage;
        $message->forceFill([
            'kind' => NotificationKind::SYSTEM, 'sender_side' => NotificationSenderSide::SYSTEM, 'status' => NotificationStatus::QUEUED,
            'origin_workflow_event_id' => $this->workflowEvent($request)->id,
        ]);

        $this->assertSame([(int) $request->family_id], $this->ids($this->resolver->forMessage($message)));
    }

    public function test_resolution_writes_nothing(): void
    {
        $sender = $this->staff(sendAll: true);
        Family::factory()->create();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $audience = $this->audience(['type' => 'STAFF_ALL_ACTIVE']);
        $this->resolver->validateRequest($sender, $audience);
        $this->resolver->families($sender, $audience)->get();

        $this->assertSame([], $writes);
    }
}
