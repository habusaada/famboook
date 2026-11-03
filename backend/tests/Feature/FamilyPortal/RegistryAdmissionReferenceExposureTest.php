<?php

namespace Tests\Feature\FamilyPortal;

use App\Exceptions\RegistryAdmissionUnavailableException;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\CivilRegistryValues;
use App\Support\FamilyPortal\RegistryAdmission\FamilyRegistryAdmissionAudit;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionFailure as Failure;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionReference as Reference;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRefs as Refs;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionReplayClaim;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildEligibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildFacts;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;
use RuntimeException;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-07B-2c-0: the child's National ID inside a validated admission
 * reference is not reachable through ORDINARY inspection of the object. Reflection on the
 * class, memory inspection and debuggers are out of scope and are shown to remain possible.
 * Synthetic data only.
 */
class RegistryAdmissionReferenceExposureTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const CHILD = '401234567';

    private const RELEASE = 3;

    private FamilyAccessResult $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $head = $this->activatedHead('123456789');
        $this->context = app(FamilyAccessResolver::class)->familyContext($head['user']);
    }

    private function reference(): Reference
    {
        return Refs::read(Refs::issue($this->context, self::RELEASE, self::CHILD, 'FATHER'), $this->context, self::RELEASE);
    }

    /** Calls every closure found, so a closure-held value would count as reachable too. */
    private static function flatten(mixed $value): string
    {
        return match (true) {
            $value instanceof Closure => self::flatten($value()),
            is_array($value) => implode('|', array_map(self::flatten(...), $value)),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    public function test_ordinary_inspection_finds_no_national_id(): void
    {
        $ref = $this->reference();
        $this->assertSame(self::CHILD, $ref->childNationalId(), 'the trusted accessor still works');

        $outputs = [
            'var_export' => var_export($ref, true),
            '(array)' => self::flatten((array) $ref),
            'get_object_vars' => self::flatten(get_object_vars($ref)),
            'print_r' => print_r($ref, true),
            'json_encode((array))' => (string) json_encode((array) $ref),
        ];
        foreach (['var_dump' => fn () => var_dump($ref), 'debug_zval_dump' => fn () => debug_zval_dump($ref)] as $name => $dump) {
            ob_start();
            $dump();
            $outputs[$name] = (string) ob_get_clean();
        }
        // dump() / dd(): Symfony VarDumper reads the object's properties through its casters.
        $outputs['VarDumper'] = (string) (new CliDumper)
            ->dump((new VarCloner)->cloneVar($ref), true);
        foreach ($outputs as $path => $output) {
            $this->assertStringNotContainsString(self::CHILD, $output, $path);
        }
        $this->assertStringContainsString('[redacted]', $outputs['print_r']);
        // What remains visible is what is meant to be: release, link and times.
        $this->assertSame(['releaseId', 'parentLink', 'issuedAt', 'expiresAt'], array_keys(get_object_vars($ref)));

        // No instance property holds it, private or not.
        foreach ((new ReflectionObject($ref))->getProperties() as $property) {
            if (! $property->isStatic()) {
                $this->assertStringNotContainsString(self::CHILD, self::flatten($property->getValue($ref)), $property->getName());
            }
        }
    }

    public function test_it_is_never_serialized_unserialized_or_cloned(): void
    {
        $ref = $this->reference();
        foreach ([
            'json_encode' => fn () => json_encode($ref, JSON_THROW_ON_ERROR),
            'serialize' => fn () => serialize($ref),
            'unserialize' => fn () => unserialize('O:'.strlen(Reference::class).':"'.Reference::class.'":0:{}'),
            'clone' => fn () => clone $ref,
        ] as $path => $leak) {
            try {
                $leak();
                $this->fail("Allowed: {$path}");
            } catch (LogicException $e) {
                $this->assertStringNotContainsString(self::CHILD, $e->getMessage().$e->getTraceAsString(), $path);
            }
        }
        $this->assertSame(self::CHILD, $ref->childNationalId());
    }

    public function test_the_held_value_goes_with_the_object(): void
    {
        $map = (new ReflectionClass(Reference::class))->getProperty('nationalIds');
        $before = count($map->getValue() ?? []);
        $ref = $this->reference();
        $this->assertCount($before + 1, $map->getValue());
        unset($ref);
        gc_collect_cycles();
        $this->assertCount($before, $map->getValue(), 'no National ID outlives its reference');
    }

    public function test_reflection_on_the_class_is_a_documented_residual_not_a_guarantee(): void
    {
        // PHP offers no secrecy against code running in the process: Reflection on the private
        // static map still reaches the value. This test documents it; it does not endorse it.
        $ref = $this->reference();
        $map = (new ReflectionClass(Reference::class))->getProperty('nationalIds');
        $this->assertTrue($map->isPrivate() && $map->isStatic());
        $this->assertSame(self::CHILD, $map->getValue()[$ref]);
    }

    public function test_parameters_carrying_a_national_id_are_sensitive(): void
    {
        $expected = [
            [Reference::class, '__construct', 'childNationalId'],
            [Refs::class, 'issue', 'childNationalId'],
            [Refs::class, 'authentic', 'payload'],
            [Refs::class, 'sortedKeys', 'payload'],
            [CivilRegistryValues::class, 'validNationalId', 'raw'],
            [CivilRegistryValues::class, 'parentLink', 'headNationalId'],
            [RegistryChildEligibility::class, 'registryReasons', 'nationalId'],
            [RegistryChildFacts::class, 'load', 'nationalIds'],
            [RegistryChildFacts::class, 'holders', 'nationalId'],
            [RegistryChildFacts::class, 'heldByDeleted', 'nationalId'],
            [RegistryChildFacts::class, 'requestOpen', 'nationalId'],
            [RegistryAdmissionReplayClaim::class, 'of', 'childNationalId'],
            [FamilyRegistryAdmissionAudit::class, 'referenceFingerprint', 'childNationalId'],
            [FamilyRegistryAdmissionAudit::class, 'admissionSucceeded', 'childNationalId'],
            [FamilyRegistryAdmissionAudit::class, 'admissionRefused', 'childNationalId'],
        ];
        foreach ($expected as [$class, $method, $parameter]) {
            $this->assertNotSame([], (new \ReflectionParameter([$class, $method], $parameter))->getAttributes(\SensitiveParameter::class),
                "{$class}::{$method}(\${$parameter})");
        }

        // Every parameter named like a National ID anywhere in the namespace is covered.
        foreach (glob(app_path('Support/FamilyPortal/RegistryAdmission/*.php')) as $file) {
            $class = 'App\\Support\\FamilyPortal\\RegistryAdmission\\'.basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    if (preg_match('/nationalids?$/i', $parameter->getName()) === 1) {
                        $this->assertNotSame([], $parameter->getAttributes(\SensitiveParameter::class), "{$class}::{$method->getName()}(\${$parameter->getName()})");
                    }
                }
            }
        }
    }

    public function test_a_trace_through_the_constructor_shows_no_national_id(): void
    {
        // A failure while the National ID is an argument: the trace shows a SensitiveParameterValue.
        $thrower = new class
        {
            public function make(#[\SensitiveParameter] string $id): void
            {
                new Reference(3, $id, 'FATHER', 1, 901);
                throw new RuntimeException('after construction');
            }
        };
        try {
            $thrower->make(self::CHILD);
            $this->fail('No exception.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(self::CHILD, $e->getTraceAsString());
            foreach ($e->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    $this->assertNotSame(self::CHILD, $argument, ($frame['class'] ?? '').'::'.$frame['function']);
                }
            }
        }
        $this->assertTrue((new ReflectionMethod(Reference::class, '__construct'))->getParameters()[1]->getAttributes(\SensitiveParameter::class) !== []);
    }

    public function test_a_failure_while_a_reference_is_held_logs_no_national_id(): void
    {
        $ref = $this->reference();
        $logged = null;
        Log::shouldReceive('error')->once()->andReturnUsing(function (string $message, array $context) use (&$logged) {
            $logged = [$message, $context];
        });
        try {
            Failure::run(function () use ($ref) {
                $id = $ref->childNationalId();
                throw new RuntimeException("unexpected failure near {$id}");
            });
            $this->fail('No failure.');
        } catch (RegistryAdmissionUnavailableException $e) {
            $everything = json_encode($logged).$e->getMessage().json_encode($e->render()->getData(true));
            $this->assertStringNotContainsString(self::CHILD, $everything);
        }
    }
}
