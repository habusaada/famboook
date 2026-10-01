<?php

namespace Tests\Feature\Import;

use App\Enums\FamilyKeyDecision;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\ImportFamilyKeyResolution;
use App\Models\ImportRow;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\SyntheticXlsx;
use Tests\TestCase;

/**
 * Import Wizard step 4 — family-key resolution (docs/03 §96a): one explicit
 * decision per (batch, exact source key); unresolved ≠ NO_BRANCH; Branches
 * are created only by an explicit clan.manage decision, ungrouped, in the
 * batch's Clan. Source keys are never rewritten; nothing is inferred, fuzzy
 * matched or copied from other batches; no Family/Person/Membership is made.
 */
class FamilyKeyResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/imports/initial-families';

    private const HEADERS = ['المفتاح', 'رقم الهوية', 'الاسم'];

    private Clan $clan;

    private Clan $other;

    private User $admin;

    private string $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = $this->user('SUPER_ADMIN');
        $this->clan = Clan::create(['code' => 'SYN_TARGET', 'name' => 'عشيرة الهدف']);
        $this->other = Clan::create(['code' => 'SYN_OTHER', 'name' => 'عشيرة أخرى']);

        $this->batch = $this->stage([
            2 => ['البريم', 900000001, 'رب أسرة 1'],
            3 => ['البريم', 900000002, 'رب أسرة 2'],
            4 => ['البريم', 900000003, 'رب أسرة أبو سعادة'],   // name suffix must never matter
            5 => ['أبو سعادة', 900000004, 'رب أسرة 4'],
            6 => ['أبو سعادة', 900000005, 'رب أسرة 5'],
            7 => ['ابو سعادة', 900000006, 'رب أسرة 6'],         // spelling variant
            8 => ['قديح', 900000007, 'ربة أسرة أرملة'],        // appears once
            9 => [null, 900000008, 'رب أسرة بلا مفتاح'],       // blank key
        ]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function stage(array $rows, string $clan = 'SYN_TARGET', string $mode = 'INITIAL'): string
    {
        $path = SyntheticXlsx::write(['Sheet1' => [1 => self::HEADERS] + $rows]);
        $id = $this->actingAs($this->admin)->post(self::BASE, [
            'clan_code' => $clan, 'import_mode' => $mode,
            'file' => new UploadedFile($path, 'synthetic.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/mapping", [
            'mapping' => ['source_family_key' => 'A', 'national_id' => 'B', 'full_name' => 'C'], 'ignored' => [],
        ])->assertOk();

        return $id;
    }

    private function resolve(array $body, ?User $as = null, ?string $batch = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->putJson(self::BASE.'/'.($batch ?? $this->batch).'/family-keys/resolution', $body);
    }

    private function keys(?string $batch = null): TestResponse
    {
        return $this->actingAs($this->admin)->getJson(self::BASE.'/'.($batch ?? $this->batch).'/family-keys')->assertOk();
    }

    private function resolutionOf(string $key): ?array
    {
        return collect($this->keys()->json('data'))->firstWhere('key', $key)['resolution'];
    }

    private function branch(string $code, string $name, ?Clan $clan = null, array $attrs = []): Branch
    {
        return Branch::create(['clan_id' => ($clan ?? $this->clan)->id, 'branch_group_id' => null, 'code' => $code, 'name' => $name, ...$attrs]);
    }

    private function sourceKeys(): array
    {
        return ImportRow::orderBy('row_number')->pluck('source_family_key', 'row_number')->all();
    }

    // ------------------------------------------------ 1-3, 28. unit of resolution

    public function test_1_2_3_28_one_decision_per_exact_key_and_source_keys_are_untouched(): void
    {
        $before = $this->sourceKeys();
        $response = $this->keys();
        $this->assertSame(4, $response->json('meta.resolution.distinct_keys'));   // blank key excluded
        $this->assertSame(4, $response->json('meta.resolution.unresolved_keys'));
        $this->assertSame(3, collect($response->json('data'))->firstWhere('key', 'البريم')['row_count']);

        $this->resolve(['source_family_key' => 'البريم', 'decision' => 'NO_BRANCH'])->assertOk();
        $this->resolve(['source_family_key' => 'البريم', 'decision' => 'NO_BRANCH'])->assertOk();

        // One record for the key, covering its three rows.
        $this->assertSame(1, ImportFamilyKeyResolution::count());
        $this->assertSame(3, ImportRow::where('source_family_key', ImportFamilyKeyResolution::sole()->source_family_key)->count());
        $this->assertSame($before, $this->sourceKeys());

        // Blank-key rows never get a (fake) resolution.
        $this->resolve(['source_family_key' => '', 'decision' => 'NO_BRANCH'])->assertUnprocessable();
        $this->resolve(['source_family_key' => 'مفتاح غير موجود', 'decision' => 'NO_BRANCH'])->assertUnprocessable()->assertJsonValidationErrors('source_family_key');
    }

    // ------------------------------------------------ 4-7. existing Branch

    public function test_4_5_6_7_existing_branch_must_be_selectable_in_the_batch_clan(): void
    {
        $good = $this->branch('BREEM', 'البريم');
        $foreign = $this->branch('FOREIGN', 'البريم', $this->other);
        $inactive = $this->branch('OFF', 'فرع موقوف', attrs: ['is_active' => false]);
        $group = BranchGroup::create(['clan_id' => $this->clan->id, 'code' => 'G1', 'is_active' => false]);
        $inInactiveGroup = $this->branch('IN_OFF_GROUP', 'فرع في مجموعة موقوفة', attrs: ['branch_group_id' => $group->id]);

        $this->resolve(['source_family_key' => 'البريم', 'decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $foreign->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        foreach ([$inactive, $inInactiveGroup] as $bad) {
            $this->resolve(['source_family_key' => 'البريم', 'decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $bad->uuid])
                ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        }
        $this->assertSame(0, ImportFamilyKeyResolution::count());

        // The selector only offers selectable Branches of the batch's Clan.
        $this->assertSame(['BREEM'], array_column($this->keys()->json('meta.branches'), 'code'));

        $this->resolve(['source_family_key' => 'البريم', 'decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $good->uuid])->assertOk();
        $r = $this->resolutionOf('البريم');
        $this->assertSame('MATCH_EXISTING_BRANCH', $r['decision']);
        $this->assertSame('BREEM', $r['branch']['code']);

        // The database also refuses a cross-Clan Branch.
        $this->expectException(QueryException::class);
        DB::table('import_family_key_resolutions')->where('source_family_key', 'البريم')->update(['branch_id' => $foreign->id]);
    }

    // ------------------------------------------------ 8-9, 27. NO_BRANCH

    public function test_8_9_27_no_branch_is_explicit_resolved_and_distinct_from_unresolved(): void
    {
        $this->assertNull($this->resolutionOf('قديح'));   // unresolved = no decision

        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'NO_BRANCH'])->assertOk()
            ->assertJsonPath('meta.resolution.no_branch', 1)
            ->assertJsonPath('meta.resolution.resolved_keys', 1)
            ->assertJsonPath('meta.resolution.unresolved_keys', 3);

        $r = $this->resolutionOf('قديح');
        $this->assertSame('NO_BRANCH', $r['decision']);
        $this->assertNull($r['branch']);
    }

    // ------------------------------------------------ 10-15. create Branch

    public function test_10_to_15_creating_a_branch_makes_exactly_one_ungrouped_branch_and_nothing_else(): void
    {
        $before = [BranchGroup::count(), Family::count(), Person::count(), FamilyMembership::count()];

        $this->resolve(['source_family_key' => 'البريم', 'decision' => 'CREATE_NEW_BRANCH', 'branch' => ['name' => 'البريم', 'code' => 'AL_BREEM_MAIN']])
            ->assertOk()->assertJsonPath('meta.resolution.created_branch', 1);

        $branch = Branch::sole();
        $this->assertSame([$this->clan->id, null, 'AL_BREEM_MAIN', 'البريم'], [$branch->clan_id, $branch->branch_group_id, $branch->code, $branch->name]);
        $this->assertSame('AL_BREEM_MAIN', $this->resolutionOf('البريم')['branch']['code']);
        $this->assertSame($before, [BranchGroup::count(), Family::count(), Person::count(), FamilyMembership::count()]);
        // Available immediately for other keys.
        $this->assertSame(['AL_BREEM_MAIN'], array_column($this->keys()->json('meta.branches'), 'code'));
    }

    public function test_branch_creation_validates_code_name_and_duplicates(): void
    {
        $this->branch('EXISTING', 'البريم');

        $this->resolve(['source_family_key' => 'البريم', 'decision' => 'CREATE_NEW_BRANCH', 'branch' => ['name' => 'البريم', 'code' => 'NEW_CODE']])
            ->assertUnprocessable()->assertJsonValidationErrors('branch.name');   // same name → link instead
        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'CREATE_NEW_BRANCH', 'branch' => ['name' => 'قديح', 'code' => 'EXISTING']])
            ->assertUnprocessable()->assertJsonValidationErrors('branch.code');
        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'CREATE_NEW_BRANCH', 'branch' => ['name' => 'قديح', 'code' => 'bad code']])
            ->assertUnprocessable()->assertJsonValidationErrors('branch.code');
        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'CREATE_NEW_BRANCH', 'branch' => ['name' => '', 'code' => 'QDEH']])
            ->assertUnprocessable()->assertJsonValidationErrors('branch.name');

        $this->assertSame(1, Branch::count());
        $this->assertSame(0, ImportFamilyKeyResolution::count());
    }

    // ------------------------------------------------ 16-17. authorization

    public function test_16_17_reviewers_without_clan_manage_cannot_create_branches(): void
    {
        // A role holding import.review but not clan.manage (constructed for the test).
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('import.review');
        $existing = $this->branch('BREEM', 'البريم');

        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'CREATE_NEW_BRANCH', 'branch' => ['name' => 'قديح', 'code' => 'QDEH']], $reviewer)->assertForbidden();
        $this->assertSame(1, Branch::count());

        $this->resolve(['source_family_key' => 'البريم', 'decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $existing->uuid], $reviewer)->assertOk();
        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'NO_BRANCH'], $reviewer)->assertOk();
        $this->actingAs($reviewer)->getJson(self::BASE."/{$this->batch}/family-keys")->assertOk()->assertJsonPath('meta.can_create_branch', false);

        foreach (['ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER'] as $role) {
            $this->resolve(['source_family_key' => 'قديح', 'decision' => 'NO_BRANCH'], $this->user($role))->assertForbidden();
        }
    }

    // ------------------------------------------------ 18-23. audit, edit, persistence

    public function test_18_to_23_decisions_are_audited_editable_and_persist(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 29)->setTime(10, 0));
        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'CREATE_NEW_BRANCH', 'branch' => ['name' => 'قديح', 'code' => 'QDEH']])->assertOk();
        $created = Branch::sole();
        $record = ImportFamilyKeyResolution::sole();
        $this->assertSame($this->admin->id, $record->resolved_by);
        $this->assertSame('2026-09-29 10:00:00', $record->resolved_at->format('Y-m-d H:i:s'));

        // Later the administrator re-decides: map to another existing Branch.
        $this->travelTo(now()->addHour());
        $other = $this->branch('BREEM', 'البريم');
        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $other->uuid])->assertOk();

        $record->refresh();
        $this->assertSame(FamilyKeyDecision::MATCH_EXISTING_BRANCH, $record->decision);
        $this->assertSame($other->id, $record->branch_id);
        $this->assertSame('2026-09-29 11:00:00', $record->resolved_at->format('Y-m-d H:i:s'));
        // The Branch created earlier is kept, not deleted.
        $this->assertTrue(Branch::whereKey($created->id)->exists());
        $this->assertSame('قديح', ImportRow::where('row_number', 8)->value('source_family_key'));

        // Re-fetching (refresh/reopen) returns the saved decision.
        $this->assertSame('BREEM', $this->resolutionOf('قديح')['branch']['code']);
        $this->assertSame($this->admin->name, $this->resolutionOf('قديح')['resolved_by']['name']);

        // Clearing returns to UNRESOLVED; the Branch still stays.
        $this->actingAs($this->admin)->postJson(self::BASE."/{$this->batch}/family-keys/resolution/clear", ['source_family_key' => 'قديح'])->assertOk()
            ->assertJsonPath('meta.resolution.unresolved_keys', 4);
        $this->assertNull($this->resolutionOf('قديح'));
        $this->assertSame(2, Branch::count());
    }

    // ------------------------------------------------ spelling variants

    public function test_spelling_variants_can_explicitly_share_one_branch_without_chains(): void
    {
        $this->resolve(['source_family_key' => 'أبو سعادة', 'decision' => 'CREATE_NEW_BRANCH', 'branch' => ['name' => 'أبو سعادة', 'code' => 'ABU_SAADA']])->assertOk();
        $this->resolve(['source_family_key' => 'ابو سعادة', 'decision' => 'SAME_BRANCH_AS_KEY', 'same_as_key' => 'أبو سعادة'])->assertOk()
            ->assertJsonPath('meta.resolution.same_branch_as_key', 1);

        $variant = $this->resolutionOf('ابو سعادة');
        $this->assertSame('ABU_SAADA', $variant['branch']['code']);
        $this->assertSame('أبو سعادة', $variant['reference_source_key']);
        $this->assertSame(1, Branch::count());
        // Neither source key is rewritten.
        $this->assertSame(['أبو سعادة', 'أبو سعادة', 'ابو سعادة'], ImportRow::whereIn('row_number', [5, 6, 7])->orderBy('row_number')->pluck('source_family_key')->all());

        // Changing the first key later does NOT cascade: the variant keeps its final Branch.
        $this->resolve(['source_family_key' => 'أبو سعادة', 'decision' => 'NO_BRANCH'])->assertOk();
        $this->assertSame('ABU_SAADA', $this->resolutionOf('ابو سعادة')['branch']['code']);

        // No self reference, no unresolved/NO_BRANCH source.
        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'SAME_BRANCH_AS_KEY', 'same_as_key' => 'قديح'])->assertUnprocessable()->assertJsonValidationErrors('same_as_key');
        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'SAME_BRANCH_AS_KEY', 'same_as_key' => 'البريم'])->assertUnprocessable()->assertJsonValidationErrors('same_as_key');
        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'SAME_BRANCH_AS_KEY', 'same_as_key' => 'أبو سعادة'])->assertUnprocessable()->assertJsonValidationErrors('same_as_key');
    }

    // ------------------------------------------------ 24-27. counts & readiness

    public function test_24_25_26_counts_and_step_5_readiness(): void
    {
        $summary = fn () => $this->actingAs($this->admin)->getJson(self::BASE."/{$this->batch}")->assertOk()->json('data.summary.key_resolution');
        $this->assertFalse($summary()['complete']);

        $existing = $this->branch('BREEM', 'البريم');
        $this->resolve(['source_family_key' => 'البريم', 'decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $existing->uuid]);
        $this->resolve(['source_family_key' => 'أبو سعادة', 'decision' => 'CREATE_NEW_BRANCH', 'branch' => ['name' => 'أبو سعادة', 'code' => 'ABU_SAADA']]);
        $this->resolve(['source_family_key' => 'ابو سعادة', 'decision' => 'SAME_BRANCH_AS_KEY', 'same_as_key' => 'أبو سعادة']);
        $this->assertFalse($summary()['complete']);   // one key still unresolved

        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'NO_BRANCH']);
        $this->assertSame([
            'distinct_keys' => 4, 'resolved_keys' => 4, 'unresolved_keys' => 0, 'match_existing' => 1,
            'created_branch' => 1, 'same_branch_as_key' => 1, 'no_branch' => 1, 'complete' => true,
        ], $summary());
    }

    // ------------------------------------------------ 29-32. no automatic resolution

    public function test_29_to_32_suggestions_never_resolve_and_nothing_is_inferred(): void
    {
        $this->branch('BREEM', 'البريم');
        $this->branch('SAADA', 'أبو سعادة');
        $keys = collect($this->keys()->json('data'))->keyBy('key');

        // Exact match is only a suggestion; nothing was saved.
        $this->assertSame('BREEM', $keys['البريم']['existing_branch']['code']);
        $this->assertNull($keys['البريم']['resolution']);
        // Exact only: the variant gets no suggestion; the name suffix of row 4 is irrelevant.
        $this->assertNull($keys['ابو سعادة']['existing_branch']);
        $this->assertSame(0, ImportFamilyKeyResolution::count());
        $this->assertSame(4, $this->keys()->json('meta.resolution.unresolved_keys'));
    }

    // ------------------------------------------------ bulk

    public function test_bulk_actions_apply_only_to_selected_keys_all_or_nothing(): void
    {
        $bulk = fn (array $body, ?User $as = null) => $this->actingAs($as ?? $this->admin)->postJson(self::BASE."/{$this->batch}/family-keys/bulk", $body);

        // One invalid item fails the whole request.
        $bulk(['decision' => 'CREATE_NEW_BRANCH', 'items' => [
            ['source_family_key' => 'البريم', 'name' => 'البريم', 'code' => 'BULK_1'],
            ['source_family_key' => 'قديح', 'name' => 'قديح', 'code' => 'bad code'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.code');
        $bulk(['decision' => 'CREATE_NEW_BRANCH', 'items' => [
            ['source_family_key' => 'البريم', 'name' => 'البريم', 'code' => 'SAME'],
            ['source_family_key' => 'قديح', 'name' => 'قديح', 'code' => 'SAME'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.code');
        $this->assertSame([0, 0], [Branch::count(), ImportFamilyKeyResolution::count()]);

        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('import.review');
        $bulk(['decision' => 'CREATE_NEW_BRANCH', 'items' => [['source_family_key' => 'قديح', 'name' => 'قديح', 'code' => 'QDEH']]], $reviewer)->assertForbidden();

        $bulk(['decision' => 'CREATE_NEW_BRANCH', 'items' => [
            ['source_family_key' => 'البريم', 'name' => 'البريم', 'code' => 'BULK_1'],
            ['source_family_key' => 'أبو سعادة', 'name' => 'أبو سعادة', 'code' => 'BULK_2'],
        ]])->assertOk()->assertJsonPath('meta.resolution.created_branch', 2)->assertJsonPath('meta.resolution.unresolved_keys', 2);
        $this->assertSame(['BULK_1', 'BULK_2'], Branch::orderBy('code')->pluck('code')->all());
        $this->assertNull(Branch::whereNotNull('branch_group_id')->first());

        $bulk(['decision' => 'NO_BRANCH', 'items' => [['source_family_key' => 'قديح'], ['source_family_key' => 'ابو سعادة']]])
            ->assertOk()->assertJsonPath('meta.resolution.complete', true);
    }

    // ------------------------------------------------ staging interplay

    public function test_restaging_keeps_decisions_for_keys_that_remain(): void
    {
        $this->resolve(['source_family_key' => 'قديح', 'decision' => 'NO_BRANCH'])->assertOk();
        // Re-confirm a mapping: the same keys are staged again.
        $this->actingAs($this->admin)->postJson(self::BASE."/{$this->batch}/mapping", [
            'mapping' => ['source_family_key' => 'A', 'national_id' => 'B', 'full_name' => 'C'], 'ignored' => [],
        ])->assertOk();
        $this->assertSame('NO_BRANCH', $this->resolutionOf('قديح')['decision']);

        // A different key column: old keys disappear, so do their decisions.
        $this->actingAs($this->admin)->postJson(self::BASE."/{$this->batch}/mapping", [
            'mapping' => ['source_family_key' => 'C', 'national_id' => 'B', 'full_name' => 'A'], 'ignored' => [],
        ])->assertOk();
        $this->assertSame(0, ImportFamilyKeyResolution::count());
    }

    // ------------------------------------------------ 33-36. non-goals

    public function test_33_to_36_no_apply_no_import_apply_and_batches_stay_independent(): void
    {
        $this->resolve(['source_family_key' => 'البريم', 'decision' => 'CREATE_NEW_BRANCH', 'branch' => ['name' => 'البريم', 'code' => 'BREEM']])->assertOk();

        // A later INCREMENTAL batch with the same key starts UNRESOLVED (nothing copied).
        $later = $this->stage([2 => ['البريم', 900000009, 'رب أسرة جديد']], mode: 'INCREMENTAL');
        $keys = $this->keys($later);
        $this->assertNull($keys->json('data.0.resolution'));
        $this->assertSame('BREEM', $keys->json('data.0.existing_branch.code'));   // suggestion only
        $this->assertSame(1, $keys->json('meta.resolution.unresolved_keys'));
        $this->assertSame(1, ImportFamilyKeyResolution::count());

        // The Apply gate is closed by default: even SUPER_ADMIN is refused.
        $this->actingAs($this->admin)->postJson(self::BASE."/{$this->batch}/apply/start", ['plan_fingerprint' => str_repeat('a', 64)])->assertForbidden();
        foreach (Role::all() as $role) {
            $this->assertFalse($role->hasPermissionTo('import.apply'), $role->name);
        }
        $this->assertSame(0, Family::count());
        $this->assertSame(0, Person::count());
    }
}
