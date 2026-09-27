<?php

namespace Tests\Feature\Corrections;

use App\Enums\FamilyActivityType;
use App\Exceptions\DuplicateNationalIdException;
use App\Models\FamilyActivity;
use App\Models\Person;
use App\Support\NationalIdMask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Registry\BuildsRegistryFixtures;
use Tests\TestCase;

/**
 * Administrative National ID correction and masked view (AUTH-ADR-059):
 * SUPER_ADMIN and ADMINISTRATOR only; the full stored value never leaves
 * the server; the duplicate guard stays active; the activity carries no
 * value. All values are synthetic.
 */
class NationalIdCorrectionTest extends TestCase
{
    use BuildsRegistryFixtures;
    use RefreshDatabase;

    private const OLD = 'SYN-400111222';

    private const NEW = 'SYN-400333444';

    private const TAKEN = 'SYN-400555666';

    /** @var array{family_code: string, person_code: string} */
    private array $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();
        $this->family = $this->family('رب أسرة الهوية', self::OLD);
    }

    private function storedId(?string $personCode = null): ?string
    {
        return Person::where('person_code', $personCode ?? $this->family['person_code'])->value('national_id');
    }

    /** @param array<string, mixed>|null $body */
    private function correct(?string $role, ?array $body = null, ?string $personCode = null): TestResponse
    {
        $body ??= ['national_id' => self::NEW, 'national_id_confirmation' => self::NEW];
        $request = $role === null ? $this->asGuest() : $this->actingAs($this->user($role));

        return $request->putJson('/api/v1/people/'.($personCode ?? $this->family['person_code']).'/national-id', $body);
    }

    private function assertNoFullId(TestResponse $response): void
    {
        foreach ([self::OLD, self::NEW, self::TAKEN] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
    }

    public function test_mask_helper_is_central_and_never_reveals_the_full_value(): void
    {
        $this->assertSame('*****6789', NationalIdMask::mask('123456789'));
        $this->assertSame('*****3444', NationalIdMask::mask(self::NEW));
        $this->assertSame('*****34', NationalIdMask::mask('1234'));
        $this->assertSame('*****', NationalIdMask::mask('7'));
        $this->assertNull(NationalIdMask::mask(null));
        $this->assertNull(NationalIdMask::mask('   '));
    }

    public function test_super_admin_and_administrator_can_correct_the_national_id(): void
    {
        $this->correct('SUPER_ADMIN')
            ->assertOk()
            ->assertJsonPath('data.national_id_masked', '*****3444')
            ->assertJsonMissingPath('data.national_id');
        $this->assertSame(self::NEW, $this->storedId());

        $this->correct('ADMINISTRATOR', ['national_id' => self::OLD, 'national_id_confirmation' => self::OLD])
            ->assertOk()
            ->assertJsonPath('data.national_id_masked', '*****1222');
        $this->assertSame(self::OLD, $this->storedId());
    }

    public function test_other_roles_cannot_correct_the_national_id(): void
    {
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->correct($role)->assertForbidden();
        }
        $this->correct(null)->assertUnauthorized();

        $this->assertSame(self::OLD, $this->storedId());
    }

    public function test_masked_view_only_for_authorized_roles_and_never_the_full_value(): void
    {
        $url = "/api/v1/people/{$this->family['person_code']}";

        foreach (['SUPER_ADMIN', 'ADMINISTRATOR'] as $role) {
            $response = $this->actingAs($this->user($role))->getJson($url)
                ->assertOk()
                ->assertJsonPath('data.national_id_masked', '*****1222')
                ->assertJsonMissingPath('data.national_id');
            $this->assertNoFullId($response);
        }

        // HIDDEN for everyone else: the key is absent, so even whether a
        // National ID exists is not revealed.
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $response = $this->actingAs($this->user($role))->getJson($url)
                ->assertOk()
                ->assertJsonMissingPath('data.national_id_masked')
                ->assertJsonMissingPath('data.national_id');
            $this->assertNoFullId($response);
        }

        // Not recorded → null (only visible to masked-view holders).
        $withoutId = $this->family('رب بلا هوية');
        $this->actingAs($this->user('ADMINISTRATOR'))->getJson("/api/v1/people/{$withoutId['person_code']}")
            ->assertOk()->assertJsonPath('data.national_id_masked', null);
    }

    public function test_no_api_response_prefills_or_leaks_the_full_national_id(): void
    {
        $admin = $this->user('ADMINISTRATOR');
        $member = $this->addMember($this->family['family_code'], ['national_id' => self::TAKEN])->json('data.person_code');

        foreach ([
            "/api/v1/people/{$this->family['person_code']}",
            "/api/v1/people/{$member}",
            '/api/v1/people',
            '/api/v1/people?search='.urlencode('رب أسرة الهوية'),
            "/api/v1/families/{$this->family['family_code']}",
            '/api/v1/families',
            "/api/v1/families/{$this->family['family_code']}/activities",
        ] as $url) {
            $this->assertNoFullId($this->actingAs($admin)->getJson($url)->assertOk());
        }

        // Nor the correction response itself.
        $this->assertNoFullId($this->correct('ADMINISTRATOR')->assertOk());
    }

    public function test_duplicate_replacement_is_refused_by_the_national_id_guard(): void
    {
        $other = $this->family('رب أسرة أخرى', self::TAKEN);

        $response = $this->correct('ADMINISTRATOR', ['national_id' => self::TAKEN, 'national_id_confirmation' => self::TAKEN])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['national_id' => [DuplicateNationalIdException::MESSAGE]])
            ->assertJsonPath('duplicate.matches.0.person_code', $other['person_code']);
        $this->assertNoFullId($response);

        $this->assertSame(self::OLD, $this->storedId());
        $this->assertSame(self::TAKEN, $this->storedId($other['person_code']));
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::NATIONAL_ID_CORRECTED)->count());
    }

    public function test_blank_or_unconfirmed_replacement_never_erases_the_national_id(): void
    {
        foreach ([
            [],
            ['national_id' => '', 'national_id_confirmation' => ''],
            ['national_id' => null, 'national_id_confirmation' => null],
            ['national_id' => '   ', 'national_id_confirmation' => '   '],
            ['national_id' => self::NEW],
            ['national_id' => self::NEW, 'national_id_confirmation' => 'SYN-400333445'],
            ['national_id' => str_repeat('9', 51), 'national_id_confirmation' => str_repeat('9', 51)],
        ] as $body) {
            $this->assertNoFullId(
                $this->correct('SUPER_ADMIN', $body)->assertStatus(422)->assertJsonValidationErrors(['national_id'])
            );
        }

        $this->assertSame(self::OLD, $this->storedId());
    }

    public function test_generic_person_update_cannot_change_or_clear_the_national_id(): void
    {
        foreach ([['national_id' => self::NEW], ['national_id' => null]] as $body) {
            $this->actingAs($this->user('ADMINISTRATOR'))
                ->patchJson("/api/v1/people/{$this->family['person_code']}", $body)
                ->assertStatus(422)->assertJsonValidationErrors(['national_id']);
        }

        $this->assertSame(self::OLD, $this->storedId());
    }

    public function test_correction_records_an_activity_without_any_national_id(): void
    {
        $this->correct('ADMINISTRATOR')->assertOk();
        // Re-entering the same value is not a change and not an activity.
        $this->correct('ADMINISTRATOR')->assertOk();

        $events = FamilyActivity::where('event_type', FamilyActivityType::NATIONAL_ID_CORRECTED)->get();
        $this->assertCount(1, $events);
        $this->assertNull($events->first()->metadata);
        $this->assertSame(Person::where('person_code', $this->family['person_code'])->value('id'), $events->first()->subject_id);

        // Neither value anywhere in the stored activity rows.
        $raw = json_encode(DB::table('family_activities')->get());
        $this->assertStringNotContainsString(self::OLD, $raw);
        $this->assertStringNotContainsString(self::NEW, $raw);

        $timeline = $this->actingAs($this->staff)->getJson("/api/v1/families/{$this->family['family_code']}/activities")
            ->assertOk()->assertJsonPath('data.0.event_type', 'NATIONAL_ID_CORRECTED');
        $this->assertNoFullId($timeline);
    }

    public function test_correction_changes_only_the_national_id(): void
    {
        $before = Person::where('person_code', $this->family['person_code'])->sole()->makeHidden(['national_id', 'updated_at', 'updated_by'])->toArray();

        $this->correct('ADMINISTRATOR')->assertOk();

        $after = Person::where('person_code', $this->family['person_code'])->sole()->makeHidden(['national_id', 'updated_at', 'updated_by'])->toArray();
        $this->assertSame($before, $after);
        $this->assertSame(1, Person::count());
    }

    public function test_the_correction_is_rate_limited(): void
    {
        $admin = $this->user('ADMINISTRATOR');
        for ($i = 0; $i < 30; $i++) {
            $value = "SYN-RATE-{$i}";
            $this->actingAs($admin)->putJson("/api/v1/people/{$this->family['person_code']}/national-id", [
                'national_id' => $value, 'national_id_confirmation' => $value,
            ])->assertOk();
        }

        $this->actingAs($admin)->putJson("/api/v1/people/{$this->family['person_code']}/national-id", [
            'national_id' => self::NEW, 'national_id_confirmation' => self::NEW,
        ])->assertStatus(429);
    }
}
