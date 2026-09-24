<?php

namespace Tests\Feature\Assistances;

use App\Enums\AssistanceStatus;
use App\Models\Assistance;
use App\Models\AssistanceCategory;
use App\Models\AssistanceItem;
use App\Models\FamilyActivity;
use Database\Seeders\AssistanceCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

/**
 * Assistance V1-A: reference data, program definition, planned items,
 * opening and permissions (docs/03-BUSINESS-RULES.md §47a).
 */
class AssistanceDefinitionTest extends TestCase
{
    use BuildsAssistanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssistanceFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- reference data

    public function test_fourteen_assistance_categories_are_seeded(): void
    {
        $this->assertSame(
            ['SHELTER', 'FOOD', 'WATER', 'HYGIENE', 'HEALTHCARE', 'MEDICATION', 'ASSISTIVE_DEVICE',
                'EDUCATION', 'CASH', 'CLOTHING', 'CHILDCARE', 'PROTECTION', 'LIVELIHOOD', 'OTHER'],
            AssistanceCategory::orderBy('sort_order')->pluck('code')->all()
        );
        $this->assertSame('المأوى والسكن', AssistanceCategory::where('code', 'SHELTER')->value('name'));
    }

    public function test_category_seeder_is_idempotent_and_never_reactivates(): void
    {
        AssistanceCategory::where('code', 'CLOTHING')->update(['is_active' => false]);

        $this->seed(AssistanceCategorySeeder::class);
        $this->seed(AssistanceCategorySeeder::class);

        $this->assertSame(14, AssistanceCategory::count());
        $this->assertFalse(AssistanceCategory::where('code', 'CLOTHING')->first()->is_active);
    }

    public function test_reference_endpoint_returns_active_categories_only(): void
    {
        AssistanceCategory::where('code', 'CASH')->update(['is_active' => false]);

        $data = $this->actingAs($this->user)->getJson('/api/v1/reference/assistance-categories')->assertOk()->json('data');

        $this->assertCount(13, $data);
        $this->assertNotContains('CASH', array_column($data, 'code'));
        $this->assertArrayNotHasKey('id', $data[0]);

        foreach (['REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/v1/reference/assistance-categories')->assertForbidden();
        }
        $this->actingAs($this->user('SOCIAL_WORKER'))->getJson('/api/v1/reference/assistance-categories')->assertOk();
    }

    // ---------------------------------------------------------------- assistance

    public function test_create_draft_with_items(): void
    {
        $response = $this->createAssistance()->assertCreated();

        $response->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.title', 'حزمة إيواء طارئة')
            ->assertJsonPath('data.category.code', 'SHELTER')
            ->assertJsonPath('data.assistance_type', 'IN_KIND')
            ->assertJsonPath('data.provider_name', 'مبادرة مجتمعية تجريبية')
            ->assertJsonPath('data.target_beneficiaries', 100)
            ->assertJsonPath('data.start_date', '2026-10-01')
            ->assertJsonPath('data.end_date', '2026-10-31')
            ->assertJsonPath('data.nominee_count', 0)
            ->assertJsonPath('data.items.0.item_name', 'فرشة')
            ->assertJsonPath('data.items.0.quantity_per_beneficiary', '4')
            ->assertJsonPath('data.items.1.item_name', 'بطانية')
            ->assertJsonPath('data.created_by.name', 'مدير تجريبي')
            ->assertJsonPath('abilities.update_definition', true)
            ->assertJsonPath('abilities.open', true)
            ->assertJsonPath('abilities.nominate', false);

        $this->assertSame($this->user->id, Assistance::first()->created_by);
        // Program definitions are not family events.
        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_type_and_category_are_validated(): void
    {
        $this->createAssistance(['assistance_type' => 'LOAN'])->assertUnprocessable()->assertJsonValidationErrors('assistance_type');
        $this->createAssistance(['category_code' => 'NOPE'])->assertUnprocessable()->assertJsonValidationErrors('category_code');

        AssistanceCategory::where('code', 'FOOD')->update(['is_active' => false]);
        $this->createAssistance(['category_code' => 'FOOD'])->assertUnprocessable()->assertJsonValidationErrors('category_code');

        foreach (['IN_KIND', 'CASH', 'SERVICE'] as $type) {
            $this->createAssistance(['assistance_type' => $type])->assertCreated();
        }
    }

    public function test_provider_and_title_are_required(): void
    {
        $this->createAssistance(['provider_name' => null])->assertUnprocessable()->assertJsonValidationErrors('provider_name');
        $this->createAssistance(['provider_name' => ''])->assertUnprocessable()->assertJsonValidationErrors('provider_name');
        $this->createAssistance(['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame(0, Assistance::count());
    }

    public function test_target_count_validation(): void
    {
        foreach ([0, -5, 'abc', 1.5] as $target) {
            $this->createAssistance(['target_beneficiaries' => $target])->assertUnprocessable()->assertJsonValidationErrors('target_beneficiaries');
        }
        $this->createAssistance(['target_beneficiaries' => null])->assertCreated()->assertJsonPath('data.target_beneficiaries', null);
    }

    public function test_date_validation(): void
    {
        $this->createAssistance(['start_date' => '2026-10-10', 'end_date' => '2026-10-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->createAssistance(['start_date' => '2026-10-10', 'end_date' => '2026-10-10'])->assertCreated();
        $this->createAssistance(['start_date' => null, 'end_date' => '2026-10-10'])->assertCreated();
        $this->createAssistance(['start_date' => '10/10/2026'])->assertUnprocessable()->assertJsonValidationErrors('start_date');

        // Partial update is checked against the stored date.
        $id = $this->createAssistance()->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$id}", ['end_date' => '2026-09-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_status_cannot_be_set_from_payload(): void
    {
        $this->createAssistance(['status' => 'OPEN'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_draft_is_fully_editable(): void
    {
        $id = $this->createAssistance()->json('data.id');

        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$id}", [
            'title' => 'حزمة إيواء معدلة',
            'category_code' => 'HEALTHCARE',
            'assistance_type' => 'SERVICE',
            'provider_name' => 'جهة أخرى',
            'targeting_criteria' => ['min_family_members' => 5, 'displacement_status' => 'DISPLACED'],
        ])->assertOk()
            ->assertJsonPath('data.title', 'حزمة إيواء معدلة')
            ->assertJsonPath('data.category.code', 'HEALTHCARE')
            ->assertJsonPath('data.assistance_type', 'SERVICE')
            ->assertJsonPath('data.targeting_criteria.min_family_members', 5)
            ->assertJsonPath('data.targeting_criteria.displacement_status', 'DISPLACED');
    }

    public function test_open_assistance_records_opener_and_locks_definition(): void
    {
        $id = $this->createAssistance()->json('data.id');

        Carbon::setTestNow('2026-09-24 11:00:00');
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$id}/open")
            ->assertOk()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.opened_by.name', 'مدير تجريبي')
            ->assertJsonPath('abilities.update_definition', false)
            ->assertJsonPath('abilities.nominate', true);

        $assistance = Assistance::first();
        $this->assertSame('2026-09-24 11:00:00', $assistance->opened_at->toDateTimeString());

        // Conservative editing once OPEN.
        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$id}", ['description' => 'وصف محدث', 'target_beneficiaries' => 150])
            ->assertOk()->assertJsonPath('data.target_beneficiaries', 150);
        foreach ([['title' => 'x'], ['category_code' => 'FOOD'], ['items' => []], ['provider_name' => 'x'], ['targeting_criteria' => []]] as $locked) {
            $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$id}", $locked)->assertStatus(409);
        }

        // DRAFT → OPEN only once.
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$id}/open")->assertStatus(409);
        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_cannot_open_without_items(): void
    {
        $id = $this->createAssistance(['items' => []])->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$id}/open")
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(AssistanceStatus::DRAFT, Assistance::first()->status);
    }

    public function test_list_filters_and_derived_nominee_count(): void
    {
        $this->createAssistance();
        $this->createAssistance(['title' => 'نقدية', 'category_code' => 'CASH', 'assistance_type' => 'CASH',
            'items' => [['item_name' => 'مساعدة نقدية', 'quantity_per_beneficiary' => 1, 'unit' => 'دفعة', 'unit_value' => 500, 'currency' => 'ILS']]]);
        $opened = $this->openAssistance(['title' => 'مفتوحة']);
        $family = $this->family();
        $this->actingAs($this->user)->postJson("/api/v1/assistances/{$opened->uuid}/nominees/manual", ['family_code' => $family->family_code])->assertCreated();

        $all = $this->actingAs($this->user)->getJson('/api/v1/assistances')->assertOk();
        $this->assertCount(3, $all->json('data'));
        $this->assertSame('مفتوحة', $all->json('data.0.title'));
        $all->assertJsonPath('data.0.nominee_count', 1);

        $titles = fn (string $qs) => array_column($this->actingAs($this->user)->getJson("/api/v1/assistances?{$qs}")->json('data'), 'title');
        $this->assertSame(['مفتوحة'], $titles('status=OPEN'));
        $this->assertSame(['نقدية'], $titles('type=CASH'));
        $this->assertSame(['نقدية'], $titles('category=CASH'));
        $this->actingAs($this->user)->getJson('/api/v1/assistances?status=DONE')->assertUnprocessable();
    }

    // ---------------------------------------------------------------- items

    public function test_single_item_and_cash_item_with_value(): void
    {
        $this->createAssistance(['items' => [
            ['item_name' => 'مساعدة نقدية', 'quantity_per_beneficiary' => 1, 'unit' => 'دفعة', 'unit_value' => 500, 'currency' => 'ILS'],
        ]])->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.unit_value', '500')
            ->assertJsonPath('data.items.0.currency', 'ILS');
    }

    public function test_multiple_items_keep_their_order(): void
    {
        $response = $this->createAssistance(['items' => [
            ['item_name' => 'فرشة', 'quantity_per_beneficiary' => 4, 'unit' => 'قطعة'],
            ['item_name' => 'بطانية', 'quantity_per_beneficiary' => 4, 'unit' => 'قطعة'],
            ['item_name' => 'شادر', 'quantity_per_beneficiary' => 1, 'unit' => 'قطعة'],
        ]])->assertCreated();

        $this->assertSame(['فرشة', 'بطانية', 'شادر'], array_column($response->json('data.items'), 'item_name'));
        $this->assertSame(3, AssistanceItem::count());
    }

    public function test_item_quantity_validation(): void
    {
        foreach ([0, -1, 'abc', 1.234] as $qty) {
            $this->createAssistance(['items' => [['item_name' => 'فرشة', 'quantity_per_beneficiary' => $qty]]])
                ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity_per_beneficiary');
        }
        $this->createAssistance(['items' => [['item_name' => '']]])->assertUnprocessable()->assertJsonValidationErrors('items.0.item_name');
        $this->createAssistance(['items' => [['item_name' => 'جلسة', 'quantity_per_beneficiary' => null, 'unit' => null]]])->assertCreated();
    }

    public function test_item_value_and_currency_validation(): void
    {
        $item = fn (array $extra) => ['items' => [['item_name' => 'مساعدة نقدية', ...$extra]]];

        $this->createAssistance($item(['unit_value' => 500]))->assertUnprocessable()->assertJsonValidationErrors('items.0.currency');
        $this->createAssistance($item(['currency' => 'ILS']))->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_value');
        $this->createAssistance($item(['unit_value' => 0, 'currency' => 'ILS']))->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_value');
        $this->createAssistance($item(['unit_value' => 500, 'currency' => 'GBP']))->assertUnprocessable()->assertJsonValidationErrors('items.0.currency');

        foreach (['ILS', 'USD', 'JOD', 'EUR'] as $currency) {
            $this->createAssistance($item(['unit_value' => 12.5, 'currency' => $currency]))->assertCreated();
        }
        $this->assertSame(0, AssistanceItem::whereNull('currency')->whereNotNull('unit_value')->count());
    }

    public function test_draft_items_can_be_edited_and_removed(): void
    {
        $id = $this->createAssistance()->json('data.id');

        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$id}", ['items' => [
            ['item_name' => 'فرشة', 'quantity_per_beneficiary' => 2, 'unit' => 'قطعة'],
            ['item_name' => 'شادر', 'quantity_per_beneficiary' => 1, 'unit' => 'قطعة'],
        ]])->assertOk()
            ->assertJsonPath('data.items.0.quantity_per_beneficiary', '2')
            ->assertJsonPath('data.items.1.item_name', 'شادر');
        $this->assertSame(2, AssistanceItem::count());

        // Unchanged items do not count as an edit.
        $before = Assistance::first()->updated_at;
        Carbon::setTestNow('2026-09-24 12:00:00');
        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$id}", ['items' => [
            ['item_name' => 'فرشة', 'quantity_per_beneficiary' => 2, 'unit' => 'قطعة'],
            ['item_name' => 'شادر', 'quantity_per_beneficiary' => 1, 'unit' => 'قطعة'],
        ]])->assertOk();
        $this->assertTrue($before->equalTo(Assistance::first()->updated_at));

        $this->actingAs($this->user)->patchJson("/api/v1/assistances/{$id}", ['items' => []])->assertOk()->assertJsonCount(0, 'data.items');
    }

    // ---------------------------------------------------------------- permissions

    public function test_permission_matrix_for_definition(): void
    {
        $expect = [
            'SUPER_ADMIN' => ['create' => true, 'open' => true],
            'ADMINISTRATOR' => ['create' => true, 'open' => true],
            'DATA_ENTRY' => ['create' => true, 'open' => false],
            'SOCIAL_WORKER' => ['create' => false, 'open' => false],
            'REVIEWER' => ['create' => false, 'open' => false],
        ];

        foreach ($expect as $role => $can) {
            $user = $this->user($role);
            $this->actingAs($user)->getJson('/api/v1/assistances')->assertOk();

            $create = $this->createAssistance([], $user);
            $can['create'] ? $create->assertCreated() : $create->assertForbidden();

            $draft = $this->createAssistance()->json('data.id');
            $this->actingAs($user)->getJson("/api/v1/assistances/{$draft}")->assertOk();
            $update = $this->actingAs($user)->patchJson("/api/v1/assistances/{$draft}", ['description' => 'x']);
            $can['create'] ? $update->assertOk() : $update->assertForbidden();
            $open = $this->actingAs($user)->postJson("/api/v1/assistances/{$draft}/open");
            $can['open'] ? $open->assertOk() : $open->assertForbidden();
        }
    }

    public function test_reports_viewer_and_family_user_are_denied(): void
    {
        $draft = $this->createAssistance()->json('data.id');

        foreach (['REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->getJson('/api/v1/assistances')->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/assistances/{$draft}")->assertForbidden();
            $this->createAssistance([], $user)->assertForbidden();
            $this->actingAs($user)->patchJson("/api/v1/assistances/{$draft}", ['description' => 'x'])->assertForbidden();
            $this->actingAs($user)->postJson("/api/v1/assistances/{$draft}/open")->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/assistances/{$draft}/nominees")->assertForbidden();
            $this->preview(Assistance::first(), [], $user)->assertForbidden();
        }
    }

    public function test_no_delete_endpoint_and_model_refuses_delete(): void
    {
        $id = $this->createAssistance()->json('data.id');
        $this->actingAs($this->user('SUPER_ADMIN'))->deleteJson("/api/v1/assistances/{$id}")->assertStatus(405);

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'assistance')) {
                $this->assertNotContains('DELETE', $route->methods(), $route->uri());
            }
        }

        $this->expectException(LogicException::class);
        Assistance::first()->delete();
    }

    public function test_responses_have_no_internal_ids(): void
    {
        $id = $this->createAssistance()->json('data.id');
        $raw = $this->actingAs($this->user)->getJson("/api/v1/assistances/{$id}")->getContent();

        foreach (['assistance_category_id', 'assistance_id', 'created_by":{"id', 'email'] as $key) {
            $this->assertStringNotContainsString($key, $raw);
        }
        $this->assertStringNotContainsString('"id":'.Assistance::first()->id.',', $raw);
    }
}
