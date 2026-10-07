<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\AssessmentStatus;
use App\Enums\UserPersonLinkStatus;
use App\Http\Controllers\Api\V1\Family\FamilyHouseholdController;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\Assessment;
use App\Models\AuthSecurityEvent;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\FamilyNeed;
use App\Models\NeedCategory;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\FamilyPortal\HouseholdMemberReference;
use App\Support\StaffRoles;
use Database\Seeders\NeedCategorySeeder;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-3B.7: GET /api/v1/family/household/needs — the registered needs of the
 * signed-in head's Family, every status, as an explicit allow-list. The
 * Family comes only from the family.context boundary; nothing in the request
 * chooses one. A FULFILLED need is a registry status, never proof of a
 * delivery. Synthetic data only.
 */
class FamilyHouseholdNeedsTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/household/needs';

    private const NEED_KEYS = ['category', 'title', 'quantity', 'unit', 'status', 'created_at', 'resolved_at', 'person'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->seed(NeedCategorySeeder::class);
        $this->useFamilyAuthKey();
    }

    private function fetch(User $as, string $uri = self::URI): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson($uri);
    }

    private function member(Family $family, string $name, array $person = [], array $membership = []): FamilyMembership
    {
        return FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => Person::factory()->create(['full_name' => $name, ...$person])->id,
            'relationship_type_id' => RelationshipType::where('code', 'SON')->value('id'),
            ...$membership,
        ]);
    }

    private function need(Family $family, array $attributes = []): FamilyNeed
    {
        $status = $attributes['status'] ?? 'OPEN';
        $need = FamilyNeed::create([
            'family_id' => $family->id,
            'need_category_id' => NeedCategory::where('code', $attributes['category'] ?? 'FOOD')->value('id'),
            'title' => $attributes['title'] ?? 'سلة غذائية',
            'description' => $attributes['description'] ?? null,
            'priority' => $attributes['priority'] ?? 'MEDIUM',
            'quantity' => array_key_exists('quantity', $attributes) ? $attributes['quantity'] : null,
            'unit' => $attributes['unit'] ?? null,
            'status' => $status,
            'person_id' => $attributes['person_id'] ?? null,
            'source_assessment_id' => $attributes['source_assessment_id'] ?? null,
            'resolved_at' => $status === 'OPEN' ? null : ($attributes['resolved_at'] ?? now()),
            'closure_reason' => $status === 'CLOSED' ? ($attributes['closure_reason'] ?? 'سبب إغلاق داخلي') : null,
            'created_by' => $attributes['created_by'] ?? null,
            'resolved_by' => $attributes['resolved_by'] ?? null,
        ]);
        if (isset($attributes['created_at'])) {
            $need->forceFill(['created_at' => $attributes['created_at']])->saveQuietly();
        }

        return $need;
    }

    private function ref(FamilyMembership $membership): string
    {
        return HouseholdMemberReference::of((int) $membership->family_id, (int) $membership->id);
    }

    /** @return list<array<string, mixed>> */
    private function needs(TestResponse $response): array
    {
        return $response->assertOk()->json('data.needs');
    }

    // -------------------------------------------------------------- contract

    public function test_a_family_wide_need_is_returned_with_exactly_the_approved_fields(): void
    {
        $head = $this->activatedHead();
        $this->need($head['family'], ['title' => 'سلة غذائية', 'quantity' => 2, 'unit' => 'سلة', 'created_at' => '2026-09-30 10:00:00']);

        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame(['needs'], array_keys($response->json('data')));
        $this->assertSame([[
            'category' => ['code' => 'FOOD', 'name' => 'الغذاء'], 'title' => 'سلة غذائية', 'quantity' => '2', 'unit' => 'سلة',
            'status' => 'OPEN', 'created_at' => '2026-09-30', 'resolved_at' => null, 'person' => null,
        ]], $response->json('data.needs'));
    }

    public function test_responses_are_never_cached(): void
    {
        $head = $this->activatedHead();

        $cache = (string) $this->fetch($head['user'])->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public function test_a_family_without_needs_gets_an_empty_list(): void
    {
        $head = $this->activatedHead();

        $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => ['needs' => []]]);
    }

    public function test_every_status_is_returned_as_history_with_its_resolution_date(): void
    {
        $head = $this->activatedHead();
        $this->need($head['family'], ['title' => 'قائم']);
        $this->need($head['family'], ['title' => 'مُلبّى', 'status' => 'FULFILLED', 'resolved_at' => '2026-09-20 08:00:00']);
        $this->need($head['family'], ['title' => 'مغلق', 'status' => 'CLOSED', 'resolved_at' => '2026-09-21 08:00:00']);

        $needs = collect($this->needs($this->fetch($head['user'])))->keyBy('title');

        $this->assertSame(['OPEN', null], [$needs['قائم']['status'], $needs['قائم']['resolved_at']]);
        $this->assertSame(['FULFILLED', '2026-09-20'], [$needs['مُلبّى']['status'], $needs['مُلبّى']['resolved_at']]);
        $this->assertSame(['CLOSED', '2026-09-21'], [$needs['مغلق']['status'], $needs['مغلق']['resolved_at']]);
    }

    public function test_quantities_are_trimmed_and_nulls_stay_null(): void
    {
        $head = $this->activatedHead();
        $this->need($head['family'], ['title' => 'عشري', 'quantity' => 2.5, 'unit' => 'كغ']);
        $this->need($head['family'], ['title' => 'بلا كمية', 'quantity' => null, 'unit' => null]);

        $needs = collect($this->needs($this->fetch($head['user'])))->keyBy('title');

        $this->assertSame(['2.5', 'كغ'], [$needs['عشري']['quantity'], $needs['عشري']['unit']]);
        $this->assertSame([null, null], [$needs['بلا كمية']['quantity'], $needs['بلا كمية']['unit']]);
    }

    public function test_the_order_is_open_first_then_newest(): void
    {
        $head = $this->activatedHead();
        $this->need($head['family'], ['title' => 'مغلق حديث', 'status' => 'CLOSED', 'created_at' => '2026-09-29 10:00:00']);
        $this->need($head['family'], ['title' => 'قائم قديم', 'created_at' => '2026-01-01 10:00:00']);
        $this->need($head['family'], ['title' => 'قائم حديث', 'created_at' => '2026-09-01 10:00:00']);
        $this->need($head['family'], ['title' => 'مُلبّى قديم', 'status' => 'FULFILLED', 'created_at' => '2025-01-01 10:00:00']);

        $this->assertSame(['قائم حديث', 'قائم قديم', 'مغلق حديث', 'مُلبّى قديم'], array_column($this->needs($this->fetch($head['user'])), 'title'));
    }

    // ------------------------------------------------------------ the person

    public function test_a_current_members_need_names_them_with_their_member_ref(): void
    {
        $head = $this->activatedHead();
        $son = $this->member($head['family'], 'ابن تجريبي');
        $this->need($head['family'], ['person_id' => $son->person_id]);

        $this->assertSame(
            ['member_ref' => $this->ref($son), 'full_name' => 'ابن تجريبي', 'available' => true],
            $this->needs($this->fetch($head['user']))[0]['person'],
        );
    }

    public function test_a_former_or_deceased_member_keeps_their_name_without_a_member_ref(): void
    {
        $head = $this->activatedHead();
        $former = $this->member($head['family'], 'فرد سابق');
        $late = $this->member($head['family'], 'فرد متوفى', ['life_status' => 'DECEASED']);
        $this->need($head['family'], ['title' => 'احتياج سابق', 'person_id' => $former->person_id]);
        $this->need($head['family'], ['title' => 'احتياج متوفى', 'person_id' => $late->person_id]);
        $former->forceFill(['is_active' => false, 'ended_at' => now()])->save();

        $needs = collect($this->needs($this->fetch($head['user'])))->keyBy('title');

        $this->assertSame(['member_ref' => null, 'full_name' => 'فرد سابق', 'available' => true], $needs['احتياج سابق']['person']);
        $this->assertSame(['member_ref' => $this->ref($late), 'full_name' => 'فرد متوفى', 'available' => true], $needs['احتياج متوفى']['person']);
    }

    public function test_a_soft_deleted_person_is_unavailable_and_unnamed(): void
    {
        $head = $this->activatedHead();
        $gone = $this->member($head['family'], 'فرد محذوف');
        $this->need($head['family'], ['person_id' => $gone->person_id]);
        $gone->person->delete();

        $response = $this->fetch($head['user']);

        $this->assertSame(['member_ref' => null, 'full_name' => null, 'available' => false], $this->needs($response)[0]['person']);
        $this->assertStringNotContainsString('فرد محذوف', $response->getContent());
    }

    // ------------------------------------------------------------- isolation

    public function test_another_family_is_never_included_whatever_the_request_says(): void
    {
        $head = $this->activatedHead('123456789');
        $other = $this->activatedHead('222222222');
        $this->need($other['family'], ['title' => 'احتياج أسرة أخرى']);

        $response = $this->fetch($head['user'], self::URI.'?family='.$other['family']->family_code.'&family_id='.$other['family']->id)
            ->assertOk()->assertExactJson(['data' => ['needs' => []]]);

        $this->assertStringNotContainsString('أسرة أخرى', $response->getContent());
    }

    public function test_coordinator_scope_never_widens_it(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 2);
        $this->assign($head['user'], $clan);
        $this->need($assigned, ['title' => 'احتياج ضمن النطاق']);
        $this->need($head['family'], ['title' => 'احتياج أسرتي']);

        $this->assertSame(['احتياج أسرتي'], array_column($this->needs($this->fetch($head['user'])), 'title'));
    }

    // ------------------------------------------------------------- privacy

    public function test_no_internal_field_reaches_the_client(): void
    {
        $head = $this->activatedHead();
        $staff = User::factory()->create(['name' => 'موظف الاحتياجات']);
        $assessment = Assessment::create(['family_id' => $head['family']->id, 'assessment_date' => '2026-09-01', 'status' => AssessmentStatus::DRAFT]);
        $son = $this->member($head['family'], 'ابن تجريبي');
        $need = $this->need($head['family'], [
            'status' => 'CLOSED', 'closure_reason' => 'سبب داخلي سري', 'description' => 'وصف داخلي سري', 'priority' => 'URGENT',
            'source_assessment_id' => $assessment->id, 'created_by' => $staff->id, 'resolved_by' => $staff->id, 'person_id' => $son->person_id,
        ]);

        $response = $this->fetch($head['user']);
        $raw = $response->getContent();

        $this->assertSame(self::NEED_KEYS, array_keys($this->needs($response)[0]));
        foreach (['سبب داخلي سري', 'وصف داخلي سري', 'URGENT', 'موظف الاحتياجات', $need->uuid, $assessment->uuid,
            $son->person->person_code, $head['family']->family_code] as $value) {
            $this->assertStringNotContainsString((string) $value, $raw);
        }
        foreach (['"id"', 'uuid', 'family_id', 'person_id', 'person_code', 'priority', 'description', 'closure_reason',
            'resolved_by', 'created_by', 'updated_by', 'assessment', 'sort_order', 'is_active'] as $key) {
            $this->assertStringNotContainsString($key, $raw, $key);
        }
    }

    public function test_reading_writes_nothing(): void
    {
        $head = $this->activatedHead();
        $this->need($head['family']);
        $counts = fn () => [AuthSecurityEvent::count(), FamilyActivity::count(), FamilyNeed::count(), FamilyNeed::max('updated_at')];
        $before = $counts();

        $this->fetch($head['user'])->assertOk();

        $this->assertSame($before, $counts());
    }

    public function test_the_route_takes_no_parameter(): void
    {
        $route = Route::getRoutes()->getByAction(FamilyHouseholdController::class.'@needs');

        $this->assertSame('api/v1/family/household/needs', $route->uri());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(['api', 'auth:sanctum', 'family.side', 'can:family-portal.access', 'family.context'], $route->gatherMiddleware());
    }

    // --------------------------------------------------------- the boundary

    public function test_a_guest_gets_the_json_401(): void
    {
        $this->getJson(self::URI)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_staff_coordinator_only_and_mixed_accounts_are_refused_by_family_side(): void
    {
        $accounts = ['coordinator only' => $this->familyUser(['COORDINATOR']), 'role-less' => User::factory()->create()];
        foreach (StaffRoles::ALL as $role) {
            $accounts[$role] = tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
        }
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        $staff->givePermissionTo('family-portal.access');
        $accounts['staff with the permission'] = $staff;
        $mixed = $this->activatedHead('222222222');
        $this->need($mixed['family'], ['title' => 'احتياج حساب مختلط']);
        $mixed['user']->assignRole('ADMINISTRATOR');
        $accounts['mixed staff and family'] = $mixed['user'];

        foreach ($accounts as $label => $user) {
            $response = $this->fetch($user)->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
            $this->assertStringNotContainsString('احتياج', $response->getContent(), $label);
        }
    }

    public function test_a_lost_family_context_gets_the_generic_403(): void
    {
        $head = $this->activatedHead();
        $this->need($head['family'], ['title' => 'احتياج قائم']);
        $this->fetch($head['user'])->assertOk();

        $head['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();

        $response = $this->fetch($head['user'])->assertForbidden()
            ->assertExactJson(['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE]);
        $this->assertStringNotContainsString('احتياج قائم', $response->getContent());
    }

    public function test_family_portal_access_is_enforced(): void
    {
        $head = $this->activatedHead();
        $this->fetch($head['user'])->assertOk();

        Role::findByName('FAMILY_USER', 'web')->revokePermissionTo('family-portal.access');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->fetch($head['user'])->assertForbidden();
    }
}
