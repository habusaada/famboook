<?php

namespace Tests\Feature\Assistances;

use App\Enums\BeneficiaryStatus;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\FamilyActivity;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Assistance V1-B common rules: execution mode, approval, rejection,
 * permissions, family history and activity (docs/03 §47d–§47f).
 */
class AssistanceApprovalTest extends TestCase
{
    use BuildsExecutionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpExecutionFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- execution mode

    public function test_execution_mode_is_required_and_validated(): void
    {
        $this->createAssistance(['execution_mode' => null])->assertUnprocessable()->assertJsonValidationErrors('execution_mode');
        $this->createAssistance(['execution_mode' => 'HYBRID'])->assertUnprocessable()->assertJsonValidationErrors('execution_mode');
        $this->createAssistance(['execution_mode' => 'EXTERNAL'])->assertCreated()->assertJsonPath('data.execution_mode', 'EXTERNAL');
        $this->createAssistance(['execution_mode' => 'INTERNAL'])->assertCreated()->assertJsonPath('data.execution_mode', 'INTERNAL');
    }

    public function test_execution_mode_can_change_in_draft_but_not_after_open(): void
    {
        $id = $this->createAssistance()->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$id}", ['execution_mode' => 'EXTERNAL'])
            ->assertOk()->assertJsonPath('data.execution_mode', 'EXTERNAL');

        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$id}/open")->assertOk();
        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$id}", ['execution_mode' => 'INTERNAL'])->assertStatus(409);
        $this->assertSame('EXTERNAL', Assistance::first()->execution_mode->value);
    }

    public function test_existing_v1a_assistances_default_to_internal(): void
    {
        // Rows created without an explicit mode (as V1-A rows were) get INTERNAL.
        $id = \DB::table('assistances')->insertGetId([
            'uuid' => (string) \Str::uuid(),
            'title' => 'مساعدة قديمة',
            'assistance_category_id' => 1,
            'assistance_type' => 'IN_KIND',
            'provider_name' => 'جهة',
            'status' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('INTERNAL', Assistance::find($id)->execution_mode->value);
    }

    // ---------------------------------------------------------------- approval

    public function test_individual_approval_records_actor_and_time(): void
    {
        $assistance = $this->openAssistanceOf('INTERNAL');
        $beneficiary = $this->nominate($assistance, $this->household()['family']);
        $approver = $this->user('SOCIAL_WORKER', 'باحث معتمِد');

        Carbon::setTestNow('2026-09-24 11:00:00');
        $this->approve($assistance, $beneficiary, $approver)->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.approved_by.name', 'باحث معتمِد');

        $beneficiary->refresh();
        $this->assertSame(BeneficiaryStatus::APPROVED, $beneficiary->status);
        $this->assertSame('2026-09-24 11:00:00', $beneficiary->approved_at->toDateTimeString());
        $this->assertSame($approver->id, $beneficiary->approved_by);
    }

    public function test_bulk_approval_is_all_or_nothing_on_nominated_only(): void
    {
        $assistance = $this->openAssistanceOf('INTERNAL');
        $a = $this->nominate($assistance, $this->household()['family']);
        $b = $this->nominate($assistance, $this->household()['family']);
        $c = $this->nominate($assistance, $this->household()['family']);
        $this->reject($assistance, $c)->assertOk();

        // One invalid (REJECTED) member rejects the whole request.
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/bulk-approve", [
            'nominee_ids' => [$a->uuid, $c->uuid],
        ])->assertUnprocessable()->assertJsonValidationErrors('nominee_ids.1');
        $this->assertSame(BeneficiaryStatus::NOMINATED, $a->fresh()->status);

        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/bulk-approve", [
            'nominee_ids' => [$a->uuid, $b->uuid],
        ])->assertOk()->assertJsonPath('data.approved', 2);

        $this->assertSame(BeneficiaryStatus::APPROVED, $a->fresh()->status);
        $this->assertSame(BeneficiaryStatus::APPROVED, $b->fresh()->status);

        // Already-approved rows cannot be approved again.
        $this->approve($assistance, $a)->assertUnprocessable();
    }

    public function test_removed_and_foreign_nominees_cannot_be_approved(): void
    {
        $assistance = $this->openAssistanceOf('INTERNAL');
        $removed = $this->nominate($assistance, $this->household()['family']);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/{$removed->uuid}/remove")->assertOk();
        $this->approve($assistance, $removed)->assertUnprocessable();

        $other = $this->openAssistanceOf('INTERNAL', ['title' => 'أخرى']);
        $foreign = $this->nominate($other, $this->household()['family']);
        $this->approve($assistance, $foreign)->assertNotFound();
    }

    public function test_rejection_requires_reason_and_is_terminal(): void
    {
        $assistance = $this->openAssistanceOf('INTERNAL');
        $beneficiary = $this->nominate($assistance, $this->household()['family']);

        $this->reject($assistance, $beneficiary, null)->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
        $this->reject($assistance, $beneficiary, '  ')->assertUnprocessable();

        $this->reject($assistance, $beneficiary, 'لا تنطبق الشروط')->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.rejection_reason', 'لا تنطبق الشروط');

        $this->reject($assistance, $beneficiary)->assertStatus(409);
        $this->approve($assistance, $beneficiary)->assertUnprocessable();
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/{$beneficiary->uuid}/remove")->assertStatus(409);
        $this->assertSame(BeneficiaryStatus::REJECTED, $beneficiary->fresh()->status);
    }

    public function test_approval_requires_open_assistance(): void
    {
        $assistance = $this->openAssistanceOf('INTERNAL');
        $beneficiary = $this->nominate($assistance, $this->household()['family']);
        $this->reject($assistance, $beneficiary)->assertOk();
        $this->complete($assistance)->assertOk();

        $second = AssistanceBeneficiary::create([
            'assistance_id' => $assistance->id, 'family_id' => $this->household()['family']->id,
            'nomination_source' => 'MANUAL', 'status' => 'NOMINATED', 'nominated_at' => now(),
        ]);
        $this->approve($assistance, $second)->assertStatus(409);
    }

    // ---------------------------------------------------------------- permissions

    public function test_approval_permissions(): void
    {
        $assistance = $this->openAssistanceOf('INTERNAL');

        foreach (['SUPER_ADMIN' => true, 'ADMINISTRATOR' => true, 'SOCIAL_WORKER' => true, 'DATA_ENTRY' => false, 'REVIEWER' => false, 'REPORTS_VIEWER' => false, 'FAMILY_USER' => false] as $role => $allowed) {
            $beneficiary = $this->nominate($assistance, $this->household()['family']);
            $user = $this->user($role);
            $allowed ? $this->approve($assistance, $beneficiary, $user)->assertOk() : $this->approve($assistance, $beneficiary, $user)->assertForbidden();
            $allowed ? $this->reject($assistance, $this->nominate($assistance, $this->household()['family']), 'x', $user)->assertOk()
                : $this->reject($assistance, $beneficiary, 'x', $user)->assertForbidden();
        }
    }

    public function test_completion_permission_is_admin_only(): void
    {
        foreach (['SUPER_ADMIN' => true, 'ADMINISTRATOR' => true, 'SOCIAL_WORKER' => false, 'DATA_ENTRY' => false, 'REVIEWER' => false] as $role => $allowed) {
            $assistance = $this->openAssistanceOf('INTERNAL', ['title' => "مساعدة {$role}"]);
            $response = $this->complete($assistance, $this->user($role));
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- counts, history, activity

    public function test_common_derived_counts(): void
    {
        $assistance = $this->openAssistanceOf('INTERNAL', ['target_beneficiaries' => 10]);
        $a = $this->nominate($assistance, $this->household()['family']);
        $b = $this->nominate($assistance, $this->household()['family']);
        $c = $this->nominate($assistance, $this->household()['family']);
        $d = $this->nominate($assistance, $this->household()['family']);
        $this->approve($assistance, $a)->assertOk();
        $this->reject($assistance, $b)->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/{$d->uuid}/remove")->assertOk();

        $stats = $this->statistics($assistance);
        $this->assertSame(10, $stats['target']);
        $this->assertSame(3, $stats['total_nominees']);
        $this->assertSame(1, $stats['pending_approval']);
        $this->assertSame(1, $stats['approved']);
        $this->assertSame(1, $stats['rejected']);
        $this->assertSame(1, $stats['removed']);
    }

    public function test_family_history_shows_state_without_sensitive_data(): void
    {
        ['family' => $family] = $this->household(['national_id' => '812345678', 'mobile' => '0591234567']);
        $internal = $this->openAssistanceOf('INTERNAL', ['title' => 'برنامج داخلي']);
        $rejected = $this->openAssistanceOf('EXTERNAL', ['title' => 'برنامج خارجي']);
        $this->approvedBeneficiary($internal, $family);
        $this->reject($rejected, $this->nominate($rejected, $family), 'سبب رفض سري')->assertOk();

        $response = $this->actingAs($this->user)->getJson("/api/v1/families/{$family->family_code}/assistances")->assertOk();
        $rows = collect($response->json('data'))->keyBy('assistance.title');
        $this->assertSame('APPROVED', $rows['برنامج داخلي']['status']);
        $this->assertSame('INTERNAL', $rows['برنامج داخلي']['assistance']['execution_mode']);
        $this->assertNull($rows['برنامج داخلي']['delivery']);
        $this->assertSame('REJECTED', $rows['برنامج خارجي']['status']);

        $raw = $response->getContent();
        foreach (['812345678', '0591234567', 'national_id', 'mobile', 'سبب رفض سري', 'snapshot'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, $secret);
        }

        $this->actingAs($this->user('REPORTS_VIEWER'))->getJson("/api/v1/families/{$family->family_code}/assistances")->assertForbidden();
    }

    public function test_approval_and_rejection_activity_without_reasons(): void
    {
        ['family' => $family] = $this->household();
        $assistance = $this->openAssistanceOf('INTERNAL');
        $this->approvedBeneficiary($assistance, $family);
        $other = $this->household();
        $this->reject($assistance, $this->nominate($assistance, $other['family']), 'سبب رفض سري')->assertOk();

        $this->assertSame(
            ['ASSISTANCE_NOMINEE_ADDED', 'ASSISTANCE_BENEFICIARY_APPROVED'],
            FamilyActivity::where('family_id', $family->id)->orderBy('id')->pluck('event_type')->map->value->all()
        );
        $this->assertTrue(FamilyActivity::where('family_id', $other['family']->id)->where('event_type', 'ASSISTANCE_BENEFICIARY_REJECTED')->exists());
        $this->assertSame(0, FamilyActivity::whereNotNull('metadata')->count());

        $raw = $this->actingAs($this->user)->getJson("/api/v1/families/{$other['family']->family_code}/activities")->getContent();
        $this->assertStringNotContainsString('سبب رفض سري', $raw);
    }

    public function test_person_marital_status_create_edit_and_default(): void
    {
        $admin = $this->user('SUPER_ADMIN');
        ['family' => $family, 'head' => $head] = $this->household();

        $this->actingAs($admin)->postJson("/api/v1/families/{$family->family_code}/members", [
            'full_name' => 'ابنة جديدة', 'gender' => 'FEMALE', 'birth_date' => '2004-01-01',
            'relationship_type_id' => \App\Models\RelationshipType::where('code', 'DAUGHTER')->value('id'),
            'marital_status' => 'SINGLE',
        ])->assertCreated();
        $this->assertSame('SINGLE', Person::where('full_name', 'ابنة جديدة')->first()->marital_status->value);

        $this->actingAs($admin)->postJson("/api/v1/families/{$family->family_code}/members", [
            'full_name' => 'ابن بلا حالة', 'gender' => 'MALE', 'birth_date' => '2006-01-01',
            'relationship_type_id' => \App\Models\RelationshipType::where('code', 'SON')->value('id'),
        ])->assertCreated();
        // Never inferred: UNKNOWN when not given.
        $this->assertSame('UNKNOWN', Person::where('full_name', 'ابن بلا حالة')->first()->marital_status->value);

        $this->actingAs($admin)->patchJson("/api/v1/people/{$head->person_code}", ['marital_status' => 'WIDOWED'])
            ->assertOk()->assertJsonPath('data.marital_status', 'WIDOWED');
        $this->actingAs($admin)->patchJson("/api/v1/people/{$head->person_code}", ['marital_status' => 'ENGAGED'])
            ->assertUnprocessable();
    }
}
