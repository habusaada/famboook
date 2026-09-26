<?php

namespace Tests\Feature\Registry;

use App\Models\Family;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Server-side Family and People registries (docs/03 §93a): pagination,
 * plain "contains" search on codes and names, totals, permissions, privacy.
 */
class RegistrySearchTest extends TestCase
{
    use BuildsRegistryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();
    }

    /** 17 synthetic families: one distinctive, sixteen generic. */
    private function manyFamilies(): array
    {
        $target = $this->family('سلمى عبدالله القديرة', 'SYN-NID-0001');
        for ($i = 1; $i <= 16; $i++) {
            $this->family("رب أسرة رقم {$i}");
        }

        return $target;
    }

    // ------------------------------------------------------------ families

    public function test_families_are_paginated_server_side_with_true_totals(): void
    {
        $this->manyFamilies();
        Family::query()->latest('id')->first()->update(['status' => 'INACTIVE']);

        $page1 = $this->actingAs($this->staff)->getJson('/api/v1/families?per_page=10')->assertOk();
        $page1->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 17)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('summary.total', 17)
            ->assertJsonPath('summary.active', 16)
            ->assertJsonPath('summary.inactive', 1)
            ->assertJsonPath('summary.archived', 0);

        $page2 = $this->actingAs($this->staff)->getJson('/api/v1/families?per_page=10&page=2')->assertOk();
        $page2->assertJsonCount(7, 'data')->assertJsonPath('meta.current_page', 2);
        $this->assertEmpty(array_intersect(
            array_column($page1->json('data'), 'family_code'),
            array_column($page2->json('data'), 'family_code'),
        ));

        $this->actingAs($this->staff)->getJson('/api/v1/families?status=INACTIVE')->assertJsonPath('meta.total', 1);
        $this->actingAs($this->staff)->getJson('/api/v1/families?per_page=500')->assertStatus(422);
    }

    public function test_family_search_by_family_code_member_name_and_person_code(): void
    {
        $target = $this->manyFamilies();
        // A member (not the head) is findable too.
        $member = $this->addMember($target['family_code'], ['full_name' => 'ريتاج سلمى القديرة'])->assertCreated();
        $memberCode = $member->json('data.person_code');

        $byCode = $this->actingAs($this->staff)->getJson('/api/v1/families?search='.$target['family_code'])->assertOk();
        $byCode->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.family_code', $target['family_code']);
        // Case-insensitive.
        $this->actingAs($this->staff)->getJson('/api/v1/families?search='.strtolower($target['family_code']))
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.family_code', $target['family_code']);

        foreach (['القديرة', 'ريتاج', $target['person_code'], $memberCode] as $term) {
            $this->actingAs($this->staff)->getJson('/api/v1/families?search='.urlencode($term))
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.family_code', $target['family_code']);
        }

        $this->actingAs($this->staff)->getJson('/api/v1/families?search='.urlencode('لا يوجد مثل هذا الاسم'))
            ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        // Wildcards are literal: "%" matches nothing rather than everything.
        foreach (['%', '_', '!', '%!_'] as $wildcard) {
            $this->actingAs($this->staff)->getJson('/api/v1/families?search='.urlencode($wildcard))->assertOk()->assertJsonPath('meta.total', 0);
            $this->actingAs($this->staff)->getJson('/api/v1/people?search='.urlencode($wildcard))->assertOk()->assertJsonPath('meta.total', 0);
        }
    }

    public function test_family_search_paginates_its_results(): void
    {
        $this->manyFamilies();
        $response = $this->actingAs($this->staff)->getJson('/api/v1/families?search='.urlencode('رب أسرة رقم').'&per_page=5&page=4')->assertOk();

        $response->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 4)->assertJsonCount(1, 'data');
        // Page links keep the search.
        $this->assertStringContainsString('search=', $response->json('links.first'));
    }

    public function test_family_registry_requires_family_view_and_is_private(): void
    {
        $this->manyFamilies();
        Auth::forgetGuards();
        $this->getJson('/api/v1/families')->assertUnauthorized();
        $this->actingAs($this->user('FAMILY_USER'))->getJson('/api/v1/families')->assertForbidden();

        $response = $this->actingAs($this->user('REPORTS_VIEWER'))->getJson('/api/v1/families?search=القديرة')->assertOk();
        $this->assertNoLeak($response, ['SYN-NID-0001']);
    }

    // -------------------------------------------------------------- people

    public function test_people_registry_paginates_and_searches_by_code_and_name(): void
    {
        $target = $this->manyFamilies();

        $all = $this->actingAs($this->staff)->getJson('/api/v1/people?per_page=10')->assertOk();
        $all->assertJsonPath('meta.total', 17)->assertJsonCount(10, 'data');
        $this->actingAs($this->staff)->getJson('/api/v1/people?per_page=10&page=2')->assertJsonCount(7, 'data');

        $byCode = $this->actingAs($this->staff)->getJson('/api/v1/people?search='.$target['person_code'])->assertOk();
        $byCode->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.person_code', $target['person_code'])
            ->assertJsonPath('data.0.full_name', 'سلمى عبدالله القديرة')
            ->assertJsonPath('data.0.family.family_code', $target['family_code'])
            ->assertJsonPath('data.0.family.is_household_head', true)
            ->assertJsonPath('data.0.family.relationship.code', 'HEAD');

        $this->actingAs($this->staff)->getJson('/api/v1/people?search='.urlencode('عبدالله'))->assertJsonPath('meta.total', 1);
        $this->actingAs($this->staff)->getJson('/api/v1/people?search=nothing-matches')->assertJsonPath('meta.total', 0);
    }

    public function test_people_registry_handles_missing_birth_date_and_excludes_deleted_persons(): void
    {
        $target = $this->family('رب بلا تاريخ ميلاد');
        Person::where('person_code', $target['person_code'])->update(['birth_date' => null]);
        $deleted = $this->family('شخص محذوف');
        Person::where('person_code', $deleted['person_code'])->first()->delete();

        $response = $this->actingAs($this->staff)->getJson('/api/v1/people')->assertOk();
        $response->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.birth_date', null);
        $this->assertArrayNotHasKey('age', $response->json('data.0'), 'No age is fabricated server-side.');
    }

    public function test_people_registry_requires_person_view_and_gates_family_context(): void
    {
        $this->family('سلمى عبدالله القديرة', 'SYN-NID-0001');
        Auth::forgetGuards();
        $this->getJson('/api/v1/people')->assertUnauthorized();
        $this->actingAs($this->user('FAMILY_USER'))->getJson('/api/v1/people')->assertForbidden();

        $response = $this->actingAs($this->user('REPORTS_VIEWER'))->getJson('/api/v1/people')->assertOk();
        $this->assertSame(
            ['person_code', 'full_name', 'gender', 'birth_date', 'life_status', 'family'],
            array_keys($response->json('data.0')),
        );
        $this->assertNoLeak($response, ['SYN-NID-0001', 'mobile']);
    }
}
