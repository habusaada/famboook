<?php

namespace Database\Factories;

use App\Enums\CoordinatorScopeType;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CoordinatorScopeAssignmentFactory extends Factory
{
    protected $model = CoordinatorScopeAssignment::class;

    /** An active CLAN-level assignment; pass the group or branch for the others. */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->familySide(),
            'scope_type' => CoordinatorScopeType::CLAN->value,
            'clan_id' => fn () => Clan::query()->firstOrCreate(['code' => 'TEST_CLAN'], ['name' => 'عشيرة تجريبية'])->id,
            'branch_group_id' => null,
            'branch_id' => null,
            'assigned_by' => User::factory(),
            'assigned_at' => now(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'revoked_by' => User::factory(),
            'revoked_at' => now(),
            'revoke_reason' => 'ADMINISTRATIVE',
        ]);
    }
}
