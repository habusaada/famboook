<?php

namespace Tests\Feature\ChangeRequests\Api;

use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestTypes;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Staff API on the REAL Production registry for a type WITHOUT a handler
 * (PWA-5c; since PWA-6.1 only RESIDENCE_UPDATE is registered, so BIRTH_REPORT
 * stands for an unregistered type). Persisted requests (built as rows) stay
 * readable with type_available = false and no presentation; approve and
 * apply refuse; nothing of the stored proposal leaks.
 * Synthetic data only.
 */
class EmptyRegistryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole('REVIEWER'));
    }

    public function test_the_type_used_here_really_has_no_handler(): void
    {
        $this->assertFalse(app(ChangeRequestTypes::class)->has(ChangeRequestType::BIRTH_REPORT));
    }

    public function test_index_detail_and_timeline_load_without_any_handler_and_leak_nothing(): void
    {
        $request = ChangeRequest::factory()->create([
            'type' => 'BIRTH_REPORT', 'submitted_data' => ['governorate' => 'SECRET-GOV', 'mobile' => '0590000000'],
            'reason' => 'سبب',
        ]);

        $index = $this->actingAs($this->reviewer)->getJson('/api/v1/change-requests')->assertOk();
        $index->assertJsonPath('data.0.id', $request->uuid)
            ->assertJsonPath('data.0.type_available', false)
            ->assertJsonPath('data.0.available_actions', ['start_review']);

        $detail = $this->actingAs($this->reviewer)->getJson("/api/v1/change-requests/{$request->uuid}")->assertOk();
        $detail->assertJsonPath('data.type', 'BIRTH_REPORT')
            ->assertJsonPath('data.type_available', false)
            ->assertJsonPath('data.presentation', null)
            ->assertJsonPath('data.timeline', []);

        foreach ([$index, $detail] as $response) {
            foreach (['SECRET-GOV', '0590000000', 'submitted_data', 'base_fingerprint'] as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent());
            }
        }
    }

    public function test_review_can_start_but_approve_and_apply_refuse_an_unregistered_type(): void
    {
        $request = ChangeRequest::factory()->create(['type' => 'BIRTH_REPORT']);

        // Workflow steps that need no handler still work.
        $this->actingAs($this->reviewer)->postJson("/api/v1/change-requests/{$request->uuid}/start-review")
            ->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW');
        $this->actingAs($this->reviewer)->postJson("/api/v1/change-requests/{$request->uuid}/approve")
            ->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_TYPE_UNAVAILABLE');
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);

        $approved = ChangeRequest::factory()->approved($this->reviewer)->create(['type' => 'BIRTH_REPORT']);
        $this->actingAs($this->reviewer)->postJson("/api/v1/change-requests/{$approved->uuid}/apply")
            ->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_TYPE_UNAVAILABLE');
        $fresh = $approved->fresh();
        $this->assertSame(S::APPROVED, $fresh->status);
        // Not an apply attempt: nothing is counted.
        $this->assertSame(0, $fresh->apply_failure_count);
    }
}
