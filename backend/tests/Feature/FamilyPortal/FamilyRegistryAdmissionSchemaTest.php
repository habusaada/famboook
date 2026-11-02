<?php

namespace Tests\Feature\FamilyPortal;

use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-04 PostgreSQL schema: CHECKs, foreign keys and the
 * append-only triggers. Rows are written with the query builder so the database
 * is what refuses them. Synthetic data only.
 */
class FamilyRegistryAdmissionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const ADMISSIONS = 'family_registry_admissions';

    private const EVENTS = 'family_registry_admission_events';

    private FamilyMembership $membership;

    private int $headPersonId;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints and triggers are PostgreSQL-only.');
        }
        $head = FamilyMembership::factory()->householdHead()->create();
        $this->headPersonId = $head->person_id;
        $this->membership = FamilyMembership::factory()->create(['family_id' => $head->family_id]);
        $this->user = User::factory()->familySide()->create()->id;
    }

    /** @return array<string, mixed> */
    private function admission(array $o = []): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'family_id' => $this->membership->family_id, 'person_id' => $this->membership->person_id,
            'family_membership_id' => $this->membership->id, 'admitted_by' => $this->user, 'admitted_by_person_id' => $this->headPersonId,
            'client_reference' => (string) Str::uuid(), 'outcome_type' => 'PERSON_CREATED', 'parent_link' => 'MOTHER',
            'reference_fingerprint' => str_repeat('b', 64), 'reference_key_version' => 1, 'release_id' => 7,
            'declaration_version' => '2026-11-v1', 'declared_at' => now(), 'created_at' => now(), ...$o,
        ];
    }

    /** @return array<string, mixed> */
    private function event(array $o = []): array
    {
        return [
            'event_type' => 'ADMISSION_REFUSED', 'actor_user_id' => $this->user, 'family_id' => $this->membership->family_id,
            'outcome_code' => 'ACTIVE_MEMBERSHIP', 'created_at' => now(), ...$o,
        ];
    }

    private function refuses(string $table, array $row, string $constraint): void
    {
        try {
            DB::transaction(fn () => DB::table($table)->insert($row));
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());

            return;
        }
        $this->fail("The database accepted what {$constraint} must refuse.");
    }

    public function test_valid_evidence_is_accepted_for_each_outcome_and_parent_link(): void
    {
        foreach ([['PERSON_CREATED', 'FATHER'], ['PERSON_ATTACHED', 'MOTHER']] as [$outcome, $link]) {
            $other = FamilyMembership::factory()->create(['family_id' => $this->membership->family_id]);
            $this->assertTrue(DB::table(self::ADMISSIONS)->insert($this->admission([
                'outcome_type' => $outcome, 'parent_link' => $link, 'family_membership_id' => $other->id, 'person_id' => $other->person_id,
            ])));
        }
    }

    public function test_evidence_constraints(): void
    {
        $this->refuses(self::ADMISSIONS, $this->admission(['outcome_type' => 'TRANSFERRED']), 'chk_registry_admission_outcome');
        $this->refuses(self::ADMISSIONS, $this->admission(['parent_link' => 'GUARDIAN']), 'chk_registry_admission_parent_link');
        $this->refuses(self::ADMISSIONS, $this->admission(['reference_fingerprint' => str_repeat('Z', 64)]), 'chk_registry_admission_fingerprint');
        $this->refuses(self::ADMISSIONS, $this->admission(['reference_key_version' => 0]), 'chk_registry_admission_key_version');
        $this->refuses(self::ADMISSIONS, $this->admission(['release_id' => 0]), 'chk_registry_admission_release');
        $this->refuses(self::ADMISSIONS, $this->admission(['declaration_version' => '  ']), 'chk_registry_admission_declaration');
        foreach (['release_id', 'declared_at', 'reference_fingerprint', 'parent_link', 'client_reference'] as $column) {
            $this->refuses(self::ADMISSIONS, $this->admission([$column => null]), 'null value');
        }
    }

    public function test_every_event_type_is_accepted_in_its_valid_shape(): void
    {
        $fingerprint = ['reference_fingerprint' => str_repeat('c', 64), 'reference_key_version' => 1];
        foreach ([
            ['event_type' => 'DISCOVERY_LISTED', 'outcome_code' => 'LISTED', 'result_count' => 3, 'release_id' => 7],
            ['event_type' => 'ADMISSION_SUCCEEDED', 'outcome_code' => 'ADMITTED', 'release_id' => 7, ...$fingerprint],
            ['event_type' => 'ADMISSION_REFUSED', 'outcome_code' => 'REFERENCE_INVALID'],
            ['event_type' => 'ADMISSION_REPLAYED', 'outcome_code' => 'REPLAYED', ...$fingerprint],
        ] as $values) {
            $this->assertTrue(DB::table(self::EVENTS)->insert($this->event($values)), $values['event_type']);
        }
    }

    public function test_event_constraints(): void
    {
        $this->refuses(self::EVENTS, $this->event(['event_type' => 'TRANSFERRED']), 'chk_registry_admission_event_type');
        $this->refuses(self::EVENTS, $this->event(['outcome_code' => 'free text']), 'chk_registry_admission_event_outcome');
        $this->refuses(self::EVENTS, $this->event(['reference_fingerprint' => str_repeat('c', 64)]), 'chk_registry_admission_event_fingerprint');
        $this->refuses(self::EVENTS, $this->event(['reference_key_version' => 1]), 'chk_registry_admission_event_fingerprint');
        $this->refuses(self::EVENTS, $this->event(['reference_fingerprint' => '401234567'.str_repeat('0', 55), 'reference_key_version' => 0]), 'chk_registry_admission_event_fingerprint');
        $this->refuses(self::EVENTS, $this->event(['event_type' => 'DISCOVERY_LISTED', 'reference_fingerprint' => str_repeat('c', 64), 'reference_key_version' => 1]), 'chk_registry_admission_event_discovery');
        $this->refuses(self::EVENTS, $this->event(['result_count' => 1]), 'chk_registry_admission_event_result_count');
        $this->refuses(self::EVENTS, $this->event(['ip_hash' => '127.0.0.1']), 'chk_registry_admission_event_ip_hash');
    }

    public function test_foreign_keys_refuse_unknown_rows_and_protect_referenced_rows(): void
    {
        foreach (['family_id', 'person_id', 'family_membership_id', 'admitted_by', 'admitted_by_person_id'] as $column) {
            $this->refuses(self::ADMISSIONS, $this->admission([$column => 999999999]), 'foreign key');
        }
        foreach (['actor_user_id', 'family_id'] as $column) {
            $this->refuses(self::EVENTS, $this->event([$column => 999999999]), 'foreign key');
        }

        DB::table(self::ADMISSIONS)->insert($this->admission());
        foreach ([
            ['family_memberships', $this->membership->id],
            ['persons', $this->membership->person_id],
            ['users', $this->user],
        ] as [$table, $id]) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
                $this->fail("{$table} was deleted from under the evidence.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key', $e->getMessage());
            }
        }
    }

    public function test_release_id_has_no_foreign_key_to_identity_ref(): void
    {
        $this->assertSame(0, (int) DB::scalar(
            "select count(*) from pg_constraint where contype = 'f' and conrelid in ('family_registry_admissions'::regclass, 'family_registry_admission_events'::regclass) and pg_get_constraintdef(oid) like '%identity_ref%'",
        ));
    }

    public function test_both_tables_are_append_only(): void
    {
        DB::table(self::ADMISSIONS)->insert($this->admission());
        DB::table(self::EVENTS)->insert($this->event());

        foreach ([self::ADMISSIONS => ['release_id' => 8], self::EVENTS => ['outcome_code' => 'CHANGED']] as $table => $change) {
            foreach (['update' => fn () => DB::table($table)->update($change), 'delete' => fn () => DB::table($table)->delete()] as $operation => $write) {
                try {
                    DB::transaction($write);
                    $this->fail("{$table} accepted {$operation}.");
                } catch (QueryException $e) {
                    $this->assertStringContainsString('append-only', $e->getMessage(), "{$table} {$operation}");
                }
            }
            $this->assertSame(1, DB::table($table)->count());
        }
    }

    public function test_the_rollback_leaves_no_function_or_trigger_behind(): void
    {
        $migration = require __DIR__.'/../../../database/migrations/2026_11_01_100000_create_family_registry_admission_tables.php';
        $function = "select count(*) from pg_proc where proname = 'famboook_family_registry_admission_append_only'";
        $this->assertSame(1, (int) DB::scalar($function));

        DB::transaction(fn () => $migration->down());
        $this->assertSame(0, (int) DB::scalar($function));

        DB::transaction(fn () => $migration->up());
        $this->assertSame(1, (int) DB::scalar($function));
    }
}
