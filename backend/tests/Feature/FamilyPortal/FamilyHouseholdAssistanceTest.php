<?php

namespace Tests\Feature\FamilyPortal;

use App\Actions\RecordDeliveryAction;
use App\Actions\ReverseDeliveryAction;
use App\Enums\ReceiptMode;
use App\Enums\UserPersonLinkStatus;
use App\Http\Controllers\Api\V1\Family\FamilyHouseholdController;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\AssistanceBeneficiaryList;
use App\Models\AssistanceBeneficiaryListEntry;
use App\Models\AssistanceCategory;
use App\Models\AssistanceDelivery;
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
use Database\Seeders\AssistanceCategorySeeder;
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
 * PWA-3B.7: GET /api/v1/family/household/assistance — what the signed-in
 * head's Family actually RECEIVED: non-reversed deliveries of INTERNAL
 * Assistances to its beneficiaries, each with the full package (docs/03
 * §47e). A nomination, an approval, a NOT_DELIVERED decision, a rejection, a
 * removal, a reversed delivery or an issued EXTERNAL list is never a receipt.
 * The Family comes only from the family.context boundary. Synthetic data
 * only.
 */
class FamilyHouseholdAssistanceTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/household/assistance';

    private const DELIVERY_KEYS = ['delivered_at', 'assistance', 'beneficiary', 'receipt_mode', 'recipient'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->seed(AssistanceCategorySeeder::class);
        $this->seed(NeedCategorySeeder::class);
        $this->useFamilyAuthKey();
    }

    private function fetch(User $as, string $uri = self::URI): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson($uri);
    }

    private function member(Family $family, string $name, string $relationship = 'SON', array $person = []): FamilyMembership
    {
        return FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => Person::factory()->create(['full_name' => $name, 'marital_status' => 'SINGLE', ...$person])->id,
            'relationship_type_id' => RelationshipType::where('code', $relationship)->value('id'),
        ]);
    }

    /** An OPEN INTERNAL Assistance with a package (items are created before it leaves DRAFT). */
    private function assistance(array $attributes = [], ?array $items = null): Assistance
    {
        $assistance = Assistance::create([
            'title' => $attributes['title'] ?? 'سلة غذائية',
            'assistance_category_id' => AssistanceCategory::where('code', $attributes['category'] ?? 'FOOD')->value('id'),
            'assistance_type' => $attributes['type'] ?? 'IN_KIND',
            'execution_mode' => $attributes['execution_mode'] ?? 'INTERNAL',
            'provider_name' => $attributes['provider_name'] ?? 'جهة تجريبية',
            'description' => 'وصف برنامج داخلي سري',
            'status' => 'DRAFT',
            'target_beneficiaries' => 500,
            'targeting_criteria' => ['displacement_status' => 'DISPLACED'],
        ]);
        foreach ($items ?? [['item_name' => 'أرز', 'quantity_per_beneficiary' => 5, 'unit' => 'كغ', 'unit_value' => 4.5, 'currency' => 'ILS']] as $i => $item) {
            $assistance->items()->create([...$item, 'sort_order' => $item['sort_order'] ?? $i]);
        }
        $assistance->forceFill(['status' => $attributes['status'] ?? 'OPEN', 'opened_at' => now()])->save();

        return $assistance;
    }

    private function beneficiary(Assistance $assistance, Family $family, ?Person $person = null, string $status = 'APPROVED', array $attributes = []): AssistanceBeneficiary
    {
        return AssistanceBeneficiary::create([
            'assistance_id' => $assistance->id,
            'family_id' => $family->id,
            'person_id' => $person?->id,
            'nomination_source' => 'TARGETING',
            'targeting_criteria' => ['displacement_status' => 'DISPLACED'],
            'status' => $status,
            'nominated_at' => now(),
            'approved_at' => in_array($status, ['APPROVED', 'NOT_DELIVERED'], true) ? now() : null,
            'rejected_at' => $status === 'REJECTED' ? now() : null,
            'rejection_reason' => $status === 'REJECTED' ? 'سبب رفض داخلي' : null,
            'not_delivered_at' => $status === 'NOT_DELIVERED' ? now() : null,
            'not_delivered_reason' => $status === 'NOT_DELIVERED' ? 'سبب عدم تسليم داخلي' : null,
            'removed_at' => $status === 'REMOVED' ? now() : null,
            ...$attributes,
        ]);
    }

    private function deliver(AssistanceBeneficiary $beneficiary, Person $original, ?Person $recipient = null, string $at = '2026-09-15 10:00:00', ?string $notes = null): AssistanceDelivery
    {
        return AssistanceDelivery::create([
            'assistance_beneficiary_id' => $beneficiary->id,
            'receipt_mode' => $recipient === null ? 'PERSONAL' : 'DELEGATE',
            'original_beneficiary_person_id' => $original->id,
            'recipient_person_id' => ($recipient ?? $original)->id,
            'delivered_at' => $at,
            'notes' => $notes,
        ]);
    }

    private function ref(FamilyMembership $membership): string
    {
        return HouseholdMemberReference::of((int) $membership->family_id, (int) $membership->id);
    }

    /** @return list<array<string, mixed>> */
    private function deliveries(TestResponse $response): array
    {
        return $response->assertOk()->json('data.deliveries');
    }

    // -------------------------------------------------------------- contract

    public function test_a_family_delivery_is_returned_with_exactly_the_approved_fields(): void
    {
        $head = $this->activatedHead();
        $assistance = $this->assistance(['title' => 'سلة رمضان']);
        $this->deliver($this->beneficiary($assistance, $head['family']), $head['person']);

        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame(['deliveries'], array_keys($response->json('data')));
        $this->assertSame([[
            'delivered_at' => '2026-09-15',
            'assistance' => [
                'title' => 'سلة رمضان', 'category' => ['code' => 'FOOD', 'name' => 'الغذاء'], 'type' => 'IN_KIND', 'provider_name' => 'جهة تجريبية',
                'items' => [['item_name' => 'أرز', 'quantity' => '5', 'unit' => 'كغ', 'unit_value' => '4.5', 'currency' => 'ILS']],
            ],
            'beneficiary' => null,
            'receipt_mode' => 'PERSONAL',
            'recipient' => ['full_name' => $head['person']->full_name, 'available' => true],
        ]], $response->json('data.deliveries'));
    }

    public function test_responses_are_never_cached(): void
    {
        $head = $this->activatedHead();

        $cache = (string) $this->fetch($head['user'])->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public function test_a_family_without_deliveries_gets_an_empty_list(): void
    {
        $head = $this->activatedHead();

        $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => ['deliveries' => []]]);
    }

    public function test_the_whole_package_is_returned_in_order_with_nulls_kept(): void
    {
        $head = $this->activatedHead();
        $assistance = $this->assistance(['type' => 'CASH'], [
            ['item_name' => 'مبلغ نقدي', 'quantity_per_beneficiary' => 1, 'unit' => null, 'unit_value' => 200, 'currency' => 'USD', 'sort_order' => 2],
            ['item_name' => 'قسيمة', 'quantity_per_beneficiary' => null, 'unit' => null, 'unit_value' => null, 'currency' => null, 'sort_order' => 1],
        ]);
        $this->deliver($this->beneficiary($assistance, $head['family']), $head['person']);

        $items = $this->deliveries($this->fetch($head['user']))[0]['assistance']['items'];

        $this->assertSame([
            ['item_name' => 'قسيمة', 'quantity' => null, 'unit' => null, 'unit_value' => null, 'currency' => null],
            ['item_name' => 'مبلغ نقدي', 'quantity' => '1', 'unit' => null, 'unit_value' => '200', 'currency' => 'USD'],
        ], $items);
    }

    public function test_several_deliveries_newest_first(): void
    {
        $head = $this->activatedHead();
        $this->deliver($this->beneficiary($this->assistance(['title' => 'أقدم']), $head['family']), $head['person'], at: '2026-01-10 10:00:00');
        $this->deliver($this->beneficiary($this->assistance(['title' => 'أحدث']), $head['family']), $head['person'], at: '2026-09-10 10:00:00');
        $this->deliver($this->beneficiary($this->assistance(['title' => 'وسط', 'type' => 'SERVICE']), $head['family']), $head['person'], at: '2026-05-10 10:00:00');

        $deliveries = $this->deliveries($this->fetch($head['user']));

        $this->assertSame(['أحدث', 'وسط', 'أقدم'], array_map(fn ($d) => $d['assistance']['title'], $deliveries));
        $this->assertSame('SERVICE', $deliveries[1]['assistance']['type']);
    }

    // ------------------------------------------------- beneficiary / recipient

    public function test_a_person_beneficiary_and_a_delegate_recipient(): void
    {
        $head = $this->activatedHead();
        $wife = $this->member($head['family'], 'زوجة تجريبية', 'SPOUSE', ['gender' => 'FEMALE', 'marital_status' => 'MARRIED']);
        $daughter = $this->member($head['family'], 'ابنة تجريبية', 'DAUGHTER', ['gender' => 'FEMALE']);
        $this->deliver($this->beneficiary($this->assistance(['title' => 'لشخص']), $head['family'], $wife->person), $wife->person, at: '2026-09-02 10:00:00');
        $this->deliver($this->beneficiary($this->assistance(['title' => 'بالنيابة']), $head['family']), $head['person'], $daughter->person, '2026-09-01 10:00:00');

        [$personal, $delegate] = $this->deliveries($this->fetch($head['user']));

        $this->assertSame(['member_ref' => $this->ref($wife), 'full_name' => 'زوجة تجريبية', 'available' => true], $personal['beneficiary']);
        $this->assertSame(['PERSONAL', 'زوجة تجريبية'], [$personal['receipt_mode'], $personal['recipient']['full_name']]);
        $this->assertNull($delegate['beneficiary']);
        $this->assertSame(['DELEGATE', 'ابنة تجريبية'], [$delegate['receipt_mode'], $delegate['recipient']['full_name']]);
    }

    public function test_history_survives_a_head_change_a_member_leaving_or_dying(): void
    {
        $head = $this->activatedHead();
        $former = $this->member($head['family'], 'فرد سابق');
        $late = $this->member($head['family'], 'فرد متوفى');
        $this->deliver($this->beneficiary($this->assistance(['title' => 'لعضو سابق']), $head['family'], $former->person), $former->person, at: '2026-03-01 10:00:00');
        $this->deliver($this->beneficiary($this->assistance(['title' => 'لعضو متوفى']), $head['family'], $late->person), $late->person, at: '2026-02-01 10:00:00');
        $previousHead = $this->member($head['family'], 'رب أسرة سابق', 'OTHER');
        $this->deliver($this->beneficiary($this->assistance(['title' => 'للأسرة سابقًا']), $head['family']), $previousHead->person, at: '2026-01-01 10:00:00');
        $former->forceFill(['is_active' => false, 'ended_at' => now()])->save();
        $previousHead->forceFill(['is_active' => false, 'ended_at' => now()])->save();
        $late->person->forceFill(['life_status' => 'DECEASED'])->save();

        $deliveries = collect($this->deliveries($this->fetch($head['user'])))->keyBy(fn ($d) => $d['assistance']['title']);

        $this->assertCount(3, $deliveries);
        $this->assertSame(['member_ref' => null, 'full_name' => 'فرد سابق', 'available' => true], $deliveries['لعضو سابق']['beneficiary']);
        $this->assertSame(['member_ref' => $this->ref($late), 'full_name' => 'فرد متوفى', 'available' => true], $deliveries['لعضو متوفى']['beneficiary']);
        $this->assertNull($deliveries['للأسرة سابقًا']['beneficiary']);
        $this->assertSame(['full_name' => 'رب أسرة سابق', 'available' => true], $deliveries['للأسرة سابقًا']['recipient']);
    }

    public function test_a_soft_deleted_beneficiary_and_recipient_are_unavailable_and_unnamed(): void
    {
        $head = $this->activatedHead();
        $gone = $this->member($head['family'], 'فرد محذوف');
        $this->deliver($this->beneficiary($this->assistance(), $head['family'], $gone->person), $gone->person);
        $gone->person->delete();

        $response = $this->fetch($head['user']);
        $delivery = $this->deliveries($response)[0];

        $this->assertSame(['member_ref' => null, 'full_name' => null, 'available' => false], $delivery['beneficiary']);
        $this->assertSame(['full_name' => null, 'available' => false], $delivery['recipient']);
        $this->assertStringNotContainsString('فرد محذوف', $response->getContent());
    }

    public function test_a_delivery_of_a_completed_program_stays_visible(): void
    {
        $head = $this->activatedHead();
        $assistance = $this->assistance(['title' => 'برنامج مكتمل']);
        $this->deliver($this->beneficiary($assistance, $head['family']), $head['person']);
        $assistance->forceFill(['status' => 'COMPLETED', 'completed_at' => now()])->save();

        $this->assertSame('برنامج مكتمل', $this->deliveries($this->fetch($head['user']))[0]['assistance']['title']);
    }

    // -------------------------------------------- never a receipt / reversal

    public function test_no_workflow_state_without_an_active_delivery_is_a_receipt(): void
    {
        $head = $this->activatedHead();
        foreach (['NOMINATED', 'APPROVED', 'NOT_DELIVERED', 'REJECTED', 'REMOVED'] as $status) {
            $this->beneficiary($this->assistance(['title' => "حالة {$status}"]), $head['family'], status: $status);
        }

        $response = $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => ['deliveries' => []]]);
        $this->assertStringNotContainsString('حالة', $response->getContent());
    }

    public function test_a_reversed_delivery_disappears_and_a_redelivery_appears_once(): void
    {
        $head = $this->activatedHead();
        $beneficiary = $this->beneficiary($this->assistance(['title' => 'سلة معكوسة']), $head['family']);
        $first = $this->deliver($beneficiary, $head['person'], at: '2026-09-01 10:00:00');
        $first->forceFill(['reversed_at' => now(), 'reversal_reason' => 'سبب عكس داخلي'])->save();

        $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => ['deliveries' => []]]);

        $this->deliver($beneficiary, $head['person'], at: '2026-09-05 10:00:00');
        $deliveries = $this->deliveries($this->fetch($head['user']));

        $this->assertCount(1, $deliveries);
        $this->assertSame('2026-09-05', $deliveries[0]['delivered_at']);
    }

    public function test_the_real_delivery_and_reversal_actions_follow_the_same_rule(): void
    {
        $head = $this->activatedHead('123456789');
        $beneficiary = $this->beneficiary($this->assistance(['title' => 'تسليم فعلي']), $head['family']);

        $delivery = app(RecordDeliveryAction::class)->handle($beneficiary->assistance, $beneficiary, ReceiptMode::PERSONAL, '123456789', null, 'ملاحظة تسليم داخلية', null);
        $this->assertSame(['تسليم فعلي'], array_map(fn ($d) => $d['assistance']['title'], $this->deliveries($this->fetch($head['user']))));

        app(ReverseDeliveryAction::class)->handle($delivery, 'سبب عكس داخلي', null);
        $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => ['deliveries' => []]]);
    }

    public function test_an_issued_external_list_is_never_a_receipt(): void
    {
        $head = $this->activatedHead();
        $assistance = $this->assistance(['title' => 'برنامج خارجي', 'execution_mode' => 'EXTERNAL']);
        $beneficiary = $this->beneficiary($assistance, $head['family']);
        $list = AssistanceBeneficiaryList::create([
            'assistance_id' => $assistance->id, 'list_number' => 'ABL-000001', 'recipient_organization' => 'جهة خارجية',
            'issued_at' => now(), 'configuration_snapshot' => [], 'contains_sensitive' => false, 'row_count' => 1,
        ]);
        AssistanceBeneficiaryListEntry::create([
            'assistance_beneficiary_list_id' => $list->id, 'assistance_beneficiary_id' => $beneficiary->id, 'row_number' => 1, 'snapshot_data' => '{}',
        ]);

        $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => ['deliveries' => []]]);
    }

    // ------------------------------------------------------------- isolation

    public function test_another_family_is_never_included_whatever_the_request_says(): void
    {
        $head = $this->activatedHead('123456789');
        $other = $this->activatedHead('222222222');
        $this->deliver($this->beneficiary($this->assistance(['title' => 'مساعدة أسرة أخرى']), $other['family']), $other['person']);

        $response = $this->fetch($head['user'], self::URI.'?family='.$other['family']->family_code.'&person_id='.$other['person']->id)
            ->assertOk()->assertExactJson(['data' => ['deliveries' => []]]);

        $this->assertStringNotContainsString('أسرة أخرى', $response->getContent());
    }

    public function test_coordinator_scope_never_widens_it(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 1);
        $this->assign($head['user'], $clan);
        $assignedHead = FamilyMembership::where('family_id', $assigned->id)->firstOrFail()->person;
        $this->deliver($this->beneficiary($this->assistance(['title' => 'مساعدة ضمن النطاق']), $assigned), $assignedHead);

        $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => ['deliveries' => []]]);
    }

    // ------------------------------------------------------------- privacy

    public function test_no_internal_field_reaches_the_client(): void
    {
        $head = $this->activatedHead();
        $staff = User::factory()->create(['name' => 'موظف التسليم']);
        $need = FamilyNeed::create([
            'family_id' => $head['family']->id, 'need_category_id' => NeedCategory::where('code', 'FOOD')->value('id'),
            'title' => 'احتياج مصدر', 'priority' => 'HIGH', 'status' => 'OPEN',
        ]);
        $son = $this->member($head['family'], 'ابن تجريبي');
        $assistance = $this->assistance();
        $beneficiary = $this->beneficiary($assistance, $head['family'], $son->person, attributes: [
            'nomination_source' => 'NEED', 'source_need_id' => $need->id, 'nominated_by' => $staff->id, 'approved_by' => $staff->id,
        ]);
        $delivery = $this->deliver($beneficiary, $son->person, notes: 'ملاحظة تسليم داخلية');
        $delivery->forceFill(['delivered_by' => $staff->id])->saveQuietly();

        $response = $this->fetch($head['user']);
        $raw = $response->getContent();

        $delivered = $this->deliveries($response)[0];
        $this->assertSame(self::DELIVERY_KEYS, array_keys($delivered));
        $this->assertSame(['title', 'category', 'type', 'provider_name', 'items'], array_keys($delivered['assistance']));
        foreach (['وصف برنامج داخلي سري', 'ملاحظة تسليم داخلية', 'موظف التسليم', 'احتياج مصدر', 'DISPLACED', 'APPROVED', 'TARGETING',
            $delivery->uuid, $beneficiary->uuid, $assistance->uuid, $son->person->person_code, $head['family']->family_code] as $value) {
            $this->assertStringNotContainsString((string) $value, $raw);
        }
        foreach (['"id"', 'uuid', 'person_id', 'person_code', 'original_beneficiary', 'recipient_person', 'execution_mode', '"status"',
            'target_beneficiaries', 'start_date', 'end_date', 'description', 'targeting', 'export_fields', 'nomination', 'source_need',
            'approved', 'rejected', 'not_delivered', 'removed', 'notes', 'delivered_by', 'reversed', 'reversal', 'list', 'sort_order',
            'created_by', 'updated_by'] as $key) {
            $this->assertStringNotContainsString($key, $raw, $key);
        }
    }

    public function test_reading_writes_nothing(): void
    {
        $head = $this->activatedHead();
        $this->deliver($this->beneficiary($this->assistance(), $head['family']), $head['person']);
        $counts = fn () => [AuthSecurityEvent::count(), FamilyActivity::count(), AssistanceDelivery::count(), AssistanceDelivery::max('updated_at')];
        $before = $counts();

        $this->fetch($head['user'])->assertOk();

        $this->assertSame($before, $counts());
    }

    public function test_the_route_takes_no_parameter(): void
    {
        $route = Route::getRoutes()->getByAction(FamilyHouseholdController::class.'@assistance');

        $this->assertSame('api/v1/family/household/assistance', $route->uri());
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
        $this->deliver($this->beneficiary($this->assistance(['title' => 'مساعدة حساب مختلط']), $mixed['family']), $mixed['person']);
        $mixed['user']->assignRole('ADMINISTRATOR');
        $accounts['mixed staff and family'] = $mixed['user'];

        foreach ($accounts as $label => $user) {
            $response = $this->fetch($user)->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
            $this->assertStringNotContainsString('مساعدة', $response->getContent(), $label);
        }
    }

    public function test_a_lost_family_context_gets_the_generic_403(): void
    {
        $head = $this->activatedHead();
        $this->deliver($this->beneficiary($this->assistance(['title' => 'مساعدة مستلمة']), $head['family']), $head['person']);
        $this->fetch($head['user'])->assertOk();

        $head['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();

        $response = $this->fetch($head['user'])->assertForbidden()
            ->assertExactJson(['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE]);
        $this->assertStringNotContainsString('مساعدة مستلمة', $response->getContent());
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
