<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Reference/catalog data only: idempotent and safe in every environment
     * (AUTH-ADR-057). No user account is ever seeded — in particular no
     * known-password account. The first SUPER_ADMIN is created interactively
     * with `php artisan famboook:create-super-admin`; local development uses
     * the environment-gated /dev-login; tests use factories.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(ClanSeeder::class);
        $this->call(RelationshipTypeSeeder::class);
        $this->call(DisabilityTypeSeeder::class);
        $this->call(AssessmentDomainSeeder::class);
        $this->call(NeedCategorySeeder::class);
        $this->call(AssistanceCategorySeeder::class);
    }
}
