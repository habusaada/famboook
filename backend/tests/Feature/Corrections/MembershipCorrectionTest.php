<?php

namespace Tests\Feature\Corrections;

use App\Enums\FamilyActivityType;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\RelationshipType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Registry\BuildsRegistryFixtures;
use Tests\TestCase;

/**
 * Pilot Readiness Slice C: relationship correction (family-membership.update:
 * SUPER_ADMIN, ADMINISTRATOR, DATA_ENTRY) and ending an incorrect membership
 * (family-membership.end: SUPER_ADMIN, ADMINISTRATOR only — not reversible
 * in V1), AUTH-ADR-059. Both correct
 * the membership in place: no Person or membership is ever deleted or
 * recreated, and the household head can never be moved or removed.
 */
class MembershipCorrectionTest extends TestCase
{
    use BuildsRegistryFixtures;
    use RefreshDatabase;

    private const REASON = 'أُدخل في أسرة خاطئة أثناء الإدخال';

    /** @var array{family_code: string, person_code: string} */
    private array $family;

    private string $memberCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();

        $this->family = $this->family('رب أسرة التصحيح');
        $this->memberCode = $this->addMember($this->family['family_code'], ['full_name' => 'ابنة للتصحيح'])
            ->assertCreated()->json('data.person_code');
    }

    private function typeId(string $code): int
    {
        return RelationshipType::where('code', $code)->value('id');
    }

    private function correct(string $personCode, string $code, ?string $role = 'DATA_ENTRY', ?string $familyCode = null): TestResponse
    {
        $familyCode ??= $this->family['family_code'];
        $request = $role === null ? $this->asGuest() : $this->actingAs($this->user($role));

        return $request->patchJson(
            "/api/v1/families/{$familyCode}/members/{$personCode}/relationship",
            ['relationship_type_id' => $this->typeId($code)],
        );
    }

    /** @param array<string, mixed> $body */
    private function end(string $personCode, array $body = ['reason' => self::REASON], ?string $role = 'ADMINISTRATOR'): TestResponse
    {
        $request = $role === null ? $this->asGuest() : $this->actingAs($this->user($role));

        return $request->postJson("/api/v1/families/{$this->family['family_code']}/members/{$personCode}/end", $body);
    }

    private function membershipOf(string $personCode): FamilyMembership
    {
        return FamilyMembership::where('person_id', Person::where('person_code', $personCode)->value('id'))->sole();
    }

    private function activities(FamilyActivityType $type): \Illuminate\Support\Collection
    {
        return FamilyActivity::where('family_id', Family::where('family_code', $this->family['family_code'])->value('id'))
            ->where('event_type', $type)->get();
    }

    // ---------------------------------------------------------------- relationship

    public function test_authorized_roles_can_correct_an_ordinary_relationship(): void
    {
        foreach (['DATA_ENTRY' => 'MOTHER', 'ADMINISTRATOR' => 'OTHER', 'SUPER_ADMIN' => 'SPOUSE'] as $role => $code) {
            $this->correct($this->memberCode, $code, $role)
                ->assertOk()
                ->assertJsonPath('data.person_code', $this->memberCode)
                ->assertJsonPath('data.relationship_type.code', $code)
                ->assertJsonPath('data.is_household_head', false)
                ->assertJsonPath('data.is_active', true);
        }

        $members = collect($this->actingAs($this->staff)->getJson("/api/v1/families/{$this->family['family_code']}")
            ->assertOk()->json('data.members'))->keyBy('person_code');
        $this->assertSame('SPOUSE', $members[$this->memberCode]['relationship_type']['code']);
        $this->assertSame('HEAD', $members[$this->family['person_code']]['relationship_type']['code']);
    }

    public function test_relationship_correction_changes_only_the_relationship(): void
    {
        $person = Person::where('person_code', $this->memberCode)->sole()->toArray();
        $before = $this->membershipOf($this->memberCode);

        $this->correct($this->memberCode, 'MOTHER')->assertOk();

        $after = $this->membershipOf($this->memberCode);
        $this->assertSame($person, Person::where('person_code', $this->memberCode)->sole()->toArray());
        $this->assertSame($before->id, $after->id);
        $this->assertSame($this->typeId('MOTHER'), $after->relationship_type_id);
        $this->assertTrue($after->is_active);
        $this->assertFalse($after->is_household_head);
        $this->assertEquals($before->started_at, $after->started_at);
        $this->assertSame(2, Person::count());
        $this->assertSame(2, FamilyMembership::count());
    }

    public function test_unauthorized_roles_cannot_correct_a_relationship(): void
    {
        foreach (['REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->correct($this->memberCode, 'MOTHER', $role)->assertForbidden();
        }
        $this->correct($this->memberCode, 'MOTHER', null)->assertUnauthorized();

        $this->assertSame($this->typeId('DAUGHTER'), $this->membershipOf($this->memberCode)->relationship_type_id);
    }

    public function test_relationship_correction_requires_a_current_membership_in_that_family(): void
    {
        $other = $this->family('رب أسرة أخرى');
        // A Person of another Family, addressed through this Family.
        $this->correct($other['person_code'], 'SON')->assertStatus(409);

        $this->end($this->memberCode)->assertOk();
        $this->correct($this->memberCode, 'MOTHER')->assertStatus(409);
        $this->assertSame($this->typeId('DAUGHTER'), $this->membershipOf($this->memberCode)->relationship_type_id);

        $this->actingAs($this->staff)->patchJson("/api/v1/families/{$this->family['family_code']}/members/PER-999999/relationship", [
            'relationship_type_id' => $this->typeId('SON'),
        ])->assertNotFound();
    }

    public function test_household_head_relationship_cannot_be_moved_or_removed(): void
    {
        // The head keeps HEAD ...
        $this->correct($this->family['person_code'], 'SON')
            ->assertStatus(422)->assertJsonValidationErrors(['relationship_type_id']);
        // ... and no one else can be given HEAD.
        $this->correct($this->memberCode, 'HEAD')
            ->assertStatus(422)->assertJsonValidationErrors(['relationship_type_id']);

        $head = $this->membershipOf($this->family['person_code']);
        $this->assertTrue($head->is_household_head);
        $this->assertSame($this->typeId('HEAD'), $head->relationship_type_id);
        $this->assertFalse($this->membershipOf($this->memberCode)->is_household_head);
        $this->assertSame(1, FamilyMembership::where('is_household_head', true)->where('is_active', true)->count());

        // Re-saving HEAD on the head changes nothing and is not an activity.
        $this->correct($this->family['person_code'], 'HEAD')->assertOk();
        $this->assertCount(0, $this->activities(FamilyActivityType::MEMBERSHIP_RELATIONSHIP_CORRECTED));
    }

    public function test_relationship_must_be_an_active_canonical_type(): void
    {
        RelationshipType::where('code', 'OTHER')->update(['is_active' => false]);

        $this->correct($this->memberCode, 'OTHER')->assertStatus(422)->assertJsonValidationErrors(['relationship_type_id']);
        $this->actingAs($this->staff)->patchJson("/api/v1/families/{$this->family['family_code']}/members/{$this->memberCode}/relationship", [])
            ->assertStatus(422)->assertJsonValidationErrors(['relationship_type_id']);
        $this->actingAs($this->staff)->patchJson("/api/v1/families/{$this->family['family_code']}/members/{$this->memberCode}/relationship", [
            'relationship_type_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_relationship_correction_records_a_safe_activity_once(): void
    {
        $this->correct($this->memberCode, 'MOTHER')->assertOk();
        // Same value again: no change, no second event.
        $this->correct($this->memberCode, 'MOTHER')->assertOk();

        $events = $this->activities(FamilyActivityType::MEMBERSHIP_RELATIONSHIP_CORRECTED);
        $this->assertCount(1, $events);
        $this->assertNull($events->first()->metadata);
        $this->assertSame((new Person)->getMorphClass(), $events->first()->subject_type);
        $this->assertSame(Person::where('person_code', $this->memberCode)->value('id'), $events->first()->subject_id);

        $this->actingAs($this->staff)->getJson("/api/v1/families/{$this->family['family_code']}/activities")
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'MEMBERSHIP_RELATIONSHIP_CORRECTED')
            ->assertJsonPath('data.0.subject.person.person_code', $this->memberCode);
    }

    // ---------------------------------------------------------------- end membership

    public function test_administrator_and_super_admin_can_end_a_non_head_membership(): void
    {
        $second = $this->addMember($this->family['family_code'], ['full_name' => 'فرد ثان'])->json('data.person_code');

        foreach (['ADMINISTRATOR' => $this->memberCode, 'SUPER_ADMIN' => $second] as $role => $code) {
            $this->end($code, ['reason' => self::REASON], $role)
                ->assertOk()
                ->assertJsonPath('data.person_code', $code);

            $membership = $this->membershipOf($code);
            $this->assertFalse($membership->is_active);
            $this->assertSame(now()->toDateString(), $membership->ended_at->toDateString());
            $this->assertSame(self::REASON, $membership->end_reason);
            $this->assertFalse($membership->is_household_head);
        }
    }

    public function test_ending_keeps_the_person_and_the_membership_history(): void
    {
        $person = Person::where('person_code', $this->memberCode)->sole()->toArray();
        $membershipId = $this->membershipOf($this->memberCode)->id;

        $this->end($this->memberCode)->assertOk();

        // Person unchanged and not (soft) deleted; membership row retained.
        $this->assertSame($person, Person::where('person_code', $this->memberCode)->sole()->toArray());
        $this->assertSame(2, Person::count());
        $this->assertSame(2, FamilyMembership::count());
        $this->assertSame($membershipId, $this->membershipOf($this->memberCode)->id);
        // Not attached to any other Family.
        $this->assertSame(0, FamilyMembership::where('person_id', Person::where('person_code', $this->memberCode)->value('id'))->where('is_active', true)->count());

        $this->actingAs($this->staff)->getJson("/api/v1/people/{$this->memberCode}")
            ->assertOk()
            ->assertJsonPath('data.person_code', $this->memberCode)
            ->assertJsonMissingPath('data.family_membership');
    }

    public function test_ended_member_is_no_longer_current_in_family_and_people_views(): void
    {
        $this->end($this->memberCode)->assertOk();

        $detail = $this->actingAs($this->staff)->getJson("/api/v1/families/{$this->family['family_code']}")->assertOk();
        $this->assertSame([$this->family['person_code']], array_column($detail->json('data.members'), 'person_code'));
        $detail->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.female_count', 0);

        $this->actingAs($this->staff)->getJson('/api/v1/families?search='.urlencode($this->family['family_code']))
            ->assertOk()->assertJsonPath('data.0.member_count', 1);
        // A former member's name no longer finds the Family.
        $this->actingAs($this->staff)->getJson('/api/v1/families?search='.urlencode('ابنة للتصحيح'))
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->staff)->getJson('/api/v1/people?search='.urlencode('ابنة للتصحيح'))
            ->assertOk()
            ->assertJsonPath('data.0.person_code', $this->memberCode)
            ->assertJsonPath('data.0.family', null);
    }

    public function test_a_reason_is_required(): void
    {
        foreach ([[], ['reason' => ''], ['reason' => '  '], ['reason' => 'اخ'], ['reason' => str_repeat('س', 256)]] as $body) {
            $this->end($this->memberCode, $body)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        }

        $this->assertTrue($this->membershipOf($this->memberCode)->is_active);
        $this->assertCount(0, $this->activities(FamilyActivityType::MEMBERSHIP_ENDED));
    }

    public function test_unauthorized_roles_cannot_end_a_membership(): void
    {
        // DATA_ENTRY corrects relationships but cannot end memberships: V1
        // has no reactivation, transfer or attach-existing-person.
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->end($this->memberCode, ['reason' => self::REASON], $role)->assertForbidden();
        }
        $this->end($this->memberCode, ['reason' => self::REASON], null)->assertUnauthorized();

        $this->assertTrue($this->membershipOf($this->memberCode)->is_active);
    }

    public function test_the_household_head_cannot_be_ended(): void
    {
        foreach (['ADMINISTRATOR', 'SUPER_ADMIN'] as $role) {
            $this->end($this->family['person_code'], ['reason' => self::REASON], $role)->assertStatus(409);
        }

        $head = $this->membershipOf($this->family['person_code']);
        $this->assertTrue($head->is_active);
        $this->assertTrue($head->is_household_head);
        $this->assertNull($head->ended_at);
        $this->assertCount(0, $this->activities(FamilyActivityType::MEMBERSHIP_ENDED));
    }

    public function test_only_a_current_membership_can_be_ended(): void
    {
        $this->end($this->memberCode)->assertOk();
        $endedAt = $this->membershipOf($this->memberCode)->updated_at;

        $this->end($this->memberCode)->assertStatus(409);
        $this->assertEquals($endedAt, $this->membershipOf($this->memberCode)->updated_at);

        $other = $this->family('رب أسرة أخرى');
        $this->end($other['person_code'])->assertStatus(409);
        $this->assertTrue($this->membershipOf($other['person_code'])->is_active);
    }

    public function test_ending_records_an_activity_without_the_reason(): void
    {
        $this->end($this->memberCode)->assertOk();

        $events = $this->activities(FamilyActivityType::MEMBERSHIP_ENDED);
        $this->assertCount(1, $events);
        $this->assertNull($events->first()->metadata);
        $this->assertSame(Person::where('person_code', $this->memberCode)->value('id'), $events->first()->subject_id);

        $timeline = $this->actingAs($this->staff)->getJson("/api/v1/families/{$this->family['family_code']}/activities")
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'MEMBERSHIP_ENDED')
            // The former member's name is still resolved for the history.
            ->assertJsonPath('data.0.subject.person.person_code', $this->memberCode);
        $this->assertStringNotContainsString(self::REASON, $timeline->getContent());
        $this->assertStringNotContainsString('end_reason', $timeline->getContent());
    }

    public function test_ended_membership_never_leaks_its_reason_through_family_or_person_responses(): void
    {
        $this->end($this->memberCode)->assertOk();

        foreach ([
            "/api/v1/families/{$this->family['family_code']}",
            "/api/v1/people/{$this->memberCode}",
            '/api/v1/people',
        ] as $url) {
            $this->assertStringNotContainsString(self::REASON, $this->actingAs($this->staff)->getJson($url)->assertOk()->getContent());
        }
    }
}
