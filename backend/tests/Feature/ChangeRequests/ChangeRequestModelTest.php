<?php

namespace Tests\Feature\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Enums\FingerprintContext;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use App\Support\FamilyAuth\KeyedFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * The change_requests foundation on every driver (PWA-5a): identifiers,
 * request codes, immutability, deletion refusal, foreign keys and the safe
 * down(). The PostgreSQL CHECKs and trigger: ChangeRequestSchemaTest.
 * Synthetic data only.
 */
class ChangeRequestModelTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    public function test_a_request_gets_a_uuid_and_a_sequential_human_code_never_its_id(): void
    {
        $first = ChangeRequest::factory()->create();
        $second = ChangeRequest::factory()->create();

        $this->assertTrue(Str::isUuid($first->uuid));
        $this->assertNotSame($first->uuid, $second->uuid);
        $this->assertMatchesRegularExpression('/^CRQ-\d{6}$/', $first->request_code);
        $this->assertSame((int) substr($first->request_code, 4) + 1, (int) substr($second->request_code, 4));

        // The public identifier is the uuid; the id is never serialized.
        $this->assertSame('uuid', $first->getRouteKeyName());
        $array = $first->toArray();
        $this->assertArrayNotHasKey('id', $array);
        $this->assertArrayNotHasKey('base_fingerprint', $array);
        $this->assertArrayNotHasKey('base_key_version', $array);
    }

    public function test_request_code_and_uuid_are_unique(): void
    {
        $request = ChangeRequest::factory()->create();

        $this->expectException(QueryException::class);
        DB::transaction(fn () => ChangeRequest::factory()->create(['request_code' => $request->request_code]));
    }

    public function test_uuid_is_unique(): void
    {
        $request = ChangeRequest::factory()->create();

        $this->expectException(QueryException::class);
        DB::transaction(fn () => ChangeRequest::factory()->create(['uuid' => $request->uuid]));
    }

    public function test_client_reference_is_unique_per_submitting_user_only(): void
    {
        $reference = (string) Str::uuid();
        $request = ChangeRequest::factory()->create(['client_reference' => $reference]);

        // Another user may use the same reference; requests without one never collide.
        ChangeRequest::factory()->create(['client_reference' => $reference]);
        ChangeRequest::factory()->count(2)->create(['submitted_by' => $request->submitted_by, 'client_reference' => null]);

        $this->expectException(QueryException::class);
        DB::transaction(fn () => ChangeRequest::factory()->create([
            'family_id' => $request->family_id, 'submitted_by' => $request->submitted_by,
            'submitted_by_person_id' => $request->submitted_by_person_id, 'client_reference' => $reference,
        ]));
    }

    public function test_status_and_actor_columns_are_not_mass_assignable(): void
    {
        $fillable = (new ChangeRequest)->getFillable();

        foreach (['status', 'uuid', 'request_code', 'approved_by', 'approved_at', 'applied_by', 'applied_at', 'rejected_by',
            'rejection_reason_code', 'cancelled_by', 'reviewed_by', 'apply_failure_count'] as $column) {
            $this->assertNotContains($column, $fillable, $column);
        }
    }

    public function test_a_submitted_proposal_is_immutable(): void
    {
        $request = ChangeRequest::factory()->create();

        foreach ([
            'submitted_data' => ['example' => 'changed'],
            'reason' => 'تعديل لاحق',
            'person_id' => $request->submitted_by_person_id,
            'base_fingerprint' => str_repeat('a', 64),
            'request_code' => 'CRQ-999999',
        ] as $field => $value) {
            try {
                $request->fresh()->forceFill([$field => $value])->save();
                $this->fail("{$field} changed after submission");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        // Workflow columns may still move (the PWA-5b actions do this under a lock).
        $staff = User::factory()->create();
        $request->forceFill(['status' => ChangeRequestStatus::UNDER_REVIEW, 'reviewed_by' => $staff->id, 'reviewed_at' => now()])->save();
        $this->assertSame(ChangeRequestStatus::UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_a_finished_request_is_immutable_and_no_request_is_ever_deleted(): void
    {
        $request = ChangeRequest::factory()->create();
        $request->forceFill(['status' => ChangeRequestStatus::CANCELLED, 'cancelled_by' => $request->submitted_by, 'cancelled_at' => now()])->save();

        try {
            $request->fresh()->forceFill(['status' => ChangeRequestStatus::SUBMITTED, 'cancelled_by' => null, 'cancelled_at' => null])->save();
            $this->fail('A cancelled request was reopened');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        ChangeRequest::factory()->create()->delete();
    }

    public function test_foreign_keys_protect_families_people_and_actors(): void
    {
        $request = ChangeRequest::factory()->create();

        foreach ([
            ['families', $request->family_id],
            ['persons', $request->submitted_by_person_id],
            ['users', $request->submitted_by],
        ] as [$table, $id]) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
                $this->fail("{$table} row referenced by a change request was deleted");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(QueryException::class);
        DB::transaction(fn () => ChangeRequest::factory()->create(['target_membership_id' => 999_999]));
    }

    public function test_the_submitted_data_round_trips_as_an_array(): void
    {
        $request = ChangeRequest::factory()->create(['submitted_data' => ['governorate' => 'محافظة تجريبية', 'nested' => ['a' => 1]]]);

        // Key order is not preserved by PostgreSQL jsonb; the content is.
        $this->assertEquals(['governorate' => 'محافظة تجريبية', 'nested' => ['a' => 1]], $request->fresh()->submitted_data);
    }

    public function test_the_base_fingerprint_context_is_separate_from_every_other_context(): void
    {
        $this->useFamilyAuthKey();
        $value = '{"governorate":"x"}';

        $base = KeyedFingerprint::of(FingerprintContext::CHANGE_REQUEST_BASE, $value);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $base);
        $this->assertNotSame($base, KeyedFingerprint::of(FingerprintContext::MEMBER_REF, $value));
        $this->assertNotSame($base, KeyedFingerprint::of(FingerprintContext::MOBILE, $value));
    }

    public function test_down_refuses_while_change_request_history_exists(): void
    {
        $request = ChangeRequest::factory()->create();
        DB::transaction(fn () => WorkflowEventRecorder::record($request, null, WorkflowEventType::SUBMITTED, WorkflowActorSide::FAMILY, $request->submitted_by));

        foreach (['2026_10_17_090001_create_workflow_events_table', '2026_10_17_090000_create_change_requests_table'] as $name) {
            $migration = require database_path("migrations/{$name}.php");
            try {
                $migration->down();
                $this->fail("{$name} down() dropped existing history");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('history exists', $e->getMessage());
            }
        }

        $this->assertSame(1, DB::table('change_requests')->count());
        $this->assertSame(1, DB::table('workflow_events')->count());
    }
}
