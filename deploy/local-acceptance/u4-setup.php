<?php

/**
 * FB-CIVIL-01A-UI U4 — LOCAL operational acceptance environment (SYNTHETIC
 * data only). Builds, on the DISPOSABLE PostgreSQL test cluster
 * (127.0.0.1:5433 — never 5432, never Production):
 *
 *   database famboook_u4_test, owned by the throwaway application login
 *   famboook_u4_app (no identity privilege), with:
 *   - the application schema (migrations + reference seeders);
 *   - the identity schemas (00–03 as in FB-CIVIL-01A), 06-monitor, 07-control-plane;
 *   - three throwaway LOGIN roles, each a member of ONE role only:
 *       famboook_u4_monitor_login → famboook_identity_monitor  (the API's monitor)
 *       famboook_u4_control_login → famboook_civil_control     (the worker's control plane)
 *       famboook_u4_import_login  → famboook_identity_writer   (the worker's import login)
 *   - Staff test accounts (random passwords, written to the state directory only);
 *   - synthetic export sources under the state directory's source root.
 *
 * Credentials never touch backend/.env or the repository: they are written
 * to the state directory (default %LOCALAPPDATA%/famboook-u4), which the
 * launcher (u4.sh) reads. Publication is DISABLED by default.
 *
 *   php deploy/local-acceptance/u4-setup.php [--reset]
 *
 * --reset drops and recreates the acceptance database (and only it).
 */

use Tests\Support\IdentityRef\SyntheticIdentityExport;

const HOST = '127.0.0.1';
const PORT = 5433;
const DATABASE = 'famboook_u4_test';
const ADMIN = 'postgres';

$root = dirname(__DIR__, 2);
$backend = $root.DIRECTORY_SEPARATOR.'backend';
$state = rtrim((string) (getenv('FAMBOOOK_U4_STATE') ?: (getenv('LOCALAPPDATA') ?: sys_get_temp_dir()).DIRECTORY_SEPARATOR.'famboook-u4'), '\\/');
$reset = in_array('--reset', $argv, true);

function out(string $line): void
{
    fwrite(STDOUT, $line.PHP_EOL);
}

function fail(string $code): never
{
    fwrite(STDERR, "[{$code}]".PHP_EOL);
    exit(1);
}

function secret(): string
{
    return bin2hex(random_bytes(18));
}

function admin(string $db = 'postgres'): PDO
{
    return new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', HOST, PORT, $db), ADMIN, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function sql(string $name): string
{
    $path = dirname(__DIR__).'/sql/identity-ref/'.$name;
    $text = file_get_contents($path);
    if ($text === false) {
        fail('MISSING_SQL '.$name);
    }

    return $text;
}

// ---------------------------------------------------------------- guards
if (PORT === 5432 || HOST !== '127.0.0.1' || ! str_ends_with(DATABASE, '_test')) {
    fail('REFUSING_OUTSIDE_THE_DISPOSABLE_TEST_CLUSTER');
}
try {
    $cluster = admin();
} catch (PDOException) {
    fail('TEST_CLUSTER_NOT_REACHABLE (start it on port 5433 first)');
}
if ((int) $cluster->query('SELECT inet_server_port()')->fetchColumn() !== PORT) {
    fail('WRONG_CLUSTER');
}
if (! is_dir($state) && ! mkdir($state, 0700, true)) {
    fail('CANNOT_CREATE_STATE_DIRECTORY');
}

// ---------------------------------------------------------------- roles and database
$passwords = ['app' => secret(), 'monitor' => secret(), 'control' => secret(), 'import' => secret()];
$exists = (bool) $cluster->query("SELECT 1 FROM pg_database WHERE datname = '".DATABASE."'")->fetchColumn();
if ($exists && ! $reset) {
    fail('ACCEPTANCE_DATABASE_EXISTS (re-run with --reset to rebuild it)');
}
if ($exists) {
    $cluster->exec('DROP DATABASE '.DATABASE.' WITH (FORCE)');
}
$cluster->exec("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'famboook_u4_app') THEN
    CREATE ROLE famboook_u4_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS; END IF; END \$\$");
$cluster->exec("ALTER ROLE famboook_u4_app LOGIN NOSUPERUSER PASSWORD '{$passwords['app']}'");
$cluster->exec('CREATE DATABASE '.DATABASE.' OWNER famboook_u4_app');
$db = admin(DATABASE);
$db->exec('CREATE EXTENSION IF NOT EXISTS pg_trgm');

// Identity reference store exactly as a DBA applies it (FB-CIVIL-01A P1–P3), then the U1 monitor.
$db->exec(sql('00-roles.dba.sql'));
$db->exec('SET ROLE famboook_identity_owner');
foreach (['01-schema.sql', '02-staging.sql', '03-publication.sql'] as $file) {
    $db->exec(sql($file));
}
$db->exec('RESET ROLE');
$db->exec(sql('06-monitor.dba.sql'));
out('identity schemas: 00–03 + 06 applied');

// ---------------------------------------------------------------- application schema (as the application login)
$env = [
    // APP_DEBUG=false as in Production (deploy/env): error responses carry no exception details or paths.
    // CACHE_STORE=database as in Production: the publication re-authentication lockout (D-UI-12a) counts
    // failures in the cache, which must survive between requests (php artisan serve runs each in a new process).
    'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => HOST, 'DB_PORT' => (string) PORT, 'DB_DATABASE' => DATABASE,
    'DB_USERNAME' => 'famboook_u4_app', 'DB_PASSWORD' => $passwords['app'], 'CIVIL_IMPORT_PUBLISH_ENABLED' => 'false',
    'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'database', 'QUEUE_CONNECTION' => 'database',
];
$cmd = function (array $args) use ($backend, $env): void {
    $line = escapeshellarg(PHP_BINARY).' artisan '.implode(' ', array_map('escapeshellarg', $args));
    $process = proc_open($line, [1 => STDOUT, 2 => STDERR], $pipes, $backend, $env + getenv());
    if ($process === false || proc_close($process) !== 0) {
        fail('ARTISAN_FAILED '.$args[0]);
    }
};
$cmd(['migrate:fresh', '--seed', '--force', '--no-interaction']);
out('application schema: migrated and seeded as famboook_u4_app');

// U2 control plane (needs the migrated tables), then the three throwaway logins.
$db->exec(sql('07-control-plane.dba.sql'));
foreach ([['monitor', 'famboook_identity_monitor'], ['control', 'famboook_civil_control'], ['import', 'famboook_identity_writer']] as [$name, $group]) {
    $role = "famboook_u4_{$name}_login";
    $db->exec("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
        CREATE ROLE {$role} LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS; END IF; END \$\$");
    $db->exec("ALTER ROLE {$role} LOGIN NOINHERIT NOSUPERUSER PASSWORD '{$passwords[$name]}'");
    // Exactly one group: revoke anything else first.
    foreach ($db->query("SELECT g.rolname FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid JOIN pg_roles r ON r.oid = m.member WHERE r.rolname = '{$role}'")->fetchAll(PDO::FETCH_COLUMN) as $other) {
        $db->exec("REVOKE {$other} FROM {$role}");
    }
    $db->exec("GRANT {$group} TO {$role}");
    $db->exec('GRANT CONNECT ON DATABASE '.DATABASE." TO {$role}");
}
out('control plane: 07 applied; monitor / control / import logins created (one role each)');

// ---------------------------------------------------------------- Staff test accounts and synthetic sources (booted app)
putenv('FAMBOOOK_U4_STATE='.$state);
foreach ($env as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// D-UI-12a: one SUPER_ADMIN requests AND confirms publication (password re-entered); no second approver.
$accounts = [
    ['u4-operator@famboook.test', 'Publication operator (U4)', 'SUPER_ADMIN', []],
    ['u4-viewer@famboook.test', 'Monitoring viewer (U4)', 'REPORTS_VIEWER', ['civil-import.view']],
    ['u4-outsider@famboook.test', 'Administrator without civil import (U4)', 'ADMINISTRATOR', []],
];
$lines = ["# FB-CIVIL-01A-UI U4 local acceptance accounts (SYNTHETIC). Local only; never commit.",
    '# dev@famboook.test (SUPER_ADMIN, staging operator): sign in through /dev-login (no password; it can never confirm a publication).'];
foreach ($accounts as [$email, $name, $role, $direct]) {
    $password = secret();
    $user = App\Models\User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => Illuminate\Support\Facades\Hash::make($password), 'is_active' => true]);
    $user->syncRoles([$role]);
    $user->syncPermissions($direct);
    $lines[] = "{$email}\t{$role}".($direct === [] ? '' : '+'.implode(',', $direct))."\t{$password}";
}
file_put_contents($state.'/accounts.txt', implode(PHP_EOL, $lines).PHP_EOL);
out('staff accounts: '.count($accounts).' created (passwords in the state directory only)');

$sources = $state.DIRECTORY_SEPARATOR.'sources';
if (is_dir($sources)) {   // rebuilt every time: only generated synthetic exports live here
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sources, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && ! $f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
} else {
    mkdir($sources, 0700, true);
}
$place = function (SyntheticIdentityExport $export, string $label) use ($sources): string {
    $dir = $sources.DIRECTORY_SEPARATOR.$label;
    mkdir($dir, 0700);
    foreach (scandir($export->dir) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            copy($export->path($entry), $dir.DIRECTORY_SEPARATOR.$entry);
        }
    }
    $export->delete();

    return $dir;
};
// A: the first release (100 identities).
$place(SyntheticIdentityExport::generated(2, 50, 900_100_001), 'u4-export-a');
// B: the next release over A: 10 identities changed, 15 new, 5 missing (carried forward).
$place(SyntheticIdentityExport::generated(2, 55, 900_100_006, mutate: function (int $id, array $row): ?array {
    return $id <= 900_100_015 ? ['name1' => 'اسم-معدل-تجريبي'] + $row : $row;
}), 'u4-export-b');
// C: a manifest that does not match the approved columns (catalogued INVALID).
$dir = $place(SyntheticIdentityExport::generated(1, 10, 900_200_001), 'u4-export-invalid-manifest');
$manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);
$manifest['columns'] = array_reverse($manifest['columns']);
file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
// D: a file changed after the manifest was written (validation fails: checksum).
$dir = $place(SyntheticIdentityExport::generated(2, 10, 900_300_001), 'u4-export-tampered');
file_put_contents($dir.'/t03maindata_part_00002.csv', "\xEF\xBB\xBF".implode(',', App\Support\IdentityRef\IdentityManifest::COLUMNS)."\r\n");
out('synthetic sources: u4-export-a, u4-export-b, u4-export-invalid-manifest, u4-export-tampered');

// ---------------------------------------------------------------- state files (credentials)
$kv = fn (array $pairs) => implode(PHP_EOL, array_map(fn ($k, $v) => "{$k}={$v}", array_keys($pairs), $pairs)).PHP_EOL;
file_put_contents($state.'/identity-monitor.php', "<?php\n\nreturn ['username' => 'famboook_u4_monitor_login', 'password' => '{$passwords['monitor']}'];\n");
file_put_contents($state.'/web.env', "# U4 web application (php artisan serve). Generated; local only.\n".$kv($env + [
    'APP_URL' => 'http://localhost:8010', 'FRONTEND_URL' => 'http://localhost:3010',
    'SANCTUM_STATEFUL_DOMAINS' => 'localhost:3010,localhost:8010', 'SESSION_DOMAIN' => 'null', 'SESSION_COOKIE' => 'famboook_u4_session',
    'CIVIL_IMPORT_MONITOR_CREDENTIALS_FILE' => str_replace('\\', '/', $state).'/identity-monitor.php',
]));
file_put_contents($state.'/worker.env', "# U4 identity import worker (systemd EnvironmentFile equivalent). Generated; local only.\n".$kv([
    'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'LOG_CHANNEL' => 'stderr', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => HOST, 'DB_PORT' => (string) PORT, 'DB_DATABASE' => DATABASE,
    'DB_USERNAME' => 'famboook_u4_control_login', 'DB_PASSWORD' => $passwords['control'],
    'IDENTITY_REF_DB_USERNAME' => 'famboook_u4_import_login', 'IDENTITY_REF_DB_PASSWORD' => $passwords['import'],
    'CIVIL_IMPORT_TARGET_DATABASE' => DATABASE, 'CIVIL_IMPORT_SOURCE_ROOT' => str_replace('\\', '/', $sources), 'CIVIL_IMPORT_CHUNK_SIZE' => '20',
    'CIVIL_IMPORT_PUBLISH_ENABLED' => 'false',
]));
out('state: '.str_replace('\\', '/', $state).' (web.env, worker.env, identity-monitor.php, accounts.txt, sources/)');
out('publication: DISABLED (gate closed; no authorization reference)');
