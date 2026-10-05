<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\FingerprintContext;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\KeyedFingerprint;
use App\Support\FamilyPortal\HouseholdMemberReference;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FU-13: the opaque household-member reference `member_ref` — the full keyed
 * HMAC-SHA256 of a FAMILY MEMBERSHIP (FingerprintContext::MEMBER_REF, Family
 * Auth key and versions). It is never an id, never stored, and never
 * authorization: it resolves only among the ACTIVE memberships of the Family
 * the family.context boundary resolved. Synthetic data only.
 */
class HouseholdMemberReferenceTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/household/members';

    private const FORMAT = '/\A[0-9a-f]{64}\z/';

    private const TEST_KEY = 'test-only-family-auth-key-0123456789-abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function fetch(User $as): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson(self::URI);
    }

    private function context(User $user): FamilyAccessResult
    {
        return app(FamilyAccessResolver::class)->familyContext($user->fresh());
    }

    /** An active non-head member of the given household. */
    private function member(array $head, array $person = []): FamilyMembership
    {
        return FamilyMembership::factory()->create([
            'family_id' => $head['family']->id, 'person_id' => Person::factory()->create($person)->id,
        ]);
    }

    private function ref(FamilyMembership $membership): string
    {
        return HouseholdMemberReference::of((int) $membership->family_id, (int) $membership->id);
    }

    // -------------------------------------------------------------- payload

    public function test_every_active_row_has_a_distinct_full_length_reference_that_is_stable(): void
    {
        $head = $this->activatedHead();
        $members = [$head['membership'], $this->member($head), $this->member($head)];
        $deleted = $this->member($head);
        $deleted->person->delete();

        $rows = $this->fetch($head['user'])->assertOk()->json('data.members');
        $refs = array_column($rows, 'member_ref');

        $this->assertCount(4, $refs);
        foreach ($refs as $ref) {
            $this->assertMatchesRegularExpression(self::FORMAT, $ref);
        }
        $this->assertCount(4, array_unique($refs), 'Every membership has its own reference.');
        // The placeholder keeps its reference: its membership is still part of the household.
        $this->assertContains($this->ref($deleted), $refs);
        foreach ($members as $membership) {
            $this->assertContains($this->ref($membership), $refs);
        }
        // Stable across requests (recomputed, never stored).
        $this->assertSame($refs, array_column($this->fetch($head['user'])->json('data.members'), 'member_ref'));
    }

    public function test_the_reference_exposes_no_id_code_or_sensitive_value(): void
    {
        $head = $this->activatedHead('123456789');
        $member = $this->member($head, ['national_id' => '807766554', 'mobile' => '0591122334']);

        $response = $this->fetch($head['user'])->assertOk();
        $row = collect($response->json('data.members'))->firstWhere('member_ref', $this->ref($member));

        $this->assertNotNull($row);
        foreach ([$member->id, $member->person_id, $member->family_id] as $id) {
            foreach ([(string) $id, dechex($id), str_pad((string) $id, 64, '0', STR_PAD_LEFT), str_pad(dechex($id), 64, '0', STR_PAD_LEFT)] as $form) {
                $this->assertNotSame($form, $row['member_ref']);
            }
        }
        $body = $response->getContent();
        foreach ([$member->person->person_code, $head['person']->person_code, '807766554', '0591122334', '123456789', '"id"', 'membership_id', 'person_id', 'family_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
        // Masking unchanged.
        $this->assertSame('*****6554', $row['national_id_masked']);
        $this->assertSame('05*****334', $row['mobile_masked']);
    }

    public function test_the_member_ref_context_is_domain_separated_from_every_other_fingerprint(): void
    {
        $head = $this->activatedHead();
        $value = $head['family']->id.':'.$head['membership']->id;
        $ref = $this->ref($head['membership']);

        foreach ([FingerprintContext::LOGIN_ID, FingerprintContext::MOBILE, FingerprintContext::OTP_CODE] as $other) {
            $this->assertNotSame(KeyedFingerprint::of($other, $value), $ref, $other->name);
        }
        $this->assertSame(KeyedFingerprint::of(FingerprintContext::MEMBER_REF, $value), $ref);
        // Bound to the family as well as the membership.
        $this->assertNotSame(HouseholdMemberReference::of($head['family']->id + 1, $head['membership']->id), $ref);
    }

    // ------------------------------------------------------------- resolver

    public function test_a_same_family_reference_resolves_to_its_active_membership(): void
    {
        $head = $this->activatedHead();
        $member = $this->member($head);
        $context = $this->context($head['user']);

        $this->assertTrue(HouseholdMemberReference::resolve($context, $this->ref($member))?->is($member));
        $this->assertTrue(HouseholdMemberReference::resolve($context, $this->ref($head['membership']))?->is($head['membership']));
    }

    public function test_an_unavailable_persons_active_membership_still_resolves(): void
    {
        $head = $this->activatedHead();
        $member = $this->member($head);
        $member->person->delete();

        $this->assertTrue(HouseholdMemberReference::resolve($this->context($head['user']), $this->ref($member))?->is($member));
    }

    public function test_a_reference_of_another_household_never_resolves(): void
    {
        $mine = $this->activatedHead('111111111');
        $other = $this->activatedHead('222222222');
        $otherMember = $this->member($other);
        $context = $this->context($mine['user']);

        $this->assertNull(HouseholdMemberReference::resolve($context, $this->ref($otherMember)));
        $this->assertNull(HouseholdMemberReference::resolve($context, $this->ref($other['membership'])));
        // Nor with the foreign membership claimed under my family id.
        $this->assertNull(HouseholdMemberReference::resolve($context, HouseholdMemberReference::of($mine['family']->id, $otherMember->id)));
    }

    public function test_an_ended_membership_stops_resolving_and_a_new_membership_has_a_new_reference(): void
    {
        $head = $this->activatedHead();
        $member = $this->member($head);
        $old = $this->ref($member);
        $member->forceFill(['is_active' => false, 'ended_at' => now()->toDateString(), 'end_reason' => 'synthetic'])->save();

        $this->assertNull(HouseholdMemberReference::resolve($this->context($head['user']), $old));

        $again = FamilyMembership::factory()->create(['family_id' => $head['family']->id, 'person_id' => $member->person_id]);
        $new = $this->ref($again);
        $this->assertNotSame($old, $new);
        $this->assertTrue(HouseholdMemberReference::resolve($this->context($head['user']), $new)?->is($again));
        $this->assertNull(HouseholdMemberReference::resolve($this->context($head['user']), $old));
    }

    /** @return array<string, array{0: string}> */
    public static function malformed(): array
    {
        return [
            'empty' => [''],
            'too short' => [str_repeat('a', 63)],
            'too long' => [str_repeat('a', 65)],
            'not hex' => [str_repeat('g', 64)],
            'with spaces' => [' '.str_repeat('a', 62).' '],
            'a numeric id' => ['1'],
            'random 64 hex' => [str_repeat('0123456789abcdef', 4)],
        ];
    }

    #[DataProvider('malformed')]
    public function test_malformed_or_random_references_resolve_to_null(string $ref): void
    {
        $head = $this->activatedHead();
        $this->member($head);

        $this->assertNull(HouseholdMemberReference::resolve($this->context($head['user']), $ref));
    }

    public function test_uppercase_is_not_normalized(): void
    {
        $head = $this->activatedHead();
        $member = $this->member($head);

        $this->assertNull(HouseholdMemberReference::resolve($this->context($head['user']), strtoupper($this->ref($member))));
    }

    public function test_a_lost_family_context_resolves_nothing(): void
    {
        $head = $this->activatedHead();
        $ref = $this->ref($head['membership']);
        $head['membership']->forceFill(['is_household_head' => false])->save();

        $context = $this->context($head['user']);

        $this->assertFalse($context->hasFamilyContext());
        $this->assertNull(HouseholdMemberReference::resolve($context, $ref));
    }

    public function test_coordinator_scope_never_widens_resolution(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 3);
        $this->assign($head['user'], $clan);
        $context = $this->context($head['user']);

        foreach (FamilyMembership::where('family_id', $assigned->id)->get() as $membership) {
            $this->assertNull(HouseholdMemberReference::resolve($context, $this->ref($membership)));
        }
        $this->assertTrue(HouseholdMemberReference::resolve($context, $this->ref($head['membership']))?->is($head['membership']));
    }

    // ------------------------------------------------------------- rotation

    public function test_an_old_reference_resolves_during_the_rotation_overlap_and_not_after(): void
    {
        $head = $this->activatedHead();
        $member = $this->member($head);
        $old = $this->ref($member);

        // Rotate: a new current key (version 2) with the old one as previous.
        config(['family_auth.fingerprint' => [
            'key' => 'test-only-family-auth-key-rotated-0123456789-xyz', 'key_version' => 2,
            'previous_key' => self::TEST_KEY, 'previous_key_version' => 1,
        ]]);
        $new = $this->ref($member);
        $this->assertNotSame($old, $new);
        $this->assertSame($new, collect($this->fetch($head['user'])->assertOk()->json('data.members'))->pluck('member_ref')->last());

        // The context is resolved with the identity re-keyed for this test only.
        $context = $this->rekeyedContext($head);
        $this->assertTrue(HouseholdMemberReference::resolve($context, $new)?->is($member));
        $this->assertTrue(HouseholdMemberReference::resolve($context, $old)?->is($member), 'Overlap: the previous version still resolves.');

        // The overlap ends.
        config(['family_auth.fingerprint.previous_key' => null, 'family_auth.fingerprint.previous_key_version' => null]);
        $this->assertNull(HouseholdMemberReference::resolve($context, $old));
        $this->assertTrue(HouseholdMemberReference::resolve($context, $new)?->is($member));
    }

    /**
     * After a key rotation the login identity is re-keyed by the rotation
     * procedure; here it is done by hand so the context can be resolved.
     */
    private function rekeyedContext(array $head): FamilyAccessResult
    {
        $head['identity']->forceFill([
            'login_key' => KeyedFingerprint::of(FingerprintContext::LOGIN_ID, (string) $head['person']->national_id),
            'key_version' => 2,
        ])->save();
        $context = $this->context($head['user']);
        $this->assertTrue($context->hasFamilyContext());

        return $context;
    }
}
