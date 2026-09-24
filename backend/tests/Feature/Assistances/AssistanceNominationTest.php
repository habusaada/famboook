<?php

namespace Tests\Feature\Assistances;

use App\Enums\BeneficiaryStatus;
use App\Enums\NeedStatus;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\FamilyNeed;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

/**
 * Nominations (docs/03-BUSINESS-RULES.md §47c): manual, from OPEN Needs
 * and from a targeting selection; duplicates; history-preserving removal;
 * Activity Log; permissions and privacy.
 */
class AssistanceNominationTest extends TestCase
{
    use BuildsAssistanceFixtures, RefreshDatabase;

    private Assistance $assistance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssistanceFixtures();
        $this->assistance = $this->openAssistance();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function manual(array $payload, ?User $as = null, ?Assistance $assistance = null)
    {
        $id = ($assistance ?? $this->assistance)->uuid;

        return $this->actingAs($as ?? $this->user)->postJson("/api/v1/assistances/{$id}/nominees/manual", $payload);
    }

    private function fromNeeds(array $needIds, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->postJson("/api/v1/assistances/{$this->assistance->uuid}/nominees/from-needs", ['need_ids' => $needIds]);
    }

    private function fromTargeting(array $familyCodes, array $criteria, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->postJson("/api/v1/assistances/{$this->assistance->uuid}/nominees/from-targeting", [
                'family_codes' => $familyCodes,
                'criteria' => $criteria,
            ]);
    }

    private function remove(AssistanceBeneficiary $nominee, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->postJson("/api/v1/assistances/{$this->assistance->uuid}/nominees/{$nominee->uuid}/remove");
    }

    private function nominees(array $query = [], ?User $as = null)
    {
        $qs = $query ? '?'.http_build_query($query) : '';

        return $this->actingAs($as ?? $this->user)->getJson("/api/v1/assistances/{$this->assistance->uuid}/nominees{$qs}");
    }

    private function events(int $familyId): array
    {
        return FamilyActivity::where('family_id', $familyId)->orderBy('id')->pluck('event_type')->map->value->all();
    }

    // ---------------------------------------------------------------- manual

    public function test_manual_family_nomination(): void
    {
        $family = $this->family(3, ['name' => 'أسرة يدوية']);

        $this->manual(['family_code' => $family->family_code])->assertCreated()
            ->assertJsonPath('data.family.family_code', $family->family_code)
            ->assertJsonPath('data.family.household_head_name', 'أسرة يدوية 0')
            ->assertJsonPath('data.person', null)
            ->assertJsonPath('data.nomination_source', 'MANUAL')
            ->assertJsonPath('data.status', 'NOMINATED')
            ->assertJsonPath('data.source_need', null)
            ->assertJsonPath('data.nominated_by.name', 'مدير تجريبي');

        $nominee = AssistanceBeneficiary::first();
        $this->assertNull($nominee->person_id);
        $this->assertSame($this->user->id, $nominee->nominated_by);
        $this->assertSame('2026-09-24 10:00:00', $nominee->nominated_at->toDateTimeString());
    }

    public function test_manual_person_nomination(): void
    {
        $family = $this->family();
        $person = $this->addMember($family, ['full_name' => 'فرد مرشح']);

        $this->manual(['family_code' => $family->family_code, 'person_code' => $person->person_code])->assertCreated()
            ->assertJsonPath('data.person.person_code', $person->person_code)
            ->assertJsonPath('data.person.full_name', 'فرد مرشح');
    }

    public function test_unrelated_or_former_person_is_rejected(): void
    {
        $family = $this->family();
        $stranger = $this->addMember($this->family());
        $former = $this->addMember($family, [], active: false);

        foreach ([$stranger->person_code, $former->person_code, 'PER-NONE'] as $code) {
            $this->manual(['family_code' => $family->family_code, 'person_code' => $code])
                ->assertUnprocessable()->assertJsonValidationErrors('person_code');
        }
        $this->manual(['family_code' => 'FAM-NONE'])->assertUnprocessable()->assertJsonValidationErrors('family_code');
        $this->assertSame(0, AssistanceBeneficiary::count());
    }

    public function test_nomination_requires_open_assistance(): void
    {
        $draftId = $this->createAssistance()->json('data.id');
        $family = $this->family();

        $this->manual(['family_code' => $family->family_code], null, Assistance::where('uuid', $draftId)->first())->assertStatus(409);
        $this->assertSame(0, AssistanceBeneficiary::count());
    }

    public function test_candidate_search_returns_safe_fields_only(): void
    {
        $family = $this->family(2, ['name' => 'عائلة البحث', 'national_id' => '9990004441', 'mobile' => '0799994441']);
        $this->manual(['family_code' => $family->family_code])->assertCreated();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/assistances/{$this->assistance->uuid}/nominee-candidates?search=".urlencode('البحث'))
            ->assertOk()
            ->assertJsonPath('data.0.family_code', $family->family_code)
            ->assertJsonPath('data.0.family_nominated', true)
            ->assertJsonCount(2, 'data.0.members');

        $this->actingAs($this->user)
            ->getJson("/api/v1/assistances/{$this->assistance->uuid}/nominee-candidates?search=".$family->family_code)
            ->assertJsonCount(1, 'data');

        $raw = $response->getContent();
        foreach (['9990004441', '0799994441', 'national_id', 'mobile', 'birth_date'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        // National IDs are not searchable in V1-A.
        $this->actingAs($this->user)
            ->getJson("/api/v1/assistances/{$this->assistance->uuid}/nominee-candidates?search=9990004441")
            ->assertJsonCount(0, 'data');
    }

    // ---------------------------------------------------------------- needs

    public function test_family_and_person_nominations_from_open_needs(): void
    {
        $family = $this->family();
        $person = $this->addMember($family);
        $familyNeed = $this->need($family, ['category' => 'SHELTER', 'title' => 'مواد إيواء', 'description' => 'وصف سري']);
        $personNeed = $this->need($family, ['category' => 'ASSISTIVE_DEVICE', 'title' => 'كرسي متحرك', 'person_id' => $person->id, 'priority' => 'URGENT']);

        $this->fromNeeds([$familyNeed->uuid, $personNeed->uuid])->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped_duplicates', 0);

        $familyNominee = AssistanceBeneficiary::whereNull('person_id')->first();
        $personNominee = AssistanceBeneficiary::whereNotNull('person_id')->first();
        $this->assertSame($familyNeed->id, $familyNominee->source_need_id);
        $this->assertSame($person->id, $personNominee->person_id);
        $this->assertSame($personNeed->id, $personNominee->source_need_id);
        $this->assertSame('NEED', $personNominee->nomination_source->value);

        // The Needs are untouched.
        foreach ([$familyNeed, $personNeed] as $need) {
            $this->assertSame(NeedStatus::OPEN, $need->fresh()->status);
            $this->assertNull($need->fresh()->resolved_at);
        }

        $list = $this->nominees()->assertOk();
        $row = collect($list->json('data'))->firstWhere('person.person_code', $person->person_code);
        $this->assertSame($personNeed->uuid, $row['source_need']['id']);
        $this->assertSame('كرسي متحرك', $row['source_need']['title']);
        $this->assertSame('URGENT', $row['source_need']['priority']);
        $this->assertStringNotContainsString('وصف سري', $list->getContent());
    }

    public function test_resolved_or_unknown_needs_are_rejected_all_or_nothing(): void
    {
        $family = $this->family();
        $open = $this->need($family);
        $fulfilled = $this->need($this->family(), ['status' => 'FULFILLED']);
        $closed = $this->need($this->family(), ['status' => 'CLOSED']);

        $this->fromNeeds([$open->uuid, $fulfilled->uuid])->assertUnprocessable()->assertJsonValidationErrors('need_ids.1');
        $this->fromNeeds([$closed->uuid])->assertUnprocessable();
        $this->fromNeeds(['00000000-0000-0000-0000-000000000000'])->assertUnprocessable();

        $this->assertSame(0, AssistanceBeneficiary::count());
        $this->assertSame([], $this->events($family->id));
    }

    // ---------------------------------------------------------------- targeting

    public function test_targeting_bulk_nominates_only_selected_matching_families(): void
    {
        $a = $this->family(5, ['displacement' => 'DISPLACED']);
        $b = $this->family(6, ['displacement' => 'DISPLACED']);
        $c = $this->family(7, ['displacement' => 'DISPLACED']);
        $criteria = ['min_family_members' => 5, 'displacement_status' => 'DISPLACED'];

        $this->fromTargeting([$a->family_code, $c->family_code], $criteria)->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped_duplicates', 0);

        $this->assertEqualsCanonicalizing([$a->id, $c->id], AssistanceBeneficiary::pluck('family_id')->all());
        $this->assertFalse(AssistanceBeneficiary::where('family_id', $b->id)->exists());
        foreach (AssistanceBeneficiary::all() as $nominee) {
            $this->assertSame('TARGETING', $nominee->nomination_source->value);
            $this->assertNull($nominee->person_id);
            $this->assertSame(['displacement_status' => 'DISPLACED', 'min_family_members' => 5], $nominee->targeting_criteria);
        }
        // The criteria actually used become the Assistance snapshot.
        $this->assertSame(['displacement_status' => 'DISPLACED', 'min_family_members' => 5], $this->assistance->fresh()->targeting_criteria);
    }

    public function test_targeting_rejects_non_matching_selection_entirely(): void
    {
        $match = $this->family(5);
        $small = $this->family(2);

        $this->fromTargeting([$match->family_code, $small->family_code], ['min_family_members' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('family_codes.1');
        $this->fromTargeting(['FAM-NONE'], [])->assertUnprocessable();

        $this->assertSame(0, AssistanceBeneficiary::count());
    }

    public function test_targeting_skips_duplicates_and_reports_counts(): void
    {
        $a = $this->family(5);
        $b = $this->family(5);
        $this->manual(['family_code' => $a->family_code])->assertCreated();

        $this->fromTargeting([$a->family_code, $b->family_code], ['min_family_members' => 5])->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.skipped_duplicates', 1);

        $this->assertSame(1, AssistanceBeneficiary::where('family_id', $a->id)->count());
        $this->assertSame('MANUAL', AssistanceBeneficiary::where('family_id', $a->id)->first()->nomination_source->value);
    }

    // ---------------------------------------------------------------- duplicates

    public function test_duplicate_family_and_person_are_prevented(): void
    {
        $family = $this->family();
        $person = $this->addMember($family);

        $this->manual(['family_code' => $family->family_code])->assertCreated();
        $this->manual(['family_code' => $family->family_code])->assertUnprocessable()->assertJsonValidationErrors('family_code');

        $this->manual(['family_code' => $family->family_code, 'person_code' => $person->person_code])->assertCreated();
        $this->manual(['family_code' => $family->family_code, 'person_code' => $person->person_code])
            ->assertUnprocessable()->assertJsonValidationErrors('person_code');

        // Need nomination of an already-nominated person is skipped.
        $need = $this->need($family, ['person_id' => $person->id]);
        $this->fromNeeds([$need->uuid])->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.skipped_duplicates', 1);

        $this->assertSame(2, AssistanceBeneficiary::count());
    }

    public function test_database_constraint_backs_duplicate_rule(): void
    {
        $family = $this->family();
        $this->manual(['family_code' => $family->family_code])->assertCreated();

        $this->expectException(QueryException::class);
        AssistanceBeneficiary::create([
            'assistance_id' => $this->assistance->id,
            'family_id' => $family->id,
            'nomination_source' => 'MANUAL',
            'status' => 'NOMINATED',
            'nominated_at' => now(),
        ]);
    }

    public function test_family_and_person_of_same_family_can_both_be_nominated(): void
    {
        $family = $this->family();
        $person = $this->addMember($family);

        $this->manual(['family_code' => $family->family_code])->assertCreated();
        $this->manual(['family_code' => $family->family_code, 'person_code' => $person->person_code])->assertCreated();

        $this->nominees()->assertJsonPath('summary.family', 1)->assertJsonPath('summary.person', 1);
    }

    public function test_same_family_may_be_nominated_in_different_assistances(): void
    {
        $family = $this->family();
        $other = $this->openAssistance(['title' => 'مساعدة أخرى']);

        $this->manual(['family_code' => $family->family_code])->assertCreated();
        $this->manual(['family_code' => $family->family_code], null, $other)->assertCreated();
    }

    public function test_nomination_survives_later_family_data_changes(): void
    {
        $family = $this->family(5);
        $this->fromTargeting([$family->family_code], ['min_family_members' => 5])->assertOk();

        // The family no longer matches (a member left); the nominee stays.
        FamilyMembership::where('family_id', $family->id)->where('is_household_head', false)->limit(3)->get()
            ->each(fn ($m) => $m->update(['is_active' => false, 'ended_at' => '2026-09-24']));
        $this->assertSame([], $this->matching($this->assistance, ['min_family_members' => 5]));

        $this->nominees()->assertJsonCount(1, 'data')->assertJsonPath('data.0.family.family_code', $family->family_code);
    }

    // ---------------------------------------------------------------- removal

    public function test_removal_is_history_preserving_and_allows_renomination(): void
    {
        $family = $this->family();
        $this->manual(['family_code' => $family->family_code])->assertCreated();
        $nominee = AssistanceBeneficiary::first();

        Carbon::setTestNow('2026-09-24 12:00:00');
        $this->remove($nominee)->assertOk()
            ->assertJsonPath('data.status', 'REMOVED')
            ->assertJsonPath('data.removed_by.name', 'مدير تجريبي');

        $nominee->refresh();
        $this->assertSame(BeneficiaryStatus::REMOVED, $nominee->status);
        $this->assertSame('2026-09-24 12:00:00', $nominee->removed_at->toDateTimeString());

        // Removed rows are hidden by default but kept as history.
        $this->nominees()->assertJsonCount(0, 'data')->assertJsonPath('summary.removed', 1)->assertJsonPath('summary.total', 0);
        $this->nominees(['include_removed' => 1])->assertJsonCount(1, 'data');

        // Removing twice fails; re-nominating creates a new row.
        $this->remove($nominee)->assertStatus(409);
        $this->manual(['family_code' => $family->family_code])->assertCreated();
        $this->assertSame(2, AssistanceBeneficiary::count());

        $this->expectException(LogicException::class);
        $nominee->delete();
    }

    public function test_nominee_of_another_assistance_cannot_be_removed_here(): void
    {
        $other = $this->openAssistance(['title' => 'أخرى']);
        $family = $this->family();
        $this->manual(['family_code' => $family->family_code], null, $other)->assertCreated();

        $this->remove(AssistanceBeneficiary::first())->assertNotFound();
        $this->assertSame(BeneficiaryStatus::NOMINATED, AssistanceBeneficiary::first()->status);
    }

    public function test_derived_counters(): void
    {
        $a = $this->family(5);
        $b = $this->family();
        $person = $this->addMember($b);
        $c = $this->family();
        $this->fromTargeting([$a->family_code], ['min_family_members' => 5])->assertOk();
        $this->manual(['family_code' => $b->family_code, 'person_code' => $person->person_code])->assertCreated();
        $this->fromNeeds([$this->need($c)->uuid])->assertOk();
        $this->manual(['family_code' => $b->family_code])->assertCreated();
        $this->remove(AssistanceBeneficiary::latest('id')->first())->assertOk();

        $this->nominees()->assertOk()
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.family', 2)
            ->assertJsonPath('summary.person', 1)
            ->assertJsonPath('summary.targeting', 1)
            ->assertJsonPath('summary.need', 1)
            ->assertJsonPath('summary.manual', 1)
            ->assertJsonPath('summary.removed', 1);

        $this->actingAs($this->user)->getJson("/api/v1/assistances/{$this->assistance->uuid}")->assertJsonPath('data.nominee_count', 3);
    }

    // ---------------------------------------------------------------- activity

    public function test_nomination_and_removal_are_recorded_on_the_family_timeline(): void
    {
        $family = $this->family();
        $person = $this->addMember($family, ['full_name' => 'فرد النشاط']);
        $this->manual(['family_code' => $family->family_code, 'person_code' => $person->person_code])->assertCreated();
        $this->remove(AssistanceBeneficiary::first())->assertOk();

        $this->assertSame(['ASSISTANCE_NOMINEE_ADDED', 'ASSISTANCE_NOMINEE_REMOVED'], $this->events($family->id));

        $timeline = $this->actingAs($this->user)->getJson("/api/v1/families/{$family->family_code}/activities")->assertOk();
        $timeline->assertJsonPath('data.0.subject.type', 'assistance_nominee')
            ->assertJsonPath('data.0.subject.title', 'حزمة إيواء طارئة')
            ->assertJsonPath('data.0.subject.person.full_name', 'فرد النشاط');
    }

    public function test_program_events_and_preview_write_no_family_activity(): void
    {
        $this->family(5);
        $id = $this->createAssistance()->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$id}", ['title' => 'تعديل'])->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$id}/open")->assertOk();
        $this->preview($this->assistance, ['min_family_members' => 5])->assertOk();

        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_activity_holds_no_targeting_or_sensitive_metadata(): void
    {
        $family = $this->family(5, ['displacement' => 'DISPLACED']);
        $this->healthRecord($this->addMember($family), 'PREGNANCY', ['details' => 'ملاحظة حمل سرية']);
        $need = $this->need($family, ['description' => 'وصف احتياج سري']);
        $this->fromTargeting([$family->family_code], ['has_pregnant_member' => true, 'displacement_status' => 'DISPLACED'])->assertOk();
        $this->fromNeeds([$need->uuid])->assertOk()->assertJsonPath('data.skipped_duplicates', 1);

        foreach (FamilyActivity::all() as $activity) {
            $this->assertNull($activity->metadata);
        }

        $raw = $this->actingAs($this->user)->getJson("/api/v1/families/{$family->family_code}/activities")->getContent();
        foreach (['PREGNANCY', 'pregnant', 'DISPLACED', 'ملاحظة حمل سرية', 'وصف احتياج سري', 'criteria', 'TARGETING'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, $secret);
        }
    }

    public function test_nomination_events_are_hidden_without_assistance_view(): void
    {
        $family = $this->family();
        $this->manual(['family_code' => $family->family_code])->assertCreated();
        $user = User::factory()->create();
        $user->givePermissionTo('activity-log.view');

        $this->actingAs($user)->getJson("/api/v1/families/{$family->family_code}/activities")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_failed_nominations_leave_no_activity(): void
    {
        $family = $this->family(2);
        $this->fromTargeting([$family->family_code], ['min_family_members' => 5])->assertUnprocessable();
        $this->manual(['family_code' => $family->family_code, 'person_code' => 'PER-NONE'])->assertUnprocessable();
        $this->manual(['family_code' => $family->family_code], $this->user('REVIEWER'))->assertForbidden();

        $this->assertSame([], $this->events($family->id));
    }

    // ---------------------------------------------------------------- permissions

    public function test_nomination_permissions(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $user = $this->user($role);
            $family = $this->family();
            $this->nominees([], $user)->assertOk();
            $this->manual(['family_code' => $family->family_code], $user)->assertCreated();
            $this->remove(AssistanceBeneficiary::where('family_id', $family->id)->first(), $user)->assertOk();
        }

        $reviewer = $this->user('REVIEWER');
        $family = $this->family();
        $this->manual(['family_code' => $family->family_code])->assertCreated();
        $this->nominees([], $reviewer)->assertOk();
        $this->manual(['family_code' => $this->family()->family_code], $reviewer)->assertForbidden();
        $this->fromNeeds([$this->need($family)->uuid], $reviewer)->assertForbidden();
        $this->fromTargeting([$family->family_code], [], $reviewer)->assertForbidden();
        $this->remove(AssistanceBeneficiary::where('family_id', $family->id)->first(), $reviewer)->assertForbidden();
        $this->actingAs($reviewer)->getJson("/api/v1/assistances/{$this->assistance->uuid}/nominee-candidates?search=FAM")->assertForbidden();

        foreach (['REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $user = $this->user($role);
            $this->nominees([], $user)->assertForbidden();
            $this->manual(['family_code' => $family->family_code], $user)->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- privacy

    public function test_nominee_responses_contain_no_sensitive_data(): void
    {
        $family = $this->family(2, ['national_id' => '9990006661', 'mobile' => '0799996661']);
        $person = $this->addMember($family);
        $this->healthRecord($person, 'CHRONIC_DISEASE', ['condition_name' => 'مرض سري', 'details' => 'تفاصيل صحية سرية']);
        $this->assessment($family, 'HEALTH', 'CRITICAL', '2026-09-01', notes: 'ملاحظة تقييم سرية');
        $need = $this->need($family, ['person_id' => $person->id, 'description' => 'وصف احتياج سري']);
        $this->fromNeeds([$need->uuid])->assertOk();
        $this->manual(['family_code' => $family->family_code])->assertCreated();

        $raw = $this->nominees()->assertOk()->getContent();
        foreach (['9990006661', '0799996661', 'national_id', 'mobile', 'مرض سري', 'تفاصيل صحية سرية', 'ملاحظة تقييم سرية',
            'وصف احتياج سري', 'targeting_criteria', 'family_id', 'person_id', 'source_need_id', 'email', 'description'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, $secret);
        }
        foreach (AssistanceBeneficiary::all() as $nominee) {
            $this->assertStringNotContainsString('"id":'.$nominee->id.',', $raw);
        }
    }

    public function test_assistance_data_is_absent_from_family_person_and_need_responses(): void
    {
        $family = $this->family();
        $person = $this->addMember($family);
        $need = $this->need($family);
        $this->fromNeeds([$need->uuid])->assertOk();
        $admin = $this->user('SUPER_ADMIN');

        foreach ([
            "/api/v1/families/{$family->family_code}",
            "/api/v1/people/{$person->person_code}",
            "/api/v1/needs/{$need->uuid}",
        ] as $url) {
            $raw = $this->actingAs($admin)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($this->assistance->uuid, $raw, $url);
            $this->assertStringNotContainsString('حزمة إيواء طارئة', $raw, $url);
        }
        $this->assertSame(NeedStatus::OPEN, FamilyNeed::first()->status);
    }
}
