<?php

namespace Tests\Feature\FamilyPortal;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 DBA procedure on PostgreSQL, against the REAL identity_ref schema
 * (00-roles, 01-schema, 08-lookup) in the disposable *_test cluster:
 *
 * - 11-registry-admission-pin-precheck.sql refuses what 10 needs and is missing;
 * - 12-registry-admission-pin-verify.sql fails before 10, passes after it, and catches a
 *   disabled trigger or a widened grant;
 * - 10 is idempotent;
 * - 13-registry-admission-pin-rollback.dba.sql restores publish_release() as installed,
 *   is idempotent, keeps the writer's grant, and refuses while a pin or publication lock is held.
 *
 * SYNTHETIC identities only; no admission row is written.
 */
class RegistryAdmissionPinScriptsPostgresTest extends TestCase
{
    use DatabaseTruncation, IdentityRefDatabase;

    protected $exceptTables = ['clans', 'relationship_types'];

    private const PIN = "x'4642434956303150'::bigint";

    private const PUBLICATION = "x'4642434956303141'::bigint";

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->setUpIdentityRef();
        DB::unprepared($this->sqlFile('08-lookup.dba.sql'));
    }

    protected function tearDown(): void
    {
        if ($this->app === null) {
            parent::tearDown();

            return;
        }
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    private static function pgsqlTestDatabaseIsReachable(): bool
    {
        $env = static fn (string $key): ?string => ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)) ?: null;
        if ($env('DB_CONNECTION') !== 'pgsql' || ! str_ends_with((string) $env('DB_DATABASE'), '_test')) {
            return false;
        }
        try {
            new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=3', $env('DB_HOST') ?? '127.0.0.1', $env('DB_PORT') ?? '5432', $env('DB_DATABASE')),
                $env('DB_USERNAME'), $env('DB_PASSWORD'));

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** The error of running $file as the administrator, or '' when it passes. */
    private function apply(string $file): string
    {
        try {
            DB::unprepared($this->sqlFile($file));

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        } finally {
            DB::unprepared('RESET ROLE');
        }
    }

    private function publishSource(): string
    {
        return (string) DB::selectOne("SELECT prosrc FROM pg_proc WHERE oid = 'identity_ref.publish_release(bigint)'::regprocedure")->prosrc;
    }

    private function exists(string $sql): bool
    {
        return (bool) DB::selectOne("SELECT ({$sql}) IS NOT NULL AS present")->present;
    }

    public function test_the_precheck_needs_a_published_active_release_and_reports_dba_4(): void
    {
        $this->assertStringContainsString('IDENTITY_REF_PIN_PRECHECK_NO_PUBLISHED_ACTIVE_RELEASE', $this->apply('11-registry-admission-pin-precheck.sql'));
        $this->publishedRelease(['111111118' => []]);
        $this->assertSame('', $this->apply('11-registry-admission-pin-precheck.sql'));
        $this->assertNull(DB::selectOne("SELECT to_regprocedure('identity_ref_lookup.release_pin_held(integer, integer)') AS f")->f, 'the precheck changes nothing');
    }

    public function test_the_precheck_refuses_while_a_publication_runs(): void
    {
        $this->publishedRelease(['111111118' => []]);
        $publisher = $this->connection();
        $publisher->beginTransaction();
        $publisher->query('SELECT pg_advisory_xact_lock('.self::PUBLICATION.')');
        $this->assertStringContainsString('IDENTITY_REF_PIN_PRECHECK_PUBLICATION_IN_PROGRESS', $this->apply('11-registry-admission-pin-precheck.sql'));
        $publisher->rollBack();
        $this->assertSame('', $this->apply('11-registry-admission-pin-precheck.sql'));
    }

    public function test_verify_fails_before_the_dba_file_and_passes_after_it_applied_twice(): void
    {
        $this->publishedRelease(['111111118' => []]);
        $this->assertStringContainsString('IDENTITY_REF_PIN_VERIFY_DBA1_NOT_APPLIED', $this->apply('12-registry-admission-pin-verify.sql'));

        $this->assertSame('', $this->apply('10-registry-admission-pin.dba.sql'));
        $this->assertSame('', $this->apply('10-registry-admission-pin.dba.sql'), 'the DBA file is idempotent');
        $this->assertSame('', $this->apply('12-registry-admission-pin-verify.sql'));
        $this->assertSame('', $this->apply('11-registry-admission-pin-precheck.sql'), 'the precheck also passes once applied');
    }

    public function test_verify_catches_a_disabled_trigger_and_a_widened_grant(): void
    {
        $this->publishedRelease(['111111118' => []]);
        $this->apply('10-registry-admission-pin.dba.sql');

        DB::unprepared('ALTER TABLE identity_ref.active_release DISABLE TRIGGER active_release_pinned_forward');
        $this->assertStringContainsString('IDENTITY_REF_PIN_VERIFY_DBA2_TRIGGER', $this->apply('12-registry-admission-pin-verify.sql'));
        DB::unprepared('ALTER TABLE identity_ref.active_release ENABLE TRIGGER active_release_pinned_forward');

        DB::unprepared('GRANT EXECUTE ON FUNCTION identity_ref_lookup.release_pin_held(integer, integer) TO PUBLIC');
        $this->assertStringContainsString('IDENTITY_REF_PIN_VERIFY_DBA3_PRIVILEGES', $this->apply('12-registry-admission-pin-verify.sql'));
        DB::unprepared('REVOKE EXECUTE ON FUNCTION identity_ref_lookup.release_pin_held(integer, integer) FROM PUBLIC');

        DB::unprepared('GRANT EXECUTE ON FUNCTION identity_ref_lookup.release_pin_held(integer, integer) TO '.self::WRITER);
        $this->assertStringContainsString('IDENTITY_REF_PIN_VERIFY_DBA3_PRIVILEGES', $this->apply('12-registry-admission-pin-verify.sql'));
        DB::unprepared('REVOKE EXECUTE ON FUNCTION identity_ref_lookup.release_pin_held(integer, integer) FROM '.self::WRITER);
        $this->assertSame('', $this->apply('12-registry-admission-pin-verify.sql'));
    }

    public function test_the_rollback_restores_publish_release_as_installed_and_is_idempotent(): void
    {
        $installed = $this->publishSource();
        $r1 = $this->publishedRelease(['111111118' => []]);
        $this->apply('10-registry-admission-pin.dba.sql');
        $this->assertNotSame($installed, $this->publishSource());

        $this->assertSame('', $this->apply('13-registry-admission-pin-rollback.dba.sql'));
        $this->assertSame('', $this->apply('13-registry-admission-pin-rollback.dba.sql'), 'the rollback is idempotent');
        $this->assertSame($installed, $this->publishSource());
        $this->assertFalse($this->exists("SELECT to_regprocedure('identity_ref_lookup.release_pin_held(integer, integer)')"));
        $this->assertFalse($this->exists("SELECT to_regprocedure('identity_ref.check_active_release_pinned()')"));
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgname = 'active_release_pinned_forward'")->n);
        $this->assertTrue((bool) DB::selectOne("SELECT has_function_privilege('".self::WRITER."', 'identity_ref.publish_release(bigint)', 'EXECUTE') AS ok")->ok);
        $this->assertStringContainsString('IDENTITY_REF_PIN_VERIFY_DBA1_NOT_APPLIED', $this->apply('12-registry-admission-pin-verify.sql'));

        // Publication still works without the pin, and the file can be applied again afterwards.
        $r2 = $this->publishedRelease(['111111118' => []], $r1);
        $this->assertSame($r2, (int) $this->asRole(self::OWNER, fn () => DB::selectOne('SELECT release_id FROM identity_ref.active_release WHERE id = 1')->release_id));
        $this->assertSame('', $this->apply('10-registry-admission-pin.dba.sql'));
        $this->assertSame('', $this->apply('12-registry-admission-pin-verify.sql'));
    }

    public function test_the_rollback_refuses_while_an_admission_holds_the_pin(): void
    {
        $this->publishedRelease(['111111118' => []]);
        $this->apply('10-registry-admission-pin.dba.sql');
        $admission = $this->connection();
        $admission->beginTransaction();
        $admission->query('SELECT pg_advisory_xact_lock_shared('.self::PIN.')');
        $this->assertStringContainsString('IDENTITY_REF_PIN_ROLLBACK_LOCK_HELD', $this->apply('13-registry-admission-pin-rollback.dba.sql'));
        $admission->rollBack();
        $this->assertSame('', $this->apply('12-registry-admission-pin-verify.sql'), 'nothing was removed');
    }
}
