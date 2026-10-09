<?php

namespace Tests\Feature\ChangeRequests\AddMember;

use App\Actions\AttachFamilyMemberAction;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\AddFamilyMemberHandler;
use App\Support\NationalIdGuard;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * AttachFamilyMemberAction, the equivalent National ID lookup and the Staff
 * approval throttle (docs/11 FP-ADR-076). Synthetic data only.
 */
class AttachFamilyMemberActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
    }

    private function refused(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a refusal.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_it_attaches_an_unattached_person_as_a_non_head_member_and_records_activity(): void
    {
        $family = Family::factory()->create();
        $person = Person::factory()->create(['full_name' => 'كما في السجل']);
        $user = User::factory()->create();

        $membership = app(AttachFamilyMemberAction::class)->handle($family, $person, 'SON', $user->id);

        $this->assertSame([$family->id, $person->id, true, false, 'SON'],
            [$membership->family_id, $membership->person_id, $membership->is_active, $membership->is_household_head, $membership->relationshipType->code]);
        $this->assertSame('كما في السجل', $person->fresh()->full_name);
        $activity = FamilyActivity::where('event_type', FamilyActivityType::FAMILY_MEMBER_ADDED)->sole();
        $this->assertSame([$family->id, $user->id], [$activity->family_id, $activity->actor_user_id]);
    }

    public function test_it_never_transfers_never_makes_a_head_and_writes_nothing_when_refused(): void
    {
        $family = Family::factory()->create();
        $elsewhere = Person::factory()->create();
        FamilyMembership::factory()->create(['family_id' => Family::factory()->create()->id, 'person_id' => $elsewhere->id, 'is_active' => true]);

        $this->refused(fn () => app(AttachFamilyMemberAction::class)->handle($family, $elsewhere, 'SON', null));
        $this->refused(fn () => app(AttachFamilyMemberAction::class)->handle($family, Person::factory()->create(), 'HEAD', null));

        $this->assertSame(0, FamilyMembership::where('family_id', $family->id)->count());
        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_equivalent_matches_are_conservative_and_never_rewrite(): void
    {
        $exact = Person::factory()->create(['national_id' => '401234567']);
        $dashed = Person::factory()->create(['national_id' => '401-234-567']);
        $arabic = Person::factory()->create(['national_id' => '٤٠١٢٣٤٥٦٧']);
        Person::factory()->create(['national_id' => '401234568']);
        Person::factory()->create(['national_id' => 'X401234567']);
        Person::factory()->create(['national_id' => null]);
        $deleted = Person::factory()->create(['national_id' => '401 234 567']);
        $deleted->delete();

        $this->assertSame([$exact->id, $dashed->id, $arabic->id], NationalIdGuard::equivalentMatches('401234567')->pluck('id')->all());
        $this->assertSame('401-234-567', $dashed->fresh()->national_id);
        $this->assertTrue(NationalIdGuard::equivalentMatches('')->isEmpty());
    }

    public function test_the_approve_endpoint_is_throttled_per_user(): void
    {
        config(['change_requests.staff_limits.approve_user_minute' => 3]);
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([ChangeRequestType::ADD_FAMILY_MEMBER->value => new AddFamilyMemberHandler]));
        $reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
        $request = ChangeRequest::factory()->create(['type' => ChangeRequestType::ADD_FAMILY_MEMBER]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($reviewer)->postJson("/api/v1/change-requests/{$request->uuid}/approve", ['verified_national_id' => '40123456'.$i])
                ->assertStatus(409); // still SUBMITTED: an invalid transition, after the throttle counted it
        }
        $this->actingAs($reviewer)->postJson("/api/v1/change-requests/{$request->uuid}/approve", ['verified_national_id' => '401234569'])->assertStatus(429);
    }
}
