<?php

namespace Tests\Feature\Assistances;

use App\Enums\BeneficiaryStatus;
use App\Enums\NeedStatus;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\AssistanceDelivery;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * INTERNAL execution: identity-verified PERSONAL/DELEGATE receipt of the
 * full package, NOT_DELIVERED, reversal, statistics and completion
 * (docs/03-BUSINESS-RULES.md §47e). All National IDs are synthetic.
 */
class AssistanceInternalDeliveryTest extends TestCase
{
    use BuildsExecutionFixtures, RefreshDatabase;

    private const HEAD_ID = '800000001';

    private Assistance $assistance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpExecutionFixtures();
        $this->assistance = $this->openAssistanceOf('INTERNAL', [
            'target_beneficiaries' => 4,
            'items' => [
                ['item_name' => 'فرشة', 'quantity_per_beneficiary' => 4, 'unit' => 'قطعة'],
                ['item_name' => 'مساعدة نقدية', 'quantity_per_beneficiary' => 1, 'unit' => 'دفعة', 'unit_value' => 500, 'currency' => 'ILS'],
                ['item_name' => 'قسيمة', 'quantity_per_beneficiary' => 2, 'unit' => 'قسيمة', 'unit_value' => 10, 'currency' => 'USD'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A family with one member per interesting relationship/marital case. */
    private function fullHousehold(array $head = []): array
    {
        return $this->household($head, [
            'son' => ['SON', ['full_name' => 'ابن أعزب', 'gender' => 'MALE', 'national_id' => '800000011', 'marital_status' => 'SINGLE']],
            'daughter' => ['DAUGHTER', ['full_name' => 'ابنة عزباء', 'national_id' => '800000012', 'marital_status' => 'SINGLE']],
            'married' => ['SON', ['full_name' => 'ابن متزوج', 'gender' => 'MALE', 'national_id' => '800000013', 'marital_status' => 'MARRIED']],
            'divorced' => ['DAUGHTER', ['full_name' => 'ابنة مطلقة', 'national_id' => '800000014', 'marital_status' => 'DIVORCED']],
            'widowed' => ['DAUGHTER', ['full_name' => 'ابنة أرملة', 'national_id' => '800000015', 'marital_status' => 'WIDOWED']],
            'unknown' => ['SON', ['full_name' => 'ابن حالة غير معروفة', 'gender' => 'MALE', 'national_id' => '800000016', 'marital_status' => 'UNKNOWN']],
            'spouse' => ['SPOUSE', ['full_name' => 'زوجة', 'national_id' => '800000017', 'marital_status' => 'MARRIED']],
            'father' => ['FATHER', ['full_name' => 'أب', 'gender' => 'MALE', 'national_id' => '800000018', 'marital_status' => 'SINGLE']],
            'mother' => ['MOTHER', ['full_name' => 'أم', 'national_id' => '800000019', 'marital_status' => 'SINGLE']],
            'other' => ['OTHER', ['full_name' => 'قريب', 'gender' => 'MALE', 'national_id' => '800000020', 'marital_status' => 'SINGLE']],
            'former' => ['SON', ['full_name' => 'ابن سابق', 'gender' => 'MALE', 'national_id' => '800000021', 'marital_status' => 'SINGLE', '_inactive' => true]],
        ]);
    }

    private function url(AssistanceBeneficiary $b, string $suffix = 'delivery', ?Assistance $a = null): string
    {
        return '/api/v1/assistances/'.($a ?? $this->assistance)->uuid."/nominees/{$b->uuid}/{$suffix}";
    }

    private function verify(AssistanceBeneficiary $b, array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson($this->url($b, 'delivery/verify'), $payload);
    }

    private function deliver(AssistanceBeneficiary $b, array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson($this->url($b), $payload);
    }

    private function personal(string $id = self::HEAD_ID): array
    {
        return ['receipt_mode' => 'PERSONAL', 'beneficiary_national_id' => $id];
    }

    private function delegate(string $delegateId, string $id = self::HEAD_ID): array
    {
        return ['receipt_mode' => 'DELEGATE', 'beneficiary_national_id' => $id, 'delegate_national_id' => $delegateId];
    }

    // ---------------------------------------------------------------- PERSONAL

    public function test_personal_with_correct_head_id_verifies_and_delivers(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);

        $verify = $this->verify($b, $this->personal())->assertOk()
            ->assertJsonPath('data.receipt_mode', 'PERSONAL')
            ->assertJsonPath('data.beneficiary.person_code', $h['head']->person_code)
            ->assertJsonPath('data.recipient.person_code', $h['head']->person_code)
            ->assertJsonCount(3, 'data.package');
        $this->assertStringNotContainsString(self::HEAD_ID, $verify->getContent());
        // Verification alone writes nothing.
        $this->assertSame(0, AssistanceDelivery::count());

        Carbon::setTestNow('2026-09-24 12:00:00');
        $response = $this->deliver($b, $this->personal())->assertCreated()
            ->assertJsonPath('data.active_delivery.receipt_mode', 'PERSONAL')
            ->assertJsonPath('data.active_delivery.recipient.person_code', $h['head']->person_code);
        $this->assertStringNotContainsString(self::HEAD_ID, $response->getContent());

        $delivery = AssistanceDelivery::first();
        $this->assertSame($h['head']->id, $delivery->original_beneficiary_person_id);
        $this->assertSame($h['head']->id, $delivery->recipient_person_id);
        $this->assertSame($this->user->id, $delivery->delivered_by);
        $this->assertSame('2026-09-24 12:00:00', $delivery->delivered_at->toDateTimeString());
        // Beneficiary stays APPROVED; "delivered" is the delivery record.
        $this->assertSame(BeneficiaryStatus::APPROVED, $b->fresh()->status);
    }

    public function test_national_id_is_normalized(): void
    {
        $h = $this->household(['national_id' => '800-000 001']);
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);

        // Arabic-Indic digits and separators are normalized on both sides.
        $this->verify($b, $this->personal('٨٠٠٠٠٠٠٠١'))->assertOk();
        $this->verify($b, $this->personal(' 800 000 001 '))->assertOk();
    }

    public function test_personal_rejects_wrong_unknown_and_other_member_ids(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);

        foreach (['800000999', '12', '800000011' /* son */, '800000017' /* spouse */] as $wrong) {
            $this->verify($b, $this->personal($wrong))->assertUnprocessable()->assertJsonValidationErrors('beneficiary_national_id');
            $this->deliver($b, $this->personal($wrong))->assertUnprocessable();
        }
        $this->verify($b, ['receipt_mode' => 'PERSONAL'])->assertUnprocessable()->assertJsonValidationErrors('beneficiary_national_id');
        $this->assertSame(0, AssistanceDelivery::count());
    }

    public function test_original_beneficiary_is_the_current_head_even_if_female(): void
    {
        $h = $this->household(['full_name' => 'ربة أسرة', 'gender' => 'FEMALE', 'national_id' => '800000031', 'marital_status' => 'WIDOWED']);
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);

        $this->verify($b, $this->personal('800000031'))->assertOk()->assertJsonPath('data.beneficiary.full_name', 'ربة أسرة');
    }

    public function test_family_without_current_head_cannot_receive(): void
    {
        $h = $this->household([], [], withHead: false);
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);

        $this->verify($b, $this->personal())->assertUnprocessable()->assertJsonValidationErrors('beneficiary_national_id');
    }

    public function test_person_level_beneficiary_is_the_nominated_person(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family'], $h['people']['daughter']);

        $this->verify($b, $this->personal())->assertUnprocessable();
        $this->verify($b, $this->personal('800000012'))->assertOk()->assertJsonPath('data.beneficiary.full_name', 'ابنة عزباء');
    }

    // ---------------------------------------------------------------- DELEGATE

    public function test_delegate_single_son_or_daughter_is_accepted(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);

        $this->verify($b, $this->delegate('800000012'))->assertOk()
            ->assertJsonPath('data.recipient.full_name', 'ابنة عزباء')
            ->assertJsonPath('data.relationship', 'DAUGHTER')
            ->assertJsonPath('data.recipient_marital_status', 'SINGLE');
        $this->verify($b, $this->delegate('800000011'))->assertOk()->assertJsonPath('data.relationship', 'SON');

        $response = $this->deliver($b, $this->delegate('800000011'))->assertCreated()
            ->assertJsonPath('data.active_delivery.receipt_mode', 'DELEGATE')
            ->assertJsonPath('data.active_delivery.original_beneficiary.person_code', $h['head']->person_code)
            ->assertJsonPath('data.active_delivery.recipient.person_code', $h['people']['son']->person_code);
        foreach ([self::HEAD_ID, '800000011'] as $id) {
            $this->assertStringNotContainsString($id, $response->getContent());
        }

        $delivery = AssistanceDelivery::first();
        $this->assertNotSame($delivery->original_beneficiary_person_id, $delivery->recipient_person_id);
    }

    public function test_delegate_rules_block_ineligible_recipients(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);
        $stranger = $this->household(['national_id' => '800000041'], [
            'child' => ['SON', ['gender' => 'MALE', 'national_id' => '800000042', 'marital_status' => 'SINGLE']],
        ]);

        $cases = [
            'married child' => '800000013',
            'divorced child' => '800000014',
            'widowed child' => '800000015',
            'unknown marital status' => '800000016',
            'spouse' => '800000017',
            'father' => '800000018',
            'mother' => '800000019',
            'other relative' => '800000020',
            'former member' => '800000021',
            'wrong family' => '800000042',
            'unknown id' => '800009999',
            'same person' => self::HEAD_ID,
        ];
        foreach ($cases as $label => $id) {
            $this->verify($b, $this->delegate($id))->assertUnprocessable()->assertJsonValidationErrors('delegate_national_id');
            $this->deliver($b, $this->delegate($id))->assertUnprocessable();
        }
        $this->assertSame(0, AssistanceDelivery::count());
    }

    public function test_delegate_requires_both_ids_and_the_correct_original(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);

        $this->verify($b, ['receipt_mode' => 'DELEGATE', 'beneficiary_national_id' => self::HEAD_ID])
            ->assertUnprocessable()->assertJsonValidationErrors('delegate_national_id');
        $this->verify($b, ['receipt_mode' => 'DELEGATE', 'delegate_national_id' => '800000011'])
            ->assertUnprocessable()->assertJsonValidationErrors('beneficiary_national_id');
        // Correct child, but the original beneficiary's ID is wrong.
        $this->verify($b, $this->delegate('800000011', '800000999'))
            ->assertUnprocessable()->assertJsonValidationErrors('beneficiary_national_id');
    }

    public function test_person_level_delegation_is_blocked_when_relationship_is_not_provable(): void
    {
        $h = $this->fullHousehold();
        // The spouse is nominated personally; SON/DAUGHTER are recorded
        // relative to the head, so they are not provably her children.
        $b = $this->approvedBeneficiary($this->assistance, $h['family'], $h['people']['spouse']);

        $this->verify($b, $this->delegate('800000011', '800000017'))
            ->assertUnprocessable()->assertJsonValidationErrors('delegate_national_id');
        $this->verify($b, $this->personal('800000017'))->assertOk();
    }

    public function test_person_level_delegation_works_when_nominee_is_the_head(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family'], $h['head']);

        $this->verify($b, $this->delegate('800000012'))->assertOk();
    }

    // ---------------------------------------------------------------- delivery rules

    public function test_delivery_requires_approved_internal_open(): void
    {
        $h = $this->fullHousehold();
        $nominated = $this->nominate($this->assistance, $h['family']);
        $this->verify($nominated, $this->personal())->assertStatus(409);

        $external = $this->openAssistanceOf('EXTERNAL', ['title' => 'خارجي']);
        $b = $this->approvedBeneficiary($external, $h['family']);
        $this->actingAs($this->user)->postJson($this->url($b, 'delivery', $external), $this->personal())->assertStatus(409);
        $this->actingAs($this->user)->postJson($this->url($b, 'not-delivered', $external), ['not_delivered_reason' => 'x'])->assertStatus(409);
        $this->assertSame(0, AssistanceDelivery::count());
    }

    public function test_only_one_active_delivery_and_full_package_totals(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);
        $this->deliver($b, $this->personal())->assertCreated();
        $this->deliver($b, $this->personal())->assertStatus(409);
        $this->assertSame(1, AssistanceDelivery::count());
        // Full package only: no per-item delivery table exists.
        $this->assertFalse(Schema::hasTable('assistance_delivery_items'));

        $stats = $this->statistics($this->assistance);
        $this->assertSame(1, $stats['delivered']);
        $this->assertSame(['item_name' => 'فرشة', 'unit' => 'قطعة', 'quantity' => '4'], $stats['package_totals'][0]);
        $this->assertEqualsCanonicalizing(
            [['currency' => 'ILS', 'total' => '500'], ['currency' => 'USD', 'total' => '20']],
            $stats['monetary_totals']
        );
        $this->assertSame(25.0, (float) $stats['execution_percentage']);
    }

    public function test_delivery_never_changes_the_need(): void
    {
        $h = $this->fullHousehold();
        $need = $this->need($h['family']);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$this->assistance->uuid}/nominees/from-needs", ['need_ids' => [$need->uuid]])->assertOk();
        $b = AssistanceBeneficiary::first();
        $this->approve($this->assistance, $b)->assertOk();
        $this->deliver($b, $this->personal())->assertCreated();

        $this->assertSame(NeedStatus::OPEN, $need->fresh()->status);
        $this->assertNull($need->fresh()->resolved_at);
    }

    public function test_national_ids_are_never_persisted_in_deliveries(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);
        $this->deliver($b, $this->delegate('800000012') + ['notes' => 'ملاحظة تسليم'])->assertCreated();

        $row = json_encode(DB::table('assistance_deliveries')->first());
        $this->assertStringNotContainsString(self::HEAD_ID, $row);
        $this->assertStringNotContainsString('800000012', $row);
        $this->assertFalse(Schema::hasColumn('assistance_deliveries', 'national_id'));

        foreach ([
            "/api/v1/assistances/{$this->assistance->uuid}",
            "/api/v1/assistances/{$this->assistance->uuid}/nominees",
            "/api/v1/families/{$h['family']->family_code}/assistances",
            "/api/v1/families/{$h['family']->family_code}/activities",
        ] as $url) {
            $raw = $this->actingAs($this->user)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::HEAD_ID, $raw, $url);
            $this->assertStringNotContainsString('800000012', $raw, $url);
        }
    }

    // ---------------------------------------------------------------- reversal / not delivered

    public function test_reversal_keeps_history_and_allows_fresh_delivery(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);
        $this->deliver($b, $this->personal())->assertCreated();
        $delivery = AssistanceDelivery::first();

        $this->actingAs($this->user)->postJson("/api/v1/assistance-deliveries/{$delivery->uuid}/reverse", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reversal_reason');
        $this->actingAs($this->user('SOCIAL_WORKER'))->postJson("/api/v1/assistance-deliveries/{$delivery->uuid}/reverse", ['reversal_reason' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->user)->postJson("/api/v1/assistance-deliveries/{$delivery->uuid}/reverse", ['reversal_reason' => 'سُجّل بالخطأ'])
            ->assertOk()
            ->assertJsonPath('data.active_delivery', null)
            ->assertJsonPath('data.reversed_deliveries.0.reversal_reason', 'سُجّل بالخطأ');
        $this->actingAs($this->user)->postJson("/api/v1/assistance-deliveries/{$delivery->uuid}/reverse", ['reversal_reason' => 'مرة أخرى'])
            ->assertStatus(409);

        $delivery->refresh();
        $this->assertNotNull($delivery->reversed_at);
        $this->assertSame(1, AssistanceDelivery::count());

        // Awaiting again → a fresh, freshly verified delivery.
        $this->assertSame(1, $this->statistics($this->assistance)['awaiting_delivery']);
        $this->deliver($b, $this->delegate('800000011'))->assertCreated();
        $this->assertSame(2, AssistanceDelivery::count());
        $stats = $this->statistics($this->assistance);
        $this->assertSame(1, $stats['delivered']);
        $this->assertSame(1, $stats['reversed_deliveries']);

        $this->expectException(LogicException::class);
        $delivery->delete();
    }

    public function test_delivery_rows_are_immutable(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);
        $this->deliver($b, $this->personal())->assertCreated();

        $this->expectException(LogicException::class);
        AssistanceDelivery::first()->update(['notes' => 'تعديل']);
    }

    public function test_not_delivered_requires_reason_and_is_terminal(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);

        $this->actingAs($this->user)->postJson($this->url($b, 'not-delivered'), [])->assertUnprocessable()->assertJsonValidationErrors('not_delivered_reason');
        $this->actingAs($this->user)->postJson($this->url($b, 'not-delivered'), ['not_delivered_reason' => 'لم يحضر'])
            ->assertOk()->assertJsonPath('data.status', 'NOT_DELIVERED')->assertJsonPath('data.not_delivered_reason', 'لم يحضر');

        $this->deliver($b, $this->personal())->assertStatus(409);
        $this->actingAs($this->user)->postJson($this->url($b, 'not-delivered'), ['not_delivered_reason' => 'x'])->assertStatus(409);

        // A delivered beneficiary cannot be marked not delivered.
        $delivered = $this->approvedBeneficiary($this->assistance, $this->fullHousehold(['national_id' => '800000051'])['family']);
        $this->deliver($delivered, $this->personal('800000051'))->assertCreated();
        $this->actingAs($this->user)->postJson($this->url($delivered, 'not-delivered'), ['not_delivered_reason' => 'x'])->assertStatus(409);
    }

    public function test_delivery_permissions(): void
    {
        foreach (['SUPER_ADMIN' => true, 'ADMINISTRATOR' => true, 'SOCIAL_WORKER' => true, 'DATA_ENTRY' => true, 'REVIEWER' => false, 'REPORTS_VIEWER' => false, 'FAMILY_USER' => false] as $role => $allowed) {
            $id = '8001'.str_pad((string) crc32($role) % 100000, 5, '0', STR_PAD_LEFT);
            $b = $this->approvedBeneficiary($this->assistance, $this->household(['national_id' => $id])['family']);
            $response = $this->deliver($b, $this->personal($id), $this->user($role));
            $allowed ? $response->assertCreated() : $response->assertForbidden();
        }
    }

    public function test_delivery_activity_events(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);
        $this->deliver($b, $this->personal())->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/assistance-deliveries/'.AssistanceDelivery::first()->uuid.'/reverse', ['reversal_reason' => 'سبب عكس سري'])->assertOk();
        $other = $this->approvedBeneficiary($this->assistance, $this->household(['national_id' => '800000061'])['family']);
        $this->actingAs($this->user)->postJson($this->url($other, 'not-delivered'), ['not_delivered_reason' => 'سبب سري'])->assertOk();

        $this->assertSame(
            ['ASSISTANCE_NOMINEE_ADDED', 'ASSISTANCE_BENEFICIARY_APPROVED', 'ASSISTANCE_DELIVERED', 'ASSISTANCE_DELIVERY_REVERSED'],
            FamilyActivity::where('family_id', $h['family']->id)->orderBy('id')->pluck('event_type')->map->value->all()
        );
        $this->assertTrue(FamilyActivity::where('event_type', 'ASSISTANCE_NOT_DELIVERED')->exists());
        $this->assertSame(0, FamilyActivity::whereNotNull('metadata')->count());
        $raw = $this->actingAs($this->user)->getJson("/api/v1/families/{$h['family']->family_code}/activities")->getContent();
        $this->assertStringNotContainsString('سبب عكس سري', $raw);
    }

    public function test_failed_verification_writes_nothing(): void
    {
        $h = $this->fullHousehold();
        $b = $this->approvedBeneficiary($this->assistance, $h['family']);
        $before = FamilyActivity::count();

        $this->deliver($b, $this->delegate('800000013'))->assertUnprocessable();

        $this->assertSame(0, AssistanceDelivery::count());
        $this->assertSame($before, FamilyActivity::count());
    }

    // ---------------------------------------------------------------- statistics & completion

    public function test_internal_statistics(): void
    {
        $hs = collect(range(1, 5))->map(fn ($i) => $this->household(['national_id' => "80000070{$i}"]));
        $bs = $hs->map(fn ($h) => $this->nominate($this->assistance, $h['family']))->values();
        foreach ([0, 1, 2] as $i) {
            $this->approve($this->assistance, $bs[$i])->assertOk();
        }
        $this->reject($this->assistance, $bs[3])->assertOk();
        $this->deliver($bs[0]->fresh(), $this->personal('800000701'))->assertCreated();
        $this->actingAs($this->user)->postJson($this->url($bs[1]->fresh(), 'not-delivered'), ['not_delivered_reason' => 'x'])->assertOk();

        $stats = $this->statistics($this->assistance);
        $this->assertSame(4, $stats['target']);
        $this->assertSame(5, $stats['total_nominees']);
        $this->assertSame(1, $stats['pending_approval']);
        $this->assertSame(3, $stats['approved']);
        $this->assertSame(1, $stats['rejected']);
        $this->assertSame(1, $stats['awaiting_delivery']);
        $this->assertSame(1, $stats['delivered']);
        $this->assertSame(1, $stats['not_delivered']);
        $this->assertSame(25.0, (float) $stats['execution_percentage']);
    }

    public function test_internal_completion_rules(): void
    {
        $pending = $this->nominate($this->assistance, $this->household(['national_id' => '800000801'])['family']);
        $this->complete($this->assistance)->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->approve($this->assistance, $pending)->assertOk();
        // Approved but awaiting delivery still blocks.
        $this->complete($this->assistance)->assertUnprocessable();

        $this->deliver($pending->fresh(), $this->personal('800000801'))->assertCreated();
        $rejected = $this->nominate($this->assistance, $this->household()['family']);
        $this->reject($this->assistance, $rejected)->assertOk();
        $removed = $this->nominate($this->assistance, $this->household()['family']);
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$this->assistance->uuid}/nominees/{$removed->uuid}/remove")->assertOk();
        $notDelivered = $this->approvedBeneficiary($this->assistance, $this->household()['family']);
        $this->actingAs($this->user)->postJson($this->url($notDelivered, 'not-delivered'), ['not_delivered_reason' => 'x'])->assertOk();

        $this->complete($this->assistance)->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.completed_by.name', 'مدير تجريبي');
        $this->assertNotNull($this->assistance->fresh()->completed_at);
        $this->complete($this->assistance)->assertStatus(409);

        // Reversal remains available for correction; the program stays completed.
        $this->actingAs($this->user)->postJson('/api/v1/assistance-deliveries/'.AssistanceDelivery::first()->uuid.'/reverse', ['reversal_reason' => 'تصحيح'])->assertOk();
        $this->assertSame('COMPLETED', $this->assistance->fresh()->status->value);
    }
}
