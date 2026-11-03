<?php

namespace Tests\Feature\FamilyPortal;

use App\Exceptions\RegistryAdmissionReferenceException as RefException;
use App\Models\Person;
use App\Support\CivilLookup\CivilLookupRefs;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\FamilyRegistryAdmissionAudit as Audit;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionEvidence as Evidence;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionReference;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRefs as Refs;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionReplayClaim as Claim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-07B-2a: replay claims. An authentic, possibly expired admission
 * reference identifies a committed admission for a replay and authorizes nothing.
 * Synthetic data and synthetic National IDs only. Nothing outside these tests uses it.
 */
class RegistryAdmissionReplayClaimTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const CHILD = '401234567';

    private const RELEASE = 3;

    private const DECLARATION = '2026-11-v1';

    private FamilyAccessResult $context;

    private array $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->head = $this->activatedHead('123456789');
        $this->context = $this->contextOf($this->head['user']);
    }

    private function contextOf($user): FamilyAccessResult
    {
        $context = app(FamilyAccessResolver::class)->familyContext($user);
        $this->assertTrue($context->hasFamilyContext());

        return $context;
    }

    private function issue(string $child = self::CHILD): string
    {
        return Refs::issue($this->context, self::RELEASE, $child, 'FATHER');
    }

    /** The evidence row an admission of $child by this head would have committed. */
    private function evidence(array $override = [], string $child = self::CHILD): Evidence
    {
        $reference = Audit::referenceFingerprint($child);

        return Evidence::fromRow((object) [
            'family_id' => $this->head['family']->id, 'admitted_by' => $this->head['user']->id,
            'admitted_by_person_id' => $this->head['person']->id, 'reference_fingerprint' => $reference['fingerprint'],
            'reference_key_version' => $reference['key_version'], 'release_id' => self::RELEASE,
            'declaration_version' => self::DECLARATION, ...$override,
        ]);
    }

    private function assertRefused(mixed $token, ?FamilyAccessResult $context = null): void
    {
        try {
            Refs::openForReplay($token, $context ?? $this->context);
            $this->fail('The reference was accepted for a replay.');
        } catch (RefException $e) {
            $this->assertSame(RefException::REFERENCE_INVALID, $e->reason);
            $this->assertSame(RefException::REFERENCE_INVALID, $e->getMessage());
        }
    }

    private function forge(array $changes = [], array $without = []): string
    {
        $now = now()->getTimestamp();
        $payload = array_diff_key([
            'v' => 1, 'p' => 'ADMIT_REGISTRY_CHILD', 'u' => $this->head['user']->id, 'f' => $this->head['family']->id, 'h' => $this->head['person']->id,
            'r' => self::RELEASE, 'n' => self::CHILD, 'l' => 'FATHER', 'i' => $now, 'x' => $now + Refs::TTL_SECONDS, 'j' => str_repeat('ab', 16),
            ...$changes,
        ], array_flip($without));

        return Crypt::encryptString(json_encode($payload));
    }

    private function useFingerprintKeys(int $version, string $key, ?int $previousVersion = null, ?string $previousKey = null): void
    {
        config(['family_auth.fingerprint' => [
            'key' => $key, 'key_version' => $version, 'previous_key' => $previousKey, 'previous_key_version' => $previousVersion,
        ]]);
    }

    public function test_a_valid_reference_gives_a_claim_that_matches_its_evidence(): void
    {
        $claim = Refs::openForReplay($this->issue(), $this->context);

        $this->assertInstanceOf(Claim::class, $claim);
        $this->assertTrue($claim->matchesEvidence($this->evidence(), self::DECLARATION));
        // The release is not part of the match (D-B2), and a later release still matches.
        $this->assertTrue($claim->matchesEvidence($this->evidence(['release_id' => self::RELEASE + 5]), self::DECLARATION));
        $this->assertSame(86400, Refs::REPLAY_WINDOW_SECONDS);
        $this->assertSame(900, Refs::TTL_SECONDS);
    }

    public function test_an_expired_reference_opens_for_a_replay_within_24_hours_only(): void
    {
        $token = $this->issue();
        $this->travel(Refs::TTL_SECONDS)->seconds();
        // The strict read is unchanged: expired is refused there.
        try {
            Refs::read($token, $this->context, self::RELEASE);
            $this->fail('read() accepted an expired reference.');
        } catch (RefException $e) {
            $this->assertSame(RefException::REFERENCE_INVALID, $e->reason);
        }
        $this->assertTrue(Refs::openForReplay($token, $this->context)->matchesEvidence($this->evidence(), self::DECLARATION));

        $this->travel(Refs::REPLAY_WINDOW_SECONDS - Refs::TTL_SECONDS - 1)->seconds();
        $this->assertTrue(Refs::openForReplay($token, $this->context)->matchesEvidence($this->evidence(), self::DECLARATION));
        $this->travel(1)->seconds();
        $this->assertRefused($token);
        $this->travel(30)->days();
        $this->assertRefused($token);
        $this->travelBack();

        // Issued in the future beyond the skew, or with another lifetime: refused as by read().
        $skew = now()->getTimestamp() + Refs::CLOCK_SKEW_SECONDS + 1;
        $this->assertRefused($this->forge(['i' => $skew, 'x' => $skew + Refs::TTL_SECONDS]));
        $old = now()->getTimestamp() - 3600;
        $this->assertRefused($this->forge(['i' => $old, 'x' => $old + Refs::REPLAY_WINDOW_SECONDS]));
        $this->assertNotNull(Refs::openForReplay($this->forge(['i' => $old, 'x' => $old + Refs::TTL_SECONDS]), $this->context));
    }

    public function test_an_invalid_mac_purpose_or_payload_is_refused(): void
    {
        $token = $this->issue();
        $envelope = json_decode(base64_decode($token), true);
        foreach (['iv', 'value', 'mac'] as $field) {
            $changed = $envelope;
            $changed[$field] = strrev($changed[$field]);
            $this->assertRefused(base64_encode(json_encode($changed)));
        }
        foreach ([null, '', 42, [], 'not-a-token', base64_encode('{}'), $token.'A', str_repeat('A', Refs::MAX_LENGTH + 1)] as $junk) {
            $this->assertRefused($junk);
        }
        foreach (['ADMIT', 'LOOKUP', ''] as $purpose) {
            $this->assertRefused($this->forge(['p' => $purpose]));
        }
        foreach ([['v' => 2], ['r' => 0], ['n' => '000000000'], ['n' => '40123456a'], ['l' => 'BOTH'], ['j' => 'short'], ['admin' => true]] as $bad) {
            $this->assertRefused($this->forge($bad));
        }
        foreach (['v', 'p', 'u', 'f', 'h', 'r', 'n', 'l', 'i', 'x', 'j'] as $key) {
            $this->assertRefused($this->forge(without: [$key]));
        }
        $this->assertRefused(CivilLookupRefs::issue(CivilLookupRefs::RECORD, $this->head['user'], 'FAMILY', self::RELEASE, ['m' => self::CHILD]));
        $this->assertRefused(Crypt::encryptString('not json'));
    }

    public function test_another_actor_family_or_head_is_refused(): void
    {
        $token = $this->issue();
        $other = $this->contextOf($this->activatedHead('223456789')['user']);
        $this->assertRefused($token, $other);
        $this->assertRefused($token, FamilyAccessResult::identity($this->head['user'], $this->head['link'], $this->head['person'], $this->head['identity'])
            ->withFamily($other->membership, $other->family));
        $this->assertRefused($token, FamilyAccessResult::identity($this->head['user'], $this->head['link'], Person::factory()->create(), $this->head['identity'])
            ->withFamily($this->head['membership'], $this->head['family']));
        $this->assertRefused($this->forge(['u' => $other->user->id]));
        $this->assertRefused($this->forge(['f' => $other->family->id]));
        $this->assertRefused($this->forge(['h' => $other->person->id]));
    }

    public function test_app_key_rotation_opens_with_the_previous_key_only(): void
    {
        $old = config('app.key');
        $token = $this->issue();
        $new = 'base64:'.base64_encode(random_bytes(32));

        $this->rotateAppKey($new, [$old]);
        $this->assertTrue(Refs::openForReplay($token, $this->context)->matchesEvidence($this->evidence(), self::DECLARATION));
        $this->rotateAppKey($new, []);
        $this->assertRefused($token);
    }

    private function rotateAppKey(string $key, array $previous): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();
    }

    public function test_evidence_is_compared_under_its_stored_fingerprint_key_version(): void
    {
        $v1 = 'test-only-family-auth-key-0123456789-abcdef';
        $v2 = 'test-only-family-auth-key-rotated-9876543210';
        $stored = $this->evidence();
        $this->assertSame(1, $stored->referenceKeyVersion);
        $token = $this->issue();

        // Rotation in progress: version 2 is current, version 1 still usable.
        $this->useFingerprintKeys(2, $v2, 1, $v1);
        $claim = Refs::openForReplay($token, $this->context);
        $this->assertTrue($claim->matchesEvidence($stored, self::DECLARATION));
        $this->assertTrue($claim->matchesEvidence($this->evidence(), self::DECLARATION), 'evidence written under version 2');
        $this->assertSame(2, $this->evidence()->referenceKeyVersion);

        // Version 1 retired: the stored row can no longer be verified, and does not match.
        $this->useFingerprintKeys(2, $v2);
        $this->assertFalse(Refs::openForReplay($token, $this->context)->matchesEvidence($stored, self::DECLARATION));
        // A version that never existed does not match either, and nothing throws.
        $this->assertFalse($claim->matchesEvidence(Evidence::fromRow((object) [
            'family_id' => $stored->familyId, 'admitted_by' => $stored->admittedBy, 'admitted_by_person_id' => $stored->admittedByPersonId,
            'reference_fingerprint' => $stored->referenceFingerprint, 'reference_key_version' => 9, 'release_id' => 3, 'declaration_version' => self::DECLARATION,
        ]), self::DECLARATION));

        // No usable fingerprint key at all: the claim is not built (fail closed, no identity in the message).
        config(['family_auth.fingerprint.key' => 'short']);
        try {
            Refs::openForReplay($token, $this->context);
            $this->fail('A claim was built without a fingerprint key.');
        } catch (LogicException $e) {
            $this->assertStringNotContainsString(self::CHILD, $e->getMessage());
        }
    }

    public function test_another_child_declaration_actor_family_or_head_does_not_match(): void
    {
        $claim = Refs::openForReplay($this->issue(), $this->context);
        $evidence = $this->evidence();

        $this->assertFalse($claim->matchesEvidence($this->evidence(child: '401234568'), self::DECLARATION));
        foreach (['2026-12-v2', '2026-11-V1', '2026-11-v1 ', ''] as $declaration) {
            $this->assertFalse($claim->matchesEvidence($evidence, $declaration), $declaration);
        }
        foreach (['admitted_by' => 999999, 'family_id' => 999999, 'admitted_by_person_id' => 999999] as $column => $value) {
            $this->assertFalse($claim->matchesEvidence($this->evidence([$column => $value]), self::DECLARATION), $column);
        }
        $this->assertFalse($claim->matchesEvidence($this->evidence(['reference_fingerprint' => str_repeat('0', 64)]), self::DECLARATION));
    }

    public function test_a_malformed_evidence_row_is_refused(): void
    {
        $row = ['family_id' => 1, 'admitted_by' => 1, 'admitted_by_person_id' => 1, 'reference_fingerprint' => str_repeat('a', 64),
            'reference_key_version' => 1, 'release_id' => 1, 'declaration_version' => 'v1'];
        $this->assertSame(1, Evidence::fromRow((object) $row)->releaseId);
        $this->assertSame(7, Evidence::fromRow((object) (['release_id' => '7'] + $row))->releaseId, 'a driver returning digits');

        foreach ([
            ['family_id' => 0], ['admitted_by' => -1], ['admitted_by_person_id' => null], ['reference_key_version' => '1.0'],
            ['release_id' => '07'], ['release_id' => 'x'], ['reference_fingerprint' => str_repeat('A', 64)], ['reference_fingerprint' => 'abc'],
            ['reference_fingerprint' => null], ['declaration_version' => ' '], ['declaration_version' => null], ['declaration_version' => 1],
        ] as $bad) {
            try {
                Evidence::fromRow((object) ($bad + $row));
                $this->fail('Accepted: '.json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('An admission evidence row is malformed.', $e->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Evidence::fromRow((object) array_diff_key($row, ['release_id' => 1]));
    }

    public function test_the_claim_never_leaks_the_national_id_the_token_or_the_fingerprint(): void
    {
        Log::spy();
        $token = $this->issue();
        $claim = Refs::openForReplay($token, $this->context);
        $fingerprint = Audit::referenceFingerprint(self::CHILD)['fingerprint'];

        ob_start();
        var_dump($claim);
        print_r($claim);
        $dump = (string) ob_get_clean();
        foreach ([self::CHILD, $token, $fingerprint] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertStringContainsString('[redacted]', $dump);

        // The claim holds no National ID at all, not even privately.
        foreach ((new ReflectionClass($claim))->getProperties() as $property) {
            $this->assertStringNotContainsString(self::CHILD, json_encode($property->getValue($claim)), $property->getName());
        }

        foreach ([fn () => json_encode($claim, JSON_THROW_ON_ERROR), fn () => serialize($claim),
            fn () => unserialize('O:'.strlen(Claim::class).':"'.Claim::class.'":0:{}')] as $leak) {
            try {
                $leak();
                $this->fail('The claim was serialized or unserialized.');
            } catch (LogicException $e) {
                $this->assertStringNotContainsString(self::CHILD, $e->getMessage());
            }
        }

        try {
            Refs::openForReplay($this->forge(['u' => 999999]), $this->context);
        } catch (RefException $e) {
            $this->assertStringNotContainsString(self::CHILD, $e->getMessage().$e->getTraceAsString());
        }
        $this->assertNotSame([], (new \ReflectionParameter([Claim::class, 'of'], 'childNationalId'))->getAttributes(\SensitiveParameter::class));
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('debug');
    }

    public function test_a_claim_cannot_authorize_a_write(): void
    {
        $token = $this->issue();
        $this->travel(Refs::TTL_SECONDS + 60)->seconds();

        DB::enableQueryLog();
        $claim = Refs::openForReplay($token, $this->context);
        $claim->matchesEvidence($this->evidence(), self::DECLARATION);
        $this->assertSame([], DB::getQueryLog(), 'opening and comparing a claim touch no database');

        // Not a reference, and no way to one: the only public operation is the comparison.
        $this->assertNotInstanceOf(RegistryAdmissionReference::class, $claim);
        $public = array_map(fn (ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(Claim::class))->getMethods(ReflectionMethod::IS_PUBLIC));
        sort($public);
        $this->assertSame(['__debugInfo', '__serialize', '__unserialize', 'jsonSerialize', 'matchesEvidence', 'of'], $public);
        $this->assertSame('bool', (string) (new ReflectionMethod(Claim::class, 'matchesEvidence'))->getReturnType());
        $this->assertTrue((new ReflectionMethod(Claim::class, '__construct'))->isPrivate());
        foreach ((new ReflectionClass(Claim::class))->getProperties() as $property) {
            $this->assertTrue($property->isPrivate(), $property->getName());
        }
        // The expired token still opens nothing for an admission.
        $this->expectException(RefException::class);
        Refs::read($token, $this->context, self::RELEASE);
    }

    public function test_the_claim_rejects_invalid_construction(): void
    {
        foreach ([[0, 1, 1, self::CHILD], [1, 0, 1, self::CHILD], [1, 1, -1, self::CHILD], [1, 1, 1, '000000000'], [1, 1, 1, '40123456']] as $args) {
            try {
                Claim::of(...$args);
                $this->fail('Accepted: '.json_encode(array_slice($args, 0, 3)));
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('40123456', $e->getMessage());
            }
        }
    }
}
