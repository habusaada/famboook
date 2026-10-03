<?php

namespace Tests\Support;

use App\Enums\CoordinatorScopeType;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;

/**
 * Synthetic Clan → Branch Group → Branch structure, families placed in it,
 * and Coordinators with scope assignments (PWA-1H). Built row by row, never
 * through the actions under test. Nothing here is real registry data.
 * Requires FamilyIdentityFixtures and a seeded RolePermissionSeeder.
 */
trait CoordinatorFixtures
{
    private int $structureSeq = 0;

    protected function clan(string $code = 'TEST_CLAN', bool $active = true): Clan
    {
        return Clan::query()->firstOrCreate(['code' => $code], ['name' => 'عشيرة '.$code, 'is_active' => $active]);
    }

    protected function group(Clan $clan, ?string $code = null, bool $active = true): BranchGroup
    {
        $code ??= 'GRP_'.(++$this->structureSeq);

        return BranchGroup::create(['clan_id' => $clan->id, 'code' => $code, 'name' => 'مجموعة '.$code, 'is_active' => $active]);
    }

    protected function branch(Clan $clan, ?BranchGroup $group = null, ?string $code = null, bool $active = true): Branch
    {
        $code ??= 'BR_'.(++$this->structureSeq);

        return Branch::create([
            'clan_id' => $clan->id, 'branch_group_id' => $group?->id, 'code' => $code, 'name' => 'فرع '.$code, 'is_active' => $active,
        ]);
    }

    /**
     * An ACTIVE Family in the given place, with a head and $members active
     * members in total (head included).
     */
    protected function familyIn(Clan $clan, ?Branch $branch = null, int $members = 1, array $attributes = []): Family
    {
        $family = Family::factory()->create(['clan_id' => $clan->id, 'branch_id' => $branch?->id, ...$attributes]);
        FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => Person::factory()->create(['full_name' => 'رب أسرة '.$family->family_code])->id,
            'is_household_head' => true,
        ]);
        for ($i = 1; $i < $members; $i++) {
            FamilyMembership::factory()->create(['family_id' => $family->id]);
        }

        return $family;
    }

    /** An activated, eligible household head who also holds COORDINATOR. */
    protected function coordinator(string $nationalId = '111111111', ?array $roles = null): User
    {
        $head = $this->activatedHead($nationalId, $roles ?? ['FAMILY_USER', 'COORDINATOR']);

        return $head['user'];
    }

    protected function assign(User $user, Clan|BranchGroup|Branch $target, bool $revoked = false): CoordinatorScopeAssignment
    {
        $columns = match (true) {
            $target instanceof Clan => ['scope_type' => CoordinatorScopeType::CLAN->value, 'clan_id' => $target->id],
            $target instanceof BranchGroup => ['scope_type' => CoordinatorScopeType::BRANCH_GROUP->value, 'clan_id' => $target->clan_id, 'branch_group_id' => $target->id],
            $target instanceof Branch => ['scope_type' => CoordinatorScopeType::BRANCH->value, 'clan_id' => $target->clan_id, 'branch_id' => $target->id],
        };
        $factory = CoordinatorScopeAssignment::factory();

        return ($revoked ? $factory->revoked() : $factory)->create(['user_id' => $user->id, ...$columns]);
    }
}
