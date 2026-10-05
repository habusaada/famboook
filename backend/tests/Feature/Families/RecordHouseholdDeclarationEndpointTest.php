<?php

namespace Tests\Feature\Families;

use App\Enums\FamilyActivityType;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyHouseholdDeclaration;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FU-10: the Staff household declaration endpoint
 * POST /api/v1/families/{family}/household-declarations
 * (RecordHouseholdDeclarationAction, family.update). A new current
 * declaration with history kept, declared values stored as declared, and a
 * required stale-write expectation. Synthetic data only.
 */
class RecordHouseholdDeclarationEndpointTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private User $staff;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $this->staff = User::factory()->create()->assignRole('ADMINISTRATOR');
        $this->family = Family::factory()->create();
        FamilyMembership::factory()->create(['family_id' => $this->family->id, 'person_id' => Person::factory()->create()->id, 'is_household_head' => true]);
    }

    /** @param array<string, mixed> $body */
    private function declareVia(User $as, array $body = [], ?Family $family = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->postJson('/api/v1/families/'.($family ?? $this->family)->family_code.'/household-declarations', [
            'declared_household_size' => 7,
            'declared_living_sons' => 3,
            'declared_living_daughters' => 2,
            'declared_at' => '2026-09-01',
            'source' => 'PAPER_FORM',
            'expected_current_declaration_id' => $this->family->fresh()->currentHouseholdDeclaration?->id,
            ...$body,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function declarations(): array
    {
        return FamilyHouseholdDeclaration::orderBy('id')->get()
            ->map->only(['id', 'declared_household_size', 'declared_living_sons', 'declared_living_daughters', 'source', 'is_current'])->all();
    }

    private function activities(): int
    {
        return FamilyActivity::where('event_type', FamilyActivityType::HOUSEHOLD_DECLARATION_RECORDED)->count();
    }

    // -------------------------------------------------------------- recording

    public function test_the_first_declaration_is_recorded_and_returned_with_the_family(): void
    {
        $response = $this->declareVia($this->staff, ['expected_current_declaration_id' => null])->assertOk();

        $declaration = FamilyHouseholdDeclaration::sole();
        $response->assertJsonPath('data.family_code', $this->family->family_code)
            ->assertJsonPath('data.declared_household_size', 7)
            ->assertJsonPath('data.declared_living_sons', 3)
            ->assertJsonPath('data.declared_living_daughters', 2)
            ->assertJsonPath('data.declared_at', '2026-09-01')
            ->assertJsonPath('data.declaration_source', 'PAPER_FORM')
            ->assertJsonPath('data.current_declaration_id', $declaration->id)
            // Registered stays derived from ACTIVE memberships.
            ->assertJsonPath('data.member_count', 1);
        $this->assertTrue($declaration->is_current);
        $this->assertSame($this->staff->id, $declaration->created_by);
        $this->assertSame($this->staff->id, $declaration->updated_by);
        $this->assertNull($declaration->notes);
    }

    public function test_a_new_declaration_becomes_current_and_keeps_the_previous_one_as_history(): void
    {
        $this->declareVia($this->staff, ['declared_household_size' => 5, 'declared_living_sons' => 1])->assertOk();
        $first = FamilyHouseholdDeclaration::sole();

        $this->declareVia($this->staff, ['declared_household_size' => 8, 'source' => 'VERIFIED_SOURCE', 'expected_current_declaration_id' => $first->id])
            ->assertOk()->assertJsonPath('data.declared_household_size', 8);

        $first->refresh();
        $this->assertFalse($first->is_current);
        $this->assertSame(5, $first->declared_household_size);
        $this->assertSame(1, $first->declared_living_sons);
        $this->assertSame(1, FamilyHouseholdDeclaration::where('is_current', true)->count());
        $this->assertSame(2, FamilyHouseholdDeclaration::count());
    }

    public function test_zero_null_and_inconsistent_values_are_stored_as_declared(): void
    {
        $this->declareVia($this->staff, [
            'declared_household_size' => 0, 'declared_living_sons' => null, 'declared_living_daughters' => 0, 'declared_at' => null, 'source' => 'MANUAL_ENTRY',
        ])->assertOk()
            ->assertJsonPath('data.declared_household_size', 0)
            ->assertJsonPath('data.declared_living_sons', null)
            ->assertJsonPath('data.declared_living_daughters', 0)
            ->assertJsonPath('data.declared_at', null);

        // No arithmetic between the counts, nor with the registered members.
        $current = FamilyHouseholdDeclaration::sole()->id;
        $this->declareVia($this->staff, ['declared_household_size' => 2, 'declared_living_sons' => 5, 'declared_living_daughters' => 4, 'expected_current_declaration_id' => $current])
            ->assertOk()->assertJsonPath('data.declared_household_size', 2)->assertJsonPath('data.member_count', 1);
    }

    public function test_only_the_household_size_or_only_the_children_may_be_declared(): void
    {
        $this->declareVia($this->staff, ['declared_living_sons' => null, 'declared_living_daughters' => null])->assertOk();
        $this->declareVia($this->staff, ['declared_household_size' => null, 'declared_living_sons' => 2])
            ->assertOk()->assertJsonPath('data.declared_household_size', null)->assertJsonPath('data.declared_living_sons', 2);
    }

    public function test_one_activity_with_the_actor_and_no_declared_values(): void
    {
        $this->declareVia($this->staff)->assertOk();

        $activity = FamilyActivity::where('event_type', FamilyActivityType::HOUSEHOLD_DECLARATION_RECORDED)->sole();
        $this->assertSame($this->family->id, $activity->family_id);
        $this->assertSame($this->staff->id, $activity->actor_user_id);
        $this->assertNull($activity->metadata);
    }

    public function test_no_person_or_membership_is_created(): void
    {
        $persons = Person::count();
        $memberships = FamilyMembership::count();

        $this->declareVia($this->staff, ['declared_household_size' => 12, 'declared_living_sons' => 6])->assertOk();

        $this->assertSame($persons, Person::count());
        $this->assertSame($memberships, FamilyMembership::count());
    }

    // ------------------------------------------------------- stale protection

    public function test_a_stale_expectation_answers_409_and_overwrites_nothing(): void
    {
        $this->declareVia($this->staff, ['declared_household_size' => 5])->assertOk();
        $seen = FamilyHouseholdDeclaration::sole()->id;
        // Another Staff member records a newer declaration meanwhile.
        $this->declareVia($this->staff, ['declared_household_size' => 6, 'expected_current_declaration_id' => $seen])->assertOk();
        $before = $this->declarations();

        foreach ([$seen, null] as $stale) {
            $this->declareVia($this->staff, ['declared_household_size' => 9, 'expected_current_declaration_id' => $stale])
                ->assertStatus(409)
                ->assertExactJson([
                    'message' => 'تغيّر الإقرار الحالي لهذه الأسرة منذ فتح الصفحة. راجع الإقرار المحدَّث قبل تسجيل إقرار جديد.',
                    'code' => 'HOUSEHOLD_DECLARATION_CHANGED',
                ]);
        }

        $this->assertSame($before, $this->declarations());
        $this->assertSame(2, $this->activities());
    }

    public function test_a_duplicate_submission_is_refused_as_stale(): void
    {
        $body = ['expected_current_declaration_id' => null];
        $this->declareVia($this->staff, $body)->assertOk();

        $this->declareVia($this->staff, $body)->assertStatus(409)->assertJsonPath('code', 'HOUSEHOLD_DECLARATION_CHANGED');

        $this->assertSame(1, FamilyHouseholdDeclaration::count());
        $this->assertSame(1, $this->activities());
    }

    public function test_a_declaration_of_another_family_is_not_a_valid_expectation(): void
    {
        $other = Family::factory()->create();
        $otherDeclaration = FamilyHouseholdDeclaration::factory()->create(['family_id' => $other->id, 'is_current' => true]);

        $this->declareVia($this->staff, ['expected_current_declaration_id' => $otherDeclaration->id])
            ->assertStatus(409)->assertJsonPath('code', 'HOUSEHOLD_DECLARATION_CHANGED');

        $this->assertSame(0, FamilyHouseholdDeclaration::where('family_id', $this->family->id)->count());
        $this->assertTrue($otherDeclaration->fresh()->is_current);
    }

    // ----------------------------------------------------------------- input

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBodies(): array
    {
        return [
            'nothing declared' => [['declared_household_size' => null, 'declared_living_sons' => null, 'declared_living_daughters' => null], 'declared_household_size'],
            'negative size' => [['declared_household_size' => -1], 'declared_household_size'],
            'negative sons' => [['declared_living_sons' => -1], 'declared_living_sons'],
            'negative daughters' => [['declared_living_daughters' => -1], 'declared_living_daughters'],
            'fractional size' => [['declared_household_size' => 2.5], 'declared_household_size'],
            'text size' => [['declared_household_size' => 'سبعة'], 'declared_household_size'],
            'above the domain maximum' => [['declared_household_size' => 32768], 'declared_household_size'],
            'future declared_at' => [['declared_at' => now()->addDay()->toDateString()], 'declared_at'],
            'invalid declared_at' => [['declared_at' => '2026-02-30'], 'declared_at'],
            'missing source' => [['source' => null], 'source'],
            'IMPORT source' => [['source' => 'IMPORT'], 'source'],
            'unknown source' => [['source' => 'FAMILY_PORTAL'], 'source'],
            'non-integer expectation' => [['expected_current_declaration_id' => 'abc'], 'expected_current_declaration_id'],
            'family id' => [['family_id' => 1], 'family_id'],
            'is_current' => [['is_current' => false], 'is_current'],
            'created_by' => [['created_by' => 1], 'created_by'],
            'updated_by' => [['updated_by' => 1], 'updated_by'],
            'notes' => [['notes' => 'ملاحظة'], 'notes'],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function test_invalid_input_is_refused_and_writes_nothing(array $body, string $field): void
    {
        $this->declareVia($this->staff, $body)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame([], $this->declarations());
        $this->assertSame(0, $this->activities());
    }

    public function test_an_omitted_expectation_is_refused(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)->postJson('/api/v1/families/'.$this->family->family_code.'/household-declarations', [
            'declared_household_size' => 7, 'source' => 'PAPER_FORM',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_current_declaration_id');

        $this->assertSame([], $this->declarations());
    }

    public function test_a_soft_deleted_or_missing_family_answers_404(): void
    {
        $deleted = Family::factory()->create();
        $deleted->delete();

        $this->declareVia($this->staff, ['expected_current_declaration_id' => null], $deleted)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)->postJson('/api/v1/families/FAM-999999/household-declarations', [
            'declared_household_size' => 7, 'source' => 'PAPER_FORM', 'expected_current_declaration_id' => null,
        ])->assertNotFound();
        $this->assertSame([], $this->declarations());
    }

    // --------------------------------------------------------- authorization

    public function test_holders_of_family_update_may_record(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $this->declareVia(User::factory()->create()->assignRole($role))->assertOk();
        }

        $this->assertSame(4, FamilyHouseholdDeclaration::count());
        $this->assertSame(1, FamilyHouseholdDeclaration::where('is_current', true)->count());
    }

    public function test_staff_without_family_update_are_refused(): void
    {
        foreach (['REVIEWER', 'REPORTS_VIEWER'] as $role) {
            $this->declareVia(User::factory()->create()->assignRole($role))->assertForbidden();
        }

        $this->assertSame([], $this->declarations());
    }

    public function test_family_side_coordinator_and_mixed_accounts_never_reach_it(): void
    {
        $familyUser = $this->activatedHead('111111111');
        $coordinator = $this->coordinator('222222222');
        $mixed = $this->activatedHead('333333333')['user'];
        $mixed->assignRole('ADMINISTRATOR');
        foreach ([$familyUser['user'], $coordinator, $mixed] as $user) {
            // Even holding the permission directly, even for their own Family.
            $user->givePermissionTo('family.update');
            $this->declareVia($user)->assertForbidden()->assertExactJson(['message' => EnsureStaffSideAccount::MESSAGE]);
            $this->declareVia($user, [], $familyUser['family'])->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/families/'.$this->family->family_code.'/household-declarations', [
            'declared_household_size' => 7, 'source' => 'PAPER_FORM', 'expected_current_declaration_id' => null,
        ])->assertUnauthorized();

        $this->assertSame([], $this->declarations());
    }

    public function test_the_family_portal_never_receives_the_staff_declaration_reference(): void
    {
        $head = $this->activatedHead('123456789');
        $this->declareVia($this->staff, ['expected_current_declaration_id' => null], $head['family'])->assertOk();

        foreach (['/api/v1/family/household', '/api/v1/family/household/profile'] as $path) {
            $this->app['auth']->forgetGuards();
            $json = $this->actingAs($head['user']->fresh())->getJson($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('current_declaration_id', $json);
            $this->assertStringNotContainsString('declaration_source', $json);
        }
    }

    public function test_the_generic_family_update_never_writes_a_declaration(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)->patchJson('/api/v1/families/'.$this->family->family_code, [
            'notes' => 'ملاحظة', 'declared_household_size' => 9,
        ])->assertOk()->assertJsonPath('data.declared_household_size', null);

        $this->assertSame([], $this->declarations());
    }
}
