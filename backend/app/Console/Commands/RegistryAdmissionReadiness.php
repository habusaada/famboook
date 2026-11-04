<?php

namespace App\Console\Commands;

use App\Enums\FamilyRegistryAdmissionMode;
use App\Support\CivilLookup\CivilRegistryReader;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyPortal\FamilyRegistryAdmissionPolicy;
use App\Support\FamilyPortal\RegistryAdmission\CivilRegistryValues;
use App\Support\FamilyPortal\RegistryAdmission\PostgresRegistryReleasePin;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionDeclarations;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryLifeVerifier;
use App\Support\FamilyPortal\RegistryAdmission\RegistryReleasePinVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * FB-FAMILY-ADD-01 readiness (RR-02; ADR §12, §10a) — READ-ONLY. Prints one line per
 * TECHNICAL control, PASS or BLOCKED, then the controls that only an external authority
 * can close. Counts and YES / NO only: never a National ID, Family id, user id, mode
 * allowlist entry, credential or reference. Exit code 0 only when every technical
 * control passes; that is NOT an approval and activates nothing (G-07 decides).
 *
 * --probe-pin (PostgreSQL, with the civil registry bound) takes the release pin in a
 * rolled-back transaction and asks the lookup login whether it sees it: a positive answer
 * proves DBA-3 is applied and that both connections reach the same database NOW (DBA-4
 * at this moment, not under failover). It also checks the catalogue for DBA-1 and DBA-2.
 * It reads no registry record.
 */
class RegistryAdmissionReadiness extends Command
{
    protected $signature = 'famboook:registry-admission-readiness {--probe-pin : Prove the release pin end to end (PostgreSQL, registry bound)}';

    protected $description = 'Read-only readiness of FB-FAMILY-ADD-01 registry child admission: technical controls and external gates (counts only).';

    /** @var list<string> */
    private array $blocked = [];

    public function handle(): int
    {
        $this->line('FB-FAMILY-ADD-01 registry child admission — readiness (read-only)');

        $raw = config('family_registry_admission.mode');
        $mode = FamilyRegistryAdmissionPolicy::mode();
        $this->line(sprintf('  mode: %s%s', $mode->value,
            is_string($raw) && strtoupper(trim($raw)) !== $mode->value ? ' (configured value is invalid, treated as OFF)' : ''));
        if ($mode === FamilyRegistryAdmissionMode::PILOT) {
            $this->line('  pilot families allowed: '.count((array) config('family_registry_admission.pilot_family_ids')));
        }
        $permission = Permission::query()->where('name', 'family-member.admit-from-registry')->first();
        $this->line(sprintf('  roles holding the admission permission: %d; accounts holding it directly: %d',
            $permission?->roles()->count() ?? 0, $permission === null ? 0 : DB::table('model_has_permissions')->where('permission_id', $permission->getKey())->count()));

        $this->line('Technical controls:');
        $declaration = RegistryAdmissionDeclarations::active();
        $this->check('O-K / G-02', 'an APPROVED declaration is active', $declaration !== null,
            $declaration === null ? 'no approved catalogue entry is active (submission stays disabled)' : 'version '.$declaration->version);
        $this->check('G-04', 'the gender code mapping is approved', CivilRegistryValues::MAPPINGS_APPROVED, 'CivilRegistryValues::MAPPINGS_APPROVED is false');
        $this->check('G-05', 'an authoritative life verifier is bound', app()->bound(RegistryLifeVerifier::class), 'no RegistryLifeVerifier is bound (no life can be proven)');
        $bound = config('family_registry_admission.civil_registry_binding') === true;
        $this->check('SOURCE', 'the civil registry is bound as the source', $bound && app()->bound(RegistryChildSource::class), 'family_registry_admission.civil_registry_binding is false');
        $this->check('PIN', 'a release pin verifier is bound', app()->bound(RegistryReleasePinVerifier::class), 'no RegistryReleasePinVerifier is bound');
        // The lookup login is read at run time from civil_lookup.credentials_file (never printed).
        $credentials = (string) config('civil_lookup.credentials_file');
        $lookupReady = config('database.connections.'.CivilRegistryReader::CONNECTION.'.database') !== null
            && (config('database.connections.'.CivilRegistryReader::CONNECTION.'.username') !== null
                || ($credentials !== '' && is_file($credentials) && is_readable($credentials)));
        $this->check('LOOKUP', 'the lookup login is configured', $lookupReady, 'no lookup database or credentials file (CIVIL_LOOKUP_CREDENTIALS_FILE)');
        $this->check('KEY', 'the reference fingerprint key is configured', is_string(config('family_auth.fingerprint.key')) && config('family_auth.fingerprint.key') !== '', 'FAMILY_AUTH_FINGERPRINT_KEY is not set');
        $this->check('DEBUG', 'APP_DEBUG is off (no query capture)', config('app.debug') !== true, 'APP_DEBUG is on: every admission refuses to run');

        if ($this->option('probe-pin')) {
            $this->probe();
        }

        $this->line('External approvals (not checkable here; each needs its recorded decision):');
        foreach ([
            'G-01  legal basis and purpose authorization',
            'G-02  privacy policy for adult children, and approval of the declaration wording',
            'G-03  verified fatherid / motherid / mainid semantics',
            'G-04  verified gender code table (then a reviewed change of MAPPINGS_APPROVED)',
            'G-05  the official life / death source and its freshness (then a verifier package)',
            'G-06  an independent security review of the passing test evidence',
            'G-07  the activation approval: commit, mode, allowlist, DBA-1 … DBA-4 applied and verified',
            'DBA-4 one cluster and one database under all routing and failover (operations guarantee)',
        ] as $item) {
            $this->line('  [EXTERNAL] '.$item);
        }

        $this->line($this->blocked === [] ? 'Technical controls: all PASS (this approves nothing).'
            : 'Technical controls BLOCKED: '.implode(', ', array_unique($this->blocked)));

        return $this->blocked === [] ? self::SUCCESS : self::FAILURE;
    }

    private function check(string $gate, string $what, bool $passed, string $why): void
    {
        $this->line(sprintf('  [%s] %-11s %s%s', $passed ? 'PASS' : 'BLOCKED', $gate, $what, $passed ? '' : ' — '.$why));
        if (! $passed) {
            $this->blocked[] = $gate;
        }
    }

    private function probe(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! app()->bound(RegistryReleasePinVerifier::class)) {
            $this->check('DBA-1..4', 'release pin probe', false, 'needs PostgreSQL and a bound pin verifier');

            return;
        }
        $catalogue = static fn (string $sql): bool => (bool) (DB::selectOne($sql)->present ?? false);
        $this->check('DBA-1', 'publish_release() takes the pin', $catalogue(
            "SELECT EXISTS (SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
              WHERE n.nspname = 'identity_ref' AND p.proname = 'publish_release' AND p.prosrc LIKE '%4642434956303150%') AS present"),
            'identity_ref.publish_release() does not take FBCIV01P');
        $this->check('DBA-2', 'the active release pointer is guarded', $catalogue(
            "SELECT EXISTS (SELECT 1 FROM pg_catalog.pg_trigger t JOIN pg_catalog.pg_class c ON c.oid = t.tgrelid
              JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'identity_ref' AND c.relname = 'active_release' AND t.tgname = 'active_release_pinned_forward' AND t.tgenabled <> 'D') AS present"),
            'the trigger active_release_pinned_forward is missing or disabled');
        try {
            $release = app(CivilRegistrySource::class)->activeReleaseId();
            DB::beginTransaction();
            try {
                (new PostgresRegistryReleasePin(DB::connection(), app(RegistryReleasePinVerifier::class)))->pin((int) $release);
            } finally {
                DB::rollBack();
            }
            $this->check('DBA-3 / 4', 'the lookup login sees this session\'s pin now', true, '');
        } catch (Throwable $e) {
            $this->check('DBA-3 / 4', 'the lookup login sees this session\'s pin now', false, 'probe failed ('.class_basename($e).')');
        }
    }
}
