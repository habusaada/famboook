<?php

namespace Tests\Feature\FamilyPortal;

use App\Exceptions\RegistryAdmissionReferenceException as RefException;
use App\Models\Person;
use App\Support\CivilLookup\CivilLookupRefs;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionReference;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRefs as Refs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-06B-2: opaque direct-admission references. Synthetic data
 * and synthetic National IDs only. Nothing issues or reads them outside these tests.
 */
class RegistryAdmissionRefsTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const CHILD = '401234567';

    private const RELEASE = 3;

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

    private function issue(): string
    {
        return Refs::issue($this->context, self::RELEASE, self::CHILD, 'FATHER');
    }

    private function assertRefused(mixed $token, ?FamilyAccessResult $context = null, int $release = self::RELEASE, string $reason = RefException::REFERENCE_INVALID): void
    {
        try {
            Refs::read($token, $context ?? $this->context, $release);
            $this->fail('The reference was accepted.');
        } catch (RefException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($reason, $e->getMessage());
        }
    }

    /** A correctly encrypted payload: what a holder of APP_KEY, or another feature's token, could produce. */
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

    // 1
    public function test_a_reference_round_trips_to_a_typed_internal_result(): void
    {
        $ref = Refs::read($this->issue(), $this->context, self::RELEASE);

        $this->assertInstanceOf(RegistryAdmissionReference::class, $ref);
        $this->assertSame([self::RELEASE, self::CHILD, 'FATHER'], [$ref->releaseId, $ref->childNationalId(), $ref->parentLink]);
        $this->assertSame(Refs::TTL_SECONDS, $ref->expiresAt - $ref->issuedAt);
        $this->assertSame(900, Refs::TTL_SECONDS);
        $this->assertSame('MOTHER', Refs::read(Refs::issue($this->context, self::RELEASE, self::CHILD, 'MOTHER'), $this->context, self::RELEASE)->parentLink);
        $this->assertSame($ref->childNationalId(), Refs::read($this->forge(), $this->context, self::RELEASE)->childNationalId());
    }

    public function test_encryption_is_not_deterministic_and_carries_nothing_in_clear(): void
    {
        $a = $this->issue();
        $b = $this->issue();

        $this->assertNotSame($a, $b);
        foreach ([$a, $b] as $token) {
            $envelope = (string) base64_decode($token, true);
            $this->assertSame(['iv', 'value', 'mac', 'tag'], array_keys(json_decode($envelope, true)));
            foreach ([self::CHILD, 'FATHER', 'ADMIT_REGISTRY_CHILD', '"u"', (string) $this->head['family']->family_code] as $clear) {
                $this->assertStringNotContainsString($clear, $token.$envelope);
            }
        }
    }

    // 2
    public function test_a_tampered_reference_is_refused(): void
    {
        $token = $this->issue();
        $envelope = json_decode(base64_decode($token), true);
        foreach (['iv', 'value', 'mac'] as $field) {
            $changed = $envelope;
            $changed[$field] = strrev($changed[$field]);
            $this->assertRefused(base64_encode(json_encode($changed)));
        }
        $this->assertRefused(substr($token, 0, -4));
        $this->assertRefused($token.'A');
        foreach ([null, '', 42, [], 'not-a-token', base64_encode('{}')] as $junk) {
            $this->assertRefused($junk);
        }
    }

    // 3, 4
    public function test_expired_and_future_issued_references_are_refused(): void
    {
        $token = $this->issue();
        $this->travel(Refs::TTL_SECONDS - 1)->seconds();
        $this->assertSame(self::CHILD, Refs::read($token, $this->context, self::RELEASE)->childNationalId());
        $this->travel(1)->seconds();
        $this->assertRefused($token);
        $this->travelBack();

        $skew = now()->getTimestamp() + Refs::CLOCK_SKEW_SECONDS;
        $this->assertSame(self::CHILD, Refs::read($this->forge(['i' => $skew, 'x' => $skew + Refs::TTL_SECONDS]), $this->context, self::RELEASE)->childNationalId());
        $this->assertRefused($this->forge(['i' => $skew + 1, 'x' => $skew + 1 + Refs::TTL_SECONDS]));
        // A lifetime other than the fixed one is not ours.
        $now = now()->getTimestamp();
        $this->assertRefused($this->forge(['x' => $now + Refs::TTL_SECONDS + 3600]));
        $this->assertRefused($this->forge(['x' => $now]));
    }

    // 5, 6, 7
    public function test_another_actor_family_or_head_is_refused(): void
    {
        $token = $this->issue();
        $other = $this->contextOf($this->activatedHead('223456789')['user']);
        $this->assertRefused($token, $other);

        // Same user and Person, but another Family.
        $this->assertRefused($token, FamilyAccessResult::identity($this->head['user'], $this->head['link'], $this->head['person'], $this->head['identity'])
            ->withFamily($other->membership, $other->family));
        // Same user and Family, but the household head is now another Person.
        $this->assertRefused($token, FamilyAccessResult::identity($this->head['user'], $this->head['link'], Person::factory()->create(), $this->head['identity'])
            ->withFamily($this->head['membership'], $this->head['family']));
        $this->assertRefused($this->forge(['u' => $other->user->id]));
        $this->assertRefused($this->forge(['f' => $other->family->id]));
        $this->assertRefused($this->forge(['h' => $other->person->id]));
    }

    // 8, 9
    public function test_another_purpose_version_or_feature_is_refused(): void
    {
        foreach (['ADMIT', 'admit_registry_child', 'LOOKUP', ''] as $purpose) {
            $this->assertRefused($this->forge(['p' => $purpose]));
        }
        foreach ([0, 2, '1', '1.0', null] as $version) {
            $this->assertRefused($this->forge(['v' => $version]));
        }
        $this->assertRefused(CivilLookupRefs::issue(CivilLookupRefs::RECORD, $this->head['user'], 'FAMILY', self::RELEASE, ['m' => self::CHILD]));
    }

    // 10
    public function test_a_valid_reference_of_another_release_is_release_changed_only(): void
    {
        $this->assertRefused($this->issue(), release: self::RELEASE + 1, reason: RefException::RELEASE_CHANGED);
        // An invalid reference stays REFERENCE_INVALID whatever the release.
        $this->assertRefused($this->forge(['p' => 'X']), release: self::RELEASE + 1);
        $this->assertSame([422, 409], [(new RefException(RefException::REFERENCE_INVALID))->status(), (new RefException(RefException::RELEASE_CHANGED))->status()]);
        foreach ([0, -1, '3', null] as $release) {
            $this->assertRefused($this->forge(['r' => $release]));
        }
    }

    // 11, 12
    public function test_invalid_national_ids_and_parent_links_are_refused_on_both_sides(): void
    {
        foreach (['12', '40123456a', '000000000', '٤٠١٢٣٤٥٦٧', '401-234-567', ' 401234567', 401234567] as $id) {
            $this->assertRefused($this->forge(['n' => $id]));
            if (is_string($id)) {
                try {
                    Refs::issue($this->context, self::RELEASE, $id, 'FATHER');
                    $this->fail("issued for {$id}");
                } catch (InvalidArgumentException $e) {
                    $this->assertStringNotContainsString($id, $e->getMessage());
                }
            }
        }
        foreach (['BOTH', 'father', 'SON', '', null] as $link) {
            $this->assertRefused($this->forge(['l' => $link]));
        }
        $this->expectException(InvalidArgumentException::class);
        Refs::issue($this->context, self::RELEASE, self::CHILD, 'BOTH');
    }

    // 13
    public function test_an_oversized_reference_is_refused_before_decryption(): void
    {
        Crypt::shouldReceive('decryptString')->never();
        $this->assertRefused(str_repeat('A', Refs::MAX_LENGTH + 1));
    }

    // 14, 15
    public function test_missing_unexpected_or_mistyped_fields_are_refused(): void
    {
        foreach (['v', 'p', 'u', 'f', 'h', 'r', 'n', 'l', 'i', 'x', 'j'] as $key) {
            $this->assertRefused($this->forge(without: [$key]));
        }
        foreach ([['admin' => true], ['m' => self::CHILD], ['x2' => 1], ['N' => self::CHILD]] as $extra) {
            $this->assertRefused($this->forge($extra));
        }
        foreach ([['j' => 'short'], ['j' => str_repeat('G', 32)], ['i' => (string) now()->getTimestamp()], ['u' => (string) $this->head['user']->id], ['n' => ['401234567']]] as $bad) {
            $this->assertRefused($this->forge($bad));
        }
        $this->assertRefused(Crypt::encryptString('[1,2,3]'));
        $this->assertRefused(Crypt::encryptString('not json'));
    }

    // 16
    public function test_previous_keys_still_open_and_other_keys_do_not(): void
    {
        $old = config('app.key');
        $token = $this->issue();
        $new = 'base64:'.base64_encode(random_bytes(32));
        $this->rotate($new, [$old]);
        $this->assertSame(self::CHILD, Refs::read($token, $this->context, self::RELEASE)->childNationalId());
        $this->assertNotSame($token, $fresh = $this->issue());

        $this->rotate($new, []);
        $this->assertRefused($token);
        $this->assertSame(self::CHILD, Refs::read($fresh, $this->context, self::RELEASE)->childNationalId());
    }

    private function rotate(string $key, array $previous): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();
    }

    // 17
    public function test_the_child_national_id_never_leaks(): void
    {
        $ref = Refs::read($this->issue(), $this->context, self::RELEASE);

        ob_start();
        var_dump($ref);
        print_r($ref);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString(self::CHILD, $dump);
        $this->assertStringContainsString('[redacted]', $dump);
        foreach ([fn () => json_encode($ref, JSON_THROW_ON_ERROR), fn () => serialize($ref)] as $leak) {
            try {
                $leak();
                $this->fail('The reference was serialized.');
            } catch (LogicException $e) {
                $this->assertStringNotContainsString(self::CHILD, $e->getMessage());
            }
        }

        // A refusal says nothing about the payload, even after decryption.
        try {
            Refs::read($this->forge(['u' => 999999]), $this->context, self::RELEASE);
        } catch (RefException $e) {
            $this->assertStringNotContainsString(self::CHILD, $e->getMessage().$e->getTraceAsString());
        }
        $this->assertTrue((new \ReflectionParameter([Refs::class, 'issue'], 'childNationalId'))->getAttributes(\SensitiveParameter::class) !== []);
    }

    // 18
    public function test_issuing_and_reading_touch_no_database(): void
    {
        DB::enableQueryLog();
        Refs::read($this->issue(), $this->context, self::RELEASE);
        $this->assertRefused($this->forge(['p' => 'X']));
        $this->assertSame([], DB::getQueryLog());
    }

    // 19, 20
    public function test_nothing_routes_to_references_or_admits(): void
    {
        foreach (Route::getRoutes() as $route) {
            // ADD-WP-06B-3 added the read-only discovery GET (ref = null); ADD-WP-07C the one admission POST.
            if ($route->uri() === 'api/v1/family/household/registry-children/admissions') {
                $this->assertSame(['POST'], $route->methods());
                $this->assertSame('App\\Http\\Controllers\\Api\\V1\\Family\\FamilyRegistryAdmissionController@store', $route->getActionName());

                continue;
            }
            $this->assertStringNotContainsString('RegistryAdmission', (string) $route->getActionName());
            $this->assertStringNotContainsString('admissions', $route->uri());
        }
        // Only the RegistryAdmission support namespace and, since ADD-WP-07B-2c, the internal
        // admission action may name the class; nothing routes to that action (asserted above
        // for the controllers, and here for every other file).
        $users = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            if (str_ends_with($path, '.php') && ! str_contains($path, '/Support/FamilyPortal/RegistryAdmission/')
                && str_contains((string) file_get_contents($path), 'RegistryAdmissionRefs')) {
                $users[] = $path;
            }
            if (str_ends_with($path, '.php') && ! str_contains($path, '/Actions/FamilyPortal/')
                && ! str_ends_with($path, '/Controllers/Api/V1/Family/FamilyRegistryAdmissionController.php')) {
                // A use, not a mention: no import, ::class or construction of the action anywhere else.
                $this->assertDoesNotMatchRegularExpression('/use App\\\\Actions\\\\FamilyPortal\\\\AdmitRegistryChildAction|AdmitRegistryChildAction::class|new AdmitRegistryChildAction/', (string) file_get_contents($path), $path);
            }
            $this->assertDoesNotMatchRegularExpression('/Admi\w*From\w*Registry|RegistryAdmission\w*Action/', basename($path));
        }
        // The action, and (ADD-WP-07C) the admission request, for the reference's length limit only.
        sort($users);
        $this->assertCount(2, $users);
        $this->assertStringEndsWith('/Actions/FamilyPortal/AdmitRegistryChildAction.php', $users[0]);
        $this->assertStringEndsWith('/Http/Requests/Api/V1/Family/FamilyRegistryAdmissionRequest.php', $users[1]);
        $this->assertSame('OFF', config('family_registry_admission.mode'));
    }
}
