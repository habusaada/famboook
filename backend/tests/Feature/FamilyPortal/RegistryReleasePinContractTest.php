<?php

namespace Tests\Feature\FamilyPortal;

use App\Exceptions\CivilLookupException;
use App\Exceptions\RegistryAdmissionUnavailableException;
use App\Exceptions\RegistryReleasePinUnavailableException as PinUnavailable;
use App\Support\CivilLookup\CivilRegistryReader;
use App\Support\FamilyPortal\RegistryAdmission\PostgresRegistryReleasePin as Pin;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionFailure as Failure;
use App\Support\FamilyPortal\RegistryAdmission\RegistryReleasePin;
use App\Support\FamilyPortal\RegistryAdmission\RegistryReleasePinVerifier;
use App\Support\FamilyPortal\RegistryAdmission\ReleasePinVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * FB-FAMILY-ADD-01 ADD-WP-07B-2b: the release pin contract on both drivers (the locking
 * protocol itself is RegistryReleasePinTest, PostgreSQL only). Nothing is bound and no
 * registry is read.
 */
class RegistryReleasePinContractTest extends TestCase
{
    private function neverCalled(): RegistryReleasePinVerifier
    {
        return new class implements RegistryReleasePinVerifier
        {
            public function releasePinHeld(int $holderPid, int $nonce): ReleasePinVerification
            {
                throw new \LogicException('The verifier must not be reached.');
            }
        };
    }

    public function test_a_connection_that_is_not_postgresql_fails_closed_before_any_query(): void
    {
        config(['database.connections.pin_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $sqlite = DB::connection('pin_sqlite');
        $sqlite->enableQueryLog();
        $sqlite->beginTransaction();
        try {
            (new Pin($sqlite, $this->neverCalled()))->pin(3);
            $this->fail('Pinned on SQLite.');
        } catch (PinUnavailable $e) {
            $this->assertSame(PinUnavailable::NOT_POSTGRESQL, $e->detail);
            $this->assertFalse($e->retryable);
        }
        $this->assertSame([], $sqlite->getQueryLog());
        $sqlite->rollBack();

        $this->expectException(InvalidArgumentException::class);
        (new Pin($sqlite, $this->neverCalled()))->pin(0);
    }

    public function test_the_failure_codes_are_controlled_and_only_contention_is_retryable(): void
    {
        foreach (PinUnavailable::DETAILS as $detail) {
            $e = new PinUnavailable($detail);
            $this->assertSame($detail, $e->getMessage());
            $this->assertMatchesRegularExpression('/\APIN_[A-Z_]+\z/', $detail);
            $this->assertSame(in_array($detail, [PinUnavailable::BUSY, PinUnavailable::NONCE_BUSY], true), $e->retryable, $detail);
        }
        $this->expectException(InvalidArgumentException::class);
        new PinUnavailable('anything else');
    }

    public function test_the_failure_contract_logs_the_pin_code_and_answers_the_sanitized_503(): void
    {
        foreach ([PinUnavailable::BUSY => true, PinUnavailable::NOT_PROVEN => false, PinUnavailable::VERIFIER_UNAVAILABLE => false] as $detail => $retryable) {
            $logged = null;
            Log::shouldReceive('error')->once()->andReturnUsing(function (string $message, array $context) use (&$logged) {
                $logged = $context;
            });
            try {
                Failure::run(fn () => throw new PinUnavailable($detail));
                $this->fail('Nothing failed.');
            } catch (RegistryAdmissionUnavailableException $e) {
                $this->assertSame(['correlation_id', 'failure', 'exception', 'sqlstate'], array_keys($logged));
                $this->assertSame([$e->correlationId, $detail, null], [$logged['correlation_id'], $logged['failure'], $logged['sqlstate']]);
                $this->assertSame($retryable, $e->retryable, $detail);
                $this->assertSame(['message', 'code', 'correlation_id'], array_keys($e->render()->getData(true)));
                $this->assertSame('REGISTRY_UNAVAILABLE', $e->render()->getData(true)['code']);
                $this->assertSame($retryable ? '5' : null, $e->render()->headers->get('Retry-After'));
            }
        }
    }

    public function test_a_verification_row_is_strictly_shaped(): void
    {
        $this->assertSame([true, 7], [ReleasePinVerification::fromRow((object) ['held' => true, 'active_release_id' => 7])->held,
            ReleasePinVerification::fromRow((object) ['held' => true, 'active_release_id' => 7])->activeReleaseId]);
        $this->assertSame(7, ReleasePinVerification::fromRow((object) ['held' => false, 'active_release_id' => '7'])->activeReleaseId);
        $this->assertNull(ReleasePinVerification::fromRow((object) ['held' => false, 'active_release_id' => null])->activeReleaseId);

        foreach ([null, [], (object) [], (object) ['held' => 't', 'active_release_id' => 7], (object) ['held' => 1, 'active_release_id' => 7],
            (object) ['held' => true, 'active_release_id' => 0], (object) ['held' => true, 'active_release_id' => '07'],
            (object) ['held' => true, 'active_release_id' => 'x'], (object) ['held' => true, 'active_release_id' => 7.0]] as $row) {
            try {
                ReleasePinVerification::fromRow($row);
                $this->fail('Accepted: '.json_encode($row));
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_operational_verifier_fails_closed_until_the_dba_package_exists(): void
    {
        // CivilRegistryReader is the only user of the lookup connection; without its
        // credentials (and without identity_ref_lookup.release_pin_held, DBA-3) it cannot verify.
        config(['civil_lookup.credentials_file' => '']);
        $this->assertInstanceOf(RegistryReleasePinVerifier::class, new CivilRegistryReader);
        $this->expectException(CivilLookupException::class);
        (new CivilRegistryReader)->releasePinHeld(12345, 678);
    }

    public function test_the_documented_dba3_contract_names_the_keys_the_code_takes(): void
    {
        $sql = self::documentedDba3();
        $pin = [(Pin::PIN_KEY >> 32) & 0xFFFFFFFF, Pin::PIN_KEY & 0xFFFFFFFF];
        $this->assertStringContainsString("pin.classid = {$pin[0]}::oid AND pin.objid = {$pin[1]}::oid AND pin.objsubid = 1", $sql);
        $this->assertStringContainsString('nonce.classid = '.Pin::NONCE_NAMESPACE.'::oid AND nonce.objid = p_nonce::oid AND nonce.objsubid = 2', $sql);
        foreach (['release_pin_held(p_holder_pid integer, p_nonce integer)', 'SECURITY DEFINER SET search_path = pg_catalog, pg_temp',
            "live.locktype = 'virtualxid'", "current_setting('transaction_isolation') = 'read committed'",
            'pin.virtualtransaction', 'pg_catalog.current_database()', 'pin.pid <> pg_catalog.pg_backend_pid()',
            'TO famboook_identity_reader'] as $part) {
            $this->assertStringContainsString($part, $sql, $part);
        }
    }

    /** The SQL block of ADR §10a "DBA-3 contract". */
    public static function documentedDba3(): string
    {
        $adr = (string) file_get_contents(base_path('../docs/adr/FB-FAMILY-ADD-01-DIRECT-ADMISSION.md'));
        preg_match('/```sql\n(\s*CREATE OR REPLACE FUNCTION identity_ref_lookup\.release_pin_held.*?)```/s', str_replace("\r\n", "\n", $adr), $m);

        return $m[1] ?? '';
    }

    public function test_nothing_binds_or_uses_the_pin_and_synthetic_fixtures_stay_in_tests(): void
    {
        $this->assertFalse(app()->bound(RegistryReleasePin::class));
        $this->assertFalse(app()->bound(RegistryReleasePinVerifier::class));
        $this->assertSame(0x4642434956303150, Pin::PIN_KEY);
        $this->assertSame('FBCIV01P', hex2bin(dechex(Pin::PIN_KEY)));
        $this->assertSame('FBPN', hex2bin(dechex(Pin::NONCE_NAMESPACE)));
        $this->assertLessThan(0x80000000, Pin::NONCE_NAMESPACE, 'a positive int4');

        // The publication key the pin must differ from, as identity_ref writes it.
        $schema = (string) file_get_contents(base_path('../deploy/sql/identity-ref/01-schema.sql'));
        $this->assertStringContainsString("pg_try_advisory_xact_lock(x'4642434956303141'::bigint)", $schema);
        $this->assertStringNotContainsString('4642434956303150', $schema, 'identity_ref is unchanged: no pin there yet (DBA-1)');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('SyntheticReleasePin', $source, $file->getPathname());
            if (! str_contains($file->getPathname(), 'RegistryAdmission') && ! str_ends_with($file->getPathname(), 'CivilRegistryReader.php')
                && ! str_ends_with($file->getPathname(), 'RegistryReleasePinUnavailableException.php')) {
                $this->assertStringNotContainsString('RegistryReleasePin', $source, $file->getPathname());
            }
        }
    }
}
