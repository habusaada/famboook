<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\AuthIdentityStatus;
use App\Enums\FamilyStatus;
use App\Enums\LifeStatus;
use App\Enums\UserPersonLinkStatus;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-3A Step 3: GET /api/v1/family/household/members — one row per ACTIVE
 * membership of the signed-in head's own household. The Family comes only
 * from the family.context boundary; the projection is an explicit allow-list;
 * a soft-deleted Person becomes a placeholder that keeps the membership's
 * relationship. Synthetic data only.
 */
class FamilyHouseholdMembersTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/household/members';

    private const ROW_KEYS = [
        'available', 'full_name', 'relationship', 'is_household_head', 'gender', 'birth_date', 'marital_status', 'life_status',
        'death_date', 'national_id_masked', 'mobile_masked', 'alternate_mobile_masked', 'alternate_mobile_owner_relation',
        'membership_started_at',
    ];

    /** The Person part of a placeholder row: all NULL. */
    private const NO_PERSON = [
        'full_name' => null, 'gender' => null, 'birth_date' => null, 'marital_status' => null, 'life_status' => null, 'death_date' => null,
        'national_id_masked' => null, 'mobile_masked' => null, 'alternate_mobile_masked' => null, 'alternate_mobile_owner_relation' => null,
    ];

    private const DENIED = ['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function fetch(User $as, string $uri = self::URI): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson($uri);
    }

    /** An activated head whose membership carries the HEAD relationship, as registration does. */
    private function householdHead(string $nationalId = '123456789', array $roles = ['FAMILY_USER'], array $person = []): array
    {
        $head = $this->activatedHead($nationalId, $roles);
        $head['membership']->forceFill(['relationship_type_id' => $this->type('HEAD')])->save();
        $head['person']->forceFill(['full_name' => 'رب الأسرة التجريبي', 'gender' => 'MALE', 'birth_date' => '1970-01-01', ...$person])->save();

        return $head;
    }

    private function type(string $code): int
    {
        return (int) RelationshipType::query()->where('code', $code)->value('id');
    }

    /** An active (unless stated) non-head member. */
    private function member(Family $family, string $name, ?string $relationship, array $person = [], array $membership = []): FamilyMembership
    {
        return FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => Person::factory()->create(['full_name' => $name, ...$person])->id,
            'relationship_type_id' => $relationship === null ? null : $this->type($relationship),
            'paper_sequence_no' => null,
            ...$membership,
        ]);
    }

    private function names(TestResponse $response): array
    {
        return array_column($response->assertOk()->json('data.members'), 'full_name');
    }

    // -------------------------------------------------------------- contract

    public function test_each_active_membership_is_one_row_with_exactly_the_approved_fields(): void
    {
        $head = $this->householdHead('123456789', person: [
            'marital_status' => 'MARRIED', 'mobile' => '0591234567', 'alternate_mobile' => '0567654321', 'alternate_mobile_owner_relation' => 'أخ',
        ]);
        $head['membership']->forceFill(['started_at' => '2001-03-04'])->save();
        $this->member($head['family'], 'زوجة تجريبية', 'SPOUSE', [
            'gender' => 'FEMALE', 'birth_date' => '1975-03-04', 'marital_status' => 'UNKNOWN', 'national_id' => '807766554', 'mobile' => null,
        ], ['started_at' => null]);

        $response = $this->fetch($head['user'])->assertOk();

        $response->assertExactJson(['data' => [
            'family_code' => $head['family']->family_code,
            'members' => [
                [
                    'available' => true, 'full_name' => 'رب الأسرة التجريبي', 'relationship' => ['code' => 'HEAD', 'name' => 'رب الأسرة'],
                    'is_household_head' => true, 'gender' => 'MALE', 'birth_date' => '1970-01-01', 'marital_status' => 'MARRIED',
                    'life_status' => 'ALIVE', 'death_date' => null, 'national_id_masked' => '*****6789', 'mobile_masked' => '05*****567',
                    'alternate_mobile_masked' => '05*****321', 'alternate_mobile_owner_relation' => 'أخ', 'membership_started_at' => '2001-03-04',
                ],
                [
                    'available' => true, 'full_name' => 'زوجة تجريبية', 'relationship' => ['code' => 'SPOUSE', 'name' => 'زوج/زوجة'],
                    'is_household_head' => false, 'gender' => 'FEMALE', 'birth_date' => '1975-03-04', 'marital_status' => 'UNKNOWN',
                    'life_status' => 'ALIVE', 'death_date' => null, 'national_id_masked' => '*****6554', 'mobile_masked' => null,
                    'alternate_mobile_masked' => null, 'alternate_mobile_owner_relation' => null, 'membership_started_at' => null,
                ],
            ],
        ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        // Masked only: no full National ID or mobile, the head's included.
        foreach (['123456789', '807766554', '0591234567', '0567654321'] as $full) {
            $this->assertStringNotContainsString($full, $response->getContent(), $full);
        }
    }

    public function test_a_deceased_active_member_stays_visible_with_or_without_a_death_date(): void
    {
        $head = $this->householdHead();
        $this->member($head['family'], 'ابن متوفى بتاريخ', 'SON', ['life_status' => LifeStatus::DECEASED->value, 'birth_date' => '2000-01-01', 'death_date' => '2024-11-20']);
        $this->member($head['family'], 'ابن متوفى بلا تاريخ', 'SON', ['life_status' => LifeStatus::DECEASED->value, 'birth_date' => '2001-01-01', 'death_date' => null]);

        $response = $this->fetch($head['user'])->assertOk();
        $rows = collect($response->json('data.members'))->keyBy('full_name');

        $this->assertSame(['DECEASED', '2024-11-20'], [$rows['ابن متوفى بتاريخ']['life_status'], $rows['ابن متوفى بتاريخ']['death_date']]);
        $this->assertSame(['DECEASED', null], [$rows['ابن متوفى بلا تاريخ']['life_status'], $rows['ابن متوفى بلا تاريخ']['death_date']]);
        // Both still counted as registered members.
        $this->assertSame(3, $this->fetch($head['user'], '/api/v1/family/household')->json('data.registered_member_count'));
    }

    public function test_every_life_status_and_an_inactive_person_keep_an_available_row(): void
    {
        $head = $this->householdHead();
        $family = $head['family'];
        $this->member($family, 'ابن متوفى', 'SON', ['life_status' => LifeStatus::DECEASED->value, 'birth_date' => '2000-01-01']);
        $this->member($family, 'ابنة غير مؤكدة', 'DAUGHTER', ['life_status' => LifeStatus::UNKNOWN->value, 'birth_date' => '2001-01-01']);
        $this->member($family, 'ابن غير نشط', 'SON', ['is_active' => false, 'birth_date' => '2002-01-01']);

        $rows = collect($this->fetch($head['user'])->assertOk()->json('data.members'))->keyBy('full_name');

        $this->assertSame('DECEASED', $rows['ابن متوفى']['life_status']);
        $this->assertSame('UNKNOWN', $rows['ابنة غير مؤكدة']['life_status']);
        $this->assertSame('ALIVE', $rows['ابن غير نشط']['life_status']);
        foreach (['ابن متوفى', 'ابنة غير مؤكدة', 'ابن غير نشط'] as $name) {
            $this->assertTrue($rows[$name]['available'], $name);
            $this->assertSame(self::ROW_KEYS, array_keys($rows[$name]), $name);
        }
    }

    public function test_ended_memberships_are_left_out(): void
    {
        $head = $this->householdHead();
        $this->member($head['family'], 'عضوية منتهية', 'SON', membership: ['is_active' => false, 'ended_at' => now()->toDateString(), 'end_reason' => 'synthetic']);

        $this->assertSame(['رب الأسرة التجريبي'], $this->names($this->fetch($head['user'])));
    }

    public function test_a_soft_deleted_person_becomes_a_placeholder_that_keeps_the_membership_relationship(): void
    {
        $head = $this->householdHead();
        $deleted = $this->member($head['family'], 'شخص محذوف سري', 'DAUGHTER', [
            'gender' => 'FEMALE', 'birth_date' => '1999-09-09', 'life_status' => LifeStatus::DECEASED->value,
            'national_id' => '807766554', 'mobile' => '0591122334', 'marital_status' => 'WIDOWED',
        ], ['started_at' => null]);
        $deleted->person->delete();
        $unrelated = $this->member($head['family'], 'محذوف بلا علاقة', null, [], ['started_at' => null]);
        $unrelated->person->delete();

        $response = $this->fetch($head['user'])->assertOk();
        $rows = $response->json('data.members');

        $this->assertCount(3, $rows);
        $this->assertEquals([
            'available' => false, 'relationship' => ['code' => 'DAUGHTER', 'name' => 'ابنة'], 'is_household_head' => false,
            'membership_started_at' => null, ...self::NO_PERSON,
        ], $rows[1]);
        $this->assertSame(self::ROW_KEYS, array_keys($rows[1]));
        $this->assertEquals([
            'available' => false, 'relationship' => null, 'is_household_head' => false, 'membership_started_at' => null, ...self::NO_PERSON,
        ], $rows[2]);
        // Not even the placeholder Person's masks (the head's own row is masked legitimately).
        foreach (['شخص محذوف سري', 'محذوف بلا علاقة', '1999-09-09', 'DECEASED', 'WIDOWED', '*****6554', '05*****334'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent(), $secret);
        }
    }

    public function test_the_dashboard_count_equals_the_number_of_rows_across_every_lifecycle_state(): void
    {
        $head = $this->householdHead();
        $family = $head['family'];
        $this->member($family, 'حي', 'SON');
        $this->member($family, 'متوفى', 'SON', ['life_status' => LifeStatus::DECEASED->value]);
        $this->member($family, 'غير مؤكد', 'DAUGHTER', ['life_status' => LifeStatus::UNKNOWN->value]);
        $this->member($family, 'غير نشط', 'OTHER', ['is_active' => false]);
        $this->member($family, 'محذوف', 'SPOUSE')->person->delete();
        $this->member($family, 'بلا علاقة', null);
        $this->member($family, 'منتهية', 'SON', membership: ['is_active' => false, 'ended_at' => now()->toDateString(), 'end_reason' => 'synthetic']);

        $count = $this->fetch($head['user'], '/api/v1/family/household')->assertOk()->json('data.registered_member_count');
        $rows = $this->fetch($head['user'])->assertOk()->json('data.members');

        $this->assertSame(7, $count); // head + 6 active; the ended membership is in neither
        $this->assertCount($count, $rows);
    }

    // ---------------------------------------------------------------- order

    public function test_the_approved_order_head_spouses_children_parents_other_unrecorded(): void
    {
        $head = $this->householdHead();
        $f = $head['family'];
        // Created in a scrambled order on purpose.
        $this->member($f, 'بلا علاقة', null, ['birth_date' => '1950-01-01']);
        $this->member($f, 'أخرى', 'OTHER', ['birth_date' => '1960-01-01']);
        $this->member($f, 'أم', 'MOTHER', ['birth_date' => '1945-01-01']);
        $this->member($f, 'ابن صغير', 'SON', ['birth_date' => '2010-01-01']);
        $this->member($f, 'زوجة ثانية', 'SPOUSE', ['birth_date' => '1985-01-01']);
        $this->member($f, 'ابنة كبرى', 'DAUGHTER', ['birth_date' => '2000-01-01']);
        $this->member($f, 'أب', 'FATHER', ['birth_date' => '1940-01-01']);
        $this->member($f, 'ابن أوسط', 'SON', ['birth_date' => '2005-01-01']);
        $this->member($f, 'زوجة أولى', 'SPOUSE', ['birth_date' => '1975-01-01']);
        $this->member($f, 'ابنة بلا تاريخ', 'DAUGHTER', ['birth_date' => null]);
        $guardian = RelationshipType::query()->create(['code' => 'GUARDIAN', 'name' => 'وصي', 'is_active' => true, 'sort_order' => 7]);
        FamilyMembership::factory()->create([
            'family_id' => $f->id, 'person_id' => Person::factory()->create(['full_name' => 'رمز مستقبلي', 'birth_date' => '1955-01-01'])->id,
            'relationship_type_id' => $guardian->id,
        ]);

        $this->assertSame([
            'رب الأسرة التجريبي',
            'زوجة أولى', 'زوجة ثانية',
            'ابنة كبرى', 'ابن أوسط', 'ابن صغير', 'ابنة بلا تاريخ', // sons and daughters together, by age, unknown last
            'أب', 'أم',                                         // parents together, by age
            'رمز مستقبلي', 'أخرى',                              // OTHER and future codes together, by age
            'بلا علاقة',
        ], $this->names($this->fetch($head['user'])));
    }

    public function test_the_head_comes_first_whatever_its_relationship_or_age(): void
    {
        // A legacy head membership with no relationship, younger than the members.
        $head = $this->householdHead(person: ['birth_date' => '2001-01-01']);
        $head['membership']->forceFill(['relationship_type_id' => null])->save();
        $this->member($head['family'], 'زوجة', 'SPOUSE', ['birth_date' => '1960-01-01']);

        $rows = $this->fetch($head['user'])->assertOk()->json('data.members');

        $this->assertSame('رب الأسرة التجريبي', $rows[0]['full_name']);
        $this->assertTrue($rows[0]['is_household_head']);
        $this->assertNull($rows[0]['relationship']);
    }

    public function test_ties_break_on_paper_sequence_then_the_membership_and_never_on_a_deleted_birth_date(): void
    {
        $head = $this->householdHead();
        $f = $head['family'];
        $this->member($f, 'ابن بلا تسلسل أول', 'SON', ['birth_date' => '2000-05-05']);
        $this->member($f, 'ابن تسلسل 3', 'SON', ['birth_date' => '2000-05-05'], ['paper_sequence_no' => 3]);
        $this->member($f, 'ابن بلا تسلسل ثان', 'SON', ['birth_date' => '2000-05-05']);
        $this->member($f, 'ابن تسلسل 2', 'SON', ['birth_date' => '2000-05-05'], ['paper_sequence_no' => 2]);
        // A soft-deleted Person's birth date does not place it: it sorts as unknown.
        $this->member($f, 'محذوف قديم', 'SON', ['birth_date' => '1990-01-01'])->person->delete();

        $rows = $this->fetch($head['user'])->assertOk()->json('data.members');

        $this->assertSame(
            ['رب الأسرة التجريبي', 'ابن تسلسل 2', 'ابن تسلسل 3', 'ابن بلا تسلسل أول', 'ابن بلا تسلسل ثان', null],
            array_column($rows, 'full_name'),
        );
        // Stable across requests.
        $this->assertSame($rows, $this->fetch($head['user'])->assertOk()->json('data.members'));
    }

    // ---------------------------------------------------- context and IDOR

    public function test_spoofed_identifiers_never_switch_the_household(): void
    {
        $mine = $this->householdHead('111111111');
        $other = $this->householdHead('222222222', person: ['full_name' => 'رب أسرة أخرى']);
        $this->member($other['family'], 'فرد من أسرة أخرى', 'SON');
        $expected = $this->fetch($mine['user'])->assertOk()->json();

        $query = http_build_query([
            'family_id' => $other['family']->id, 'person_id' => $other['person']->id,
            'membership_id' => $other['membership']->id, 'family_code' => $other['family']->family_code,
        ]);
        $spoofed = $this->fetch($mine['user'], self::URI.'?'.$query)->assertOk();

        $this->assertSame($expected, $spoofed->json());
        foreach ([$other['family']->family_code, 'رب أسرة أخرى', 'فرد من أسرة أخرى'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $spoofed->getContent(), $foreign);
        }
    }

    public function test_a_dual_role_head_sees_only_their_own_members(): void
    {
        $head = $this->householdHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 4);
        $this->assign($head['user'], $clan);

        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame($head['family']->family_code, $response->json('data.family_code'));
        $this->assertSame(['رب الأسرة التجريبي'], $this->names($response));
        $this->assertStringNotContainsString($assigned->family_code, $response->getContent());
    }

    public function test_no_full_sensitive_value_or_internal_field_appears(): void
    {
        $head = $this->householdHead('123456789');
        $head['person']->forceFill(['mobile' => '0597766554', 'alternate_mobile' => '0568877665', 'notes' => 'ملاحظة سرية للرب'])->save();
        $member = $this->member($head['family'], 'زوجة', 'SPOUSE', [
            'national_id' => '807766554', 'mobile' => '0591122334', 'alternate_mobile' => '0563344556', 'notes' => 'ملاحظة سرية للزوجة',
        ], ['paper_sequence_no' => 4321, 'notes' => 'ملاحظة عضوية سرية']);
        PersonHealthRecord::factory()->create(['person_id' => $member->person_id, 'condition_name' => 'حالة صحية سرية', 'details' => 'تفاصيل صحية سرية']);

        $body = $this->fetch($head['user'])->assertOk()->getContent();

        foreach (['123456789', '807766554', '0597766554', '0568877665', '0591122334', '0563344556', 'ملاحظة', '4321',
            'حالة صحية', $head['person']->person_code, $member->person->person_code, 'person_code', 'notes', 'paper_sequence', 'is_active',
            '"id"', 'person_id', 'family_id', 'membership_id', 'relationship_type_id', 'deleted_at', 'created', 'updated', '"national_id"',
            '"mobile"', '"alternate_mobile"', 'health'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
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
        $mixed = $this->householdHead('222222222')['user'];
        $mixed->assignRole('ADMINISTRATOR');
        $accounts['mixed staff and family'] = $mixed;

        foreach ($accounts as $label => $user) {
            $this->fetch($user)->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
        }
    }

    public function test_family_portal_access_is_enforced(): void
    {
        $head = $this->householdHead();
        $this->fetch($head['user'])->assertOk();

        Role::findByName('FAMILY_USER', 'web')->revokePermissionTo('family-portal.access');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->fetch($head['user'])->assertForbidden();
        $this->assertStringNotContainsString('رب الأسرة التجريبي', $response->getContent());
    }

    /** @return array<string, array{0: string}> */
    public static function denials(): array
    {
        return [
            'link suspended' => ['link-suspended'],
            'link ended' => ['link-ended'],
            'head deceased' => ['deceased'],
            'head life status unknown' => ['unknown'],
            'head inactive' => ['person-inactive'],
            'head soft-deleted' => ['person-deleted'],
            'auth identity suspended' => ['identity-suspended'],
            'national id changed behind the identity' => ['identity-mismatch'],
            'not the household head' => ['not-head'],
            'membership ended' => ['membership-ended'],
            'family inactive' => ['family-inactive'],
            'family archived' => ['family-archived'],
            'family soft-deleted' => ['family-deleted'],
        ];
    }

    #[DataProvider('denials')]
    public function test_every_lost_family_context_gets_the_same_generic_403(string $denial): void
    {
        $head = $this->householdHead('123456789');
        $this->member($head['family'], 'فرد تجريبي', 'SON');
        $this->fetch($head['user'])->assertOk();

        match ($denial) {
            'link-suspended' => $head['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save(),
            'link-ended' => $head['link']->forceFill(['status' => UserPersonLinkStatus::ENDED->value, 'ended_at' => now(), 'end_reason' => 'ADMINISTRATIVE'])->save(),
            'deceased' => $head['person']->forceFill(['life_status' => LifeStatus::DECEASED->value])->save(),
            'unknown' => $head['person']->forceFill(['life_status' => LifeStatus::UNKNOWN->value])->save(),
            'person-inactive' => $head['person']->forceFill(['is_active' => false])->save(),
            'person-deleted' => $head['person']->delete(),
            'identity-suspended' => $head['identity']->forceFill(['status' => AuthIdentityStatus::SUSPENDED->value])->save(),
            'identity-mismatch' => DB::table('persons')->where('id', $head['person']->id)->update(['national_id' => '987654321']),
            'not-head' => $head['membership']->forceFill(['is_household_head' => false])->save(),
            'membership-ended' => $head['membership']->forceFill(['is_active' => false, 'is_household_head' => false, 'ended_at' => now()])->save(),
            'family-inactive' => $head['family']->forceFill(['status' => FamilyStatus::INACTIVE->value])->save(),
            'family-archived' => $head['family']->forceFill(['status' => FamilyStatus::ARCHIVED->value])->save(),
            'family-deleted' => $head['family']->delete(),
        };

        $response = $this->fetch($head['user'])->assertForbidden()->assertExactJson(self::DENIED);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('فرد تجريبي', $response->getContent());
    }

    // ---------------------------------------------------------------- reads

    public function test_the_query_count_does_not_grow_with_the_household(): void
    {
        $small = $this->householdHead('111111111');
        $this->member($small['family'], 'فرد', 'SON');
        $large = $this->householdHead('222222222');
        foreach (range(1, 20) as $i) {
            $this->member($large['family'], 'فرد '.$i, $i % 2 ? 'SON' : 'DAUGHTER');
        }
        $this->member($large['family'], 'محذوف', 'SPOUSE')->person->delete();

        $count = function (User $user): int {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user->fresh());
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::URI)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count($small['user']); // warm-up: one-off queries (permission cache) are not part of the comparison

        $this->assertSame($count($small['user']), $count($large['user']));
        $this->assertCount(22, $this->fetch($large['user'])->json('data.members'));
    }
}
