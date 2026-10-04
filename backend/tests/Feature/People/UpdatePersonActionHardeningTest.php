<?php

namespace Tests\Feature\People;

use App\Actions\UpdatePersonAction;
use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Http\Requests\Api\V1\UpdatePersonRequest;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UpdatePersonAction writes only its own allow-list, whatever an internal
 * caller passes: never life status, death, the National ID, record status,
 * codes or audit columns. The supported corrections behave as before.
 * Synthetic data only.
 */
class UpdatePersonActionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function memberOfAFamily(array $person = []): Person
    {
        $person = Person::factory()->create(['national_id' => '123456789', 'life_status' => LifeStatus::UNKNOWN->value, ...$person]);
        FamilyMembership::factory()->create(['family_id' => Family::factory()->create()->id, 'person_id' => $person->id]);

        return $person;
    }

    public function test_lifecycle_identity_and_audit_fields_are_never_written(): void
    {
        $person = $this->memberOfAFamily();
        $before = $person->fresh()->only(['life_status', 'death_date', 'national_id', 'is_active', 'person_code', 'created_by', 'deleted_at']);
        $other = User::factory()->create();

        app(UpdatePersonAction::class)->handle($person, [
            'life_status' => LifeStatus::DECEASED->value,
            'death_date' => '2020-01-01',
            'national_id' => '987654321',
            'is_active' => false,
            'person_code' => 'PER-999999',
            'created_by' => $other->id,
            'deleted_at' => now(),
            'id' => 999999,
        ], null);

        $this->assertEquals($before, $person->fresh()->only(['life_status', 'death_date', 'national_id', 'is_active', 'person_code', 'created_by', 'deleted_at']));
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::PERSON_UPDATED)->count(), 'Nothing allowed changed: no activity.');
    }

    public function test_supported_fields_still_change_and_a_forbidden_field_in_the_same_call_is_dropped(): void
    {
        $person = $this->memberOfAFamily(['full_name' => 'اسم قديم', 'gender' => 'FEMALE', 'marital_status' => 'UNKNOWN']);

        app(UpdatePersonAction::class)->handle($person, [
            'full_name' => 'اسم جديد',
            'marital_status' => 'MARRIED',
            'birth_date' => '1990-02-03',
            'mobile' => '0591112223',
            'alternate_mobile' => '0561112223',
            'alternate_mobile_owner_relation' => 'أخ',
            'life_status' => LifeStatus::ALIVE->value,
        ], null);

        $fresh = $person->fresh();
        $this->assertSame('اسم جديد', $fresh->full_name);
        $this->assertSame('MARRIED', $fresh->marital_status->value);
        $this->assertSame('1990-02-03', $fresh->birth_date->toDateString());
        $this->assertSame('0591112223', $fresh->mobile);
        $this->assertSame('0561112223', $fresh->alternate_mobile);
        $this->assertSame('أخ', $fresh->alternate_mobile_owner_relation);
        $this->assertSame(LifeStatus::UNKNOWN, $fresh->life_status, 'UNKNOWN → ALIVE is ConfirmPersonAliveAction only.');
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::PERSON_UPDATED)->count());
    }

    public function test_the_allow_list_is_exactly_what_the_request_accepts(): void
    {
        $this->assertSame(
            ['full_name', 'gender', 'marital_status', 'birth_date', 'mobile', 'alternate_mobile', 'alternate_mobile_owner_relation'],
            UpdatePersonAction::FIELDS,
        );
        $requestFields = array_keys((new UpdatePersonRequest)->rules());
        $this->assertSame([], array_values(array_diff($requestFields, [...UpdatePersonAction::FIELDS, 'national_id'])));
    }
}
