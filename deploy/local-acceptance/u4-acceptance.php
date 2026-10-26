<?php

/**
 * FB-CIVIL-01A-UI U4 — executes the LOCAL operational acceptance scenario
 * (docs/deployment/FB-CIVIL-01A-UI-U4-LOCAL-ACCEPTANCE.md §6, sections A–J)
 * against the prepared environment ONLY: 127.0.0.1:5433/famboook_u4_test.
 * SYNTHETIC data only.
 *
 * It drives the real stack the Staff Portal uses: the API (php artisan serve,
 * :8010) with real staff sessions (dev-login and password logins), the real
 * worker process (backend/identity-import-worker, clean environment), and the
 * acceptance database. Publication gates are opened only for section G/H and
 * are ALWAYS closed again in a finally block and a shutdown handler.
 *
 * Results: %LOCALAPPDATA%/famboook-u4/u4-results.json (codes, counts, ids; no secrets).
 *
 *   php deploy/local-acceptance/u4-acceptance.php
 */

use Tests\Support\IdentityRef\SyntheticIdentityExport;

const API = 'http://localhost:8010';
const UI = 'http://localhost:3010';
const PORT = 5433;
const DATABASE = 'famboook_u4_test';

$ROOT = dirname(__DIR__, 2);
$STATE = str_replace('\\', '/', rtrim((string) (getenv('FAMBOOOK_U4_STATE') ?: getenv('LOCALAPPDATA').'/famboook-u4'), '\\/'));
$U4 = 'bash '.escapeshellarg($ROOT.'/deploy/local-acceptance/u4.sh');
$RESULTS = [];
$CAPTURED = [];
$PROCS = [];
$GATE_OPENED = false;

require $ROOT.'/backend/vendor/autoload.php';

// ---------------------------------------------------------------- helpers

function say(string $line): void
{
    fwrite(STDOUT, $line.PHP_EOL);
}

/** A case that could not be executed here (environment), recorded as BLOCKED — never as PASS. */
function blocked(string $section, string $case, string $detail): void
{
    global $RESULTS;
    $RESULTS[] = ['section' => $section, 'case' => $case, 'result' => 'BLOCKED', 'detail' => $detail];
    say(sprintf('BLOCKED %-3s %s — %s', $section, $case, $detail));
}

/** PIDs of running identity import worker processes (php.exe running identity-import-worker). */
function workerPids(): array
{
    $script = <<<'PS'
        $ProgressPreference = 'SilentlyContinue'; Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*identity-import-worker*' } | ForEach-Object { $_.ProcessId }
        PS;
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    $out = (string) shell_exec("powershell -NoProfile -EncodedCommand {$encoded}");

    preg_match_all('/^\s*(\d+)\s*$/m', $out, $m);   // whole-number lines only

    return array_map('intval', $m[1]);
}

/** Hard-kills the worker PHP process itself (Windows equivalent of SIGKILL). */
function killWorker(): void
{
    foreach (workerPids() as $pid) {
        exec("taskkill /F /PID {$pid} 2>NUL");
    }
}

function check(string $section, string $case, bool $ok, string $detail = ''): bool
{
    global $RESULTS;
    $RESULTS[] = ['section' => $section, 'case' => $case, 'result' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
    say(sprintf('%s %-3s %s%s', $ok ? 'PASS' : 'FAIL', $section, $case, $detail === '' ? '' : " — {$detail}"));

    return $ok;
}

function envFile(string $name): array
{
    global $STATE;
    $out = [];
    foreach (file("{$STATE}/{$name}", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] !== '#' && str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $out[$k] = $v;
        }
    }

    return $out;
}

function setEnvKey(string $name, string $key, ?string $value): void
{
    global $STATE;
    $lines = array_filter(file("{$STATE}/{$name}", FILE_IGNORE_NEW_LINES), fn ($l) => ! str_starts_with($l, "{$key}="));
    if ($value !== null) {
        $lines[] = "{$key}={$value}";
    }
    file_put_contents("{$STATE}/{$name}", implode(PHP_EOL, $lines).PHP_EOL);
}

/** A PDO session; refuses anything but the acceptance database on the disposable cluster. */
function pg(string $user, string $password = '', bool $guard = true): PDO
{
    $pdo = new PDO('pgsql:host=127.0.0.1;port='.PORT.';dbname='.DATABASE, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $me = $pdo->query('SELECT current_database() AS db, inet_server_port() AS port')->fetch(PDO::FETCH_ASSOC);
    if ($guard && ($me['db'] !== DATABASE || (int) $me['port'] !== PORT)) {
        fwrite(STDERR, "[ABORT_NON_ACCEPTANCE_DATABASE]\n");
        exit(2);
    }

    return $pdo;
}

function dba(): PDO
{
    static $pdo = null;

    return $pdo ??= pg('postgres');
}

function one(string $sql, array $b = []): mixed
{
    $st = dba()->prepare($sql);
    $st->execute($b);

    return $st->fetchColumn();
}

function sqlState(PDO $pdo, string $sql): ?string
{
    try {
        $pdo->exec($sql);

        return null;
    } catch (PDOException $e) {
        return $e->errorInfo[0] ?? (string) $e->getCode();
    }
}

function waitUntil(callable $cond, int $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if ($cond()) {
            return true;
        }
        usleep(200_000);
    }

    return false;
}

function start(string $name, string $command, string $log): void
{
    global $PROCS;
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    $PROCS[$name] = $proc;
}

function stop(string $name): void
{
    global $PROCS;
    if (! isset($PROCS[$name])) {
        return;
    }
    $pid = proc_get_status($PROCS[$name])['pid'];
    exec("taskkill /T /F /PID {$pid} 2>NUL");
    proc_close($PROCS[$name]);
    unset($PROCS[$name]);
}

function startApi(): void
{
    global $U4, $STATE;
    start('api', "{$U4} api", "{$STATE}/u4-api.log");
    if (! waitUntil(fn () => http(null, 'GET', '/api/v1/civil-registry/overview')[0] === 401, 90)) {
        throw new RuntimeException('API_NOT_READY');
    }
}

function restartApi(): void
{
    stop('api');
    // php -S may outlive its parent shell on Windows: free the port.
    exec('powershell -NoProfile -Command "Get-NetTCPConnection -State Listen -LocalPort 8010 -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }"');
    startApi();
}

function workerOnce(): string
{
    global $U4;

    return (string) shell_exec("{$U4} worker --once 2>&1");
}

/** Gate change in the acceptance state files + role re-seed (u4.sh), then API restart. */
function gate(string $state): void
{
    global $U4, $GATE_OPENED;
    shell_exec("{$U4} gate {$state} 2>&1");
    $GATE_OPENED = $state === 'open';
    restartApi();
}

/** One staff session (cookie jar), as the Staff Portal: Origin/Referer of :3010, XSRF header on writes. */
final class Staff
{
    public string $jar;

    public function __construct(public string $who)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'u4jar');
    }

    public function devLogin(): int
    {
        return http($this, 'GET', '/dev-login')[0];
    }

    public function login(string $email, string $password): int
    {
        http($this, 'GET', '/sanctum/csrf-cookie');

        return http($this, 'POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password])[0];
    }

    public function xsrf(): ?string
    {
        foreach (file($this->jar) ?: [] as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) === 7 && $parts[5] === 'XSRF-TOKEN') {
                return urldecode($parts[6]);
            }
        }

        return null;
    }
}

/** @return array{0: int, 1: mixed, 2: string} status, decoded JSON, raw body */
function http(?Staff $s, string $method, string $path, ?array $body = null, string $base = API): array
{
    global $CAPTURED;
    if ($s !== null && $method !== 'GET') {
        http($s, 'GET', '/sanctum/csrf-cookie');
    }
    $ch = curl_init($base.$path);
    $headers = ['Accept: application/json', 'Origin: '.UI, 'Referer: '.UI.'/'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($s !== null && $method !== 'GET' && ($x = $s->xsrf()) !== null) {
        $headers[] = 'X-XSRF-TOKEN: '.$x;
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    if ($s !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $s->jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $s->jar);
    }
    $raw = curl_exec($ch);
    $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $raw = $raw === false ? '' : (string) $raw;
    if ($base === API && ! str_contains($path, '/locations') && $path !== '/dev-login' && $path !== '/api/v1/auth/login') {
        $CAPTURED[] = ['what' => "{$method} {$path} {$status}", 'raw' => $raw];
    }

    return [$status, json_decode($raw, true), $raw];
}

/** Queues through the API (expects 202), runs one worker iteration, returns the finished request. */
function queueAndRun(Staff $s, string $path, array $body = []): array
{
    [$status, $json] = http($s, 'POST', $path, $body);
    if ($status !== 202) {
        throw new RuntimeException("QUEUE_FAILED {$path} {$status} ".json_encode($json['code'] ?? null));
    }
    workerOnce();

    return http($s, 'GET', '/api/v1/civil-registry/requests/'.$json['data']['id'])[1]['data'];
}

function run(Staff $s, string $runId): array
{
    return http($s, 'GET', "/api/v1/civil-registry/imports/{$runId}")[1]['data'];
}

function sourceId(Staff $s, string $label): string
{
    foreach (http($s, 'GET', '/api/v1/civil-registry/sources')[1]['data'] as $src) {
        if ($src['label'] === $label) {
            return $src['id'];
        }
    }
    throw new RuntimeException("NO_SOURCE {$label}");
}

function activeRelease(): ?int
{
    $id = one('SELECT release_id FROM identity_ref.active_release WHERE id = 1');

    return $id === false || $id === null ? null : (int) $id;
}

/** Stage → prepare → verify through the API and the worker; returns [runId, releaseId]. */
function verifiedCandidate(Staff $s, string $label): array
{
    $stage = queueAndRun($s, '/api/v1/civil-registry/imports', ['source_id' => sourceId($s, $label)]);
    $prepare = queueAndRun($s, "/api/v1/civil-registry/imports/{$stage['run_id']}/prepare");
    $verify = queueAndRun($s, "/api/v1/civil-registry/releases/{$prepare['release_id']}/verify");
    if ($stage['status'] !== 'SUCCEEDED' || $prepare['status'] !== 'SUCCEEDED' || $verify['status'] !== 'SUCCEEDED') {
        throw new RuntimeException("CANDIDATE_FAILED {$label}");
    }

    return [$stage['run_id'], (int) $prepare['release_id']];
}

function requestPublication(Staff $s, int $releaseId): array
{
    return http($s, 'POST', "/api/v1/civil-registry/releases/{$releaseId}/publication-requests", ['justification_code' => 'ROUTINE_REFRESH']);
}

/** The operator's shell publication (CLI, import login) — used to change the baseline (H.3). */
function cliPublish(int $releaseId): string
{
    global $ROOT, $STATE;
    $env = envFile('web.env');
    $worker = envFile('worker.env');
    $env['IDENTITY_REF_DB_USERNAME'] = $worker['IDENTITY_REF_DB_USERNAME'];
    $env['IDENTITY_REF_DB_PASSWORD'] = $worker['IDENTITY_REF_DB_PASSWORD'];
    $proc = proc_open([PHP_BINARY, 'artisan', 'famboook:identity-ref-stage', '--publish', "--release={$releaseId}", '--target='.DATABASE, '--yes'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $ROOT.'/backend', $env + getenv());
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    proc_close($proc);

    return $out;
}

function cleanup(): void
{
    global $GATE_OPENED, $U4, $PROCS;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    setEnvKey('worker.env', 'CIVIL_IMPORT_CHUNK_SIZE', '20');
    if ($GATE_OPENED || envFile('web.env')['CIVIL_IMPORT_PUBLISH_ENABLED'] !== 'false' || envFile('worker.env')['CIVIL_IMPORT_PUBLISH_ENABLED'] !== 'false') {
        shell_exec("{$U4} gate close 2>&1");
        $GATE_OPENED = false;
        say('cleanup: publication gates CLOSED');
    }
    foreach (array_keys($PROCS) as $name) {
        stop($name);
    }
    exec('powershell -NoProfile -Command "Get-NetTCPConnection -State Listen -LocalPort 8010,3010 -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }"');
}
register_shutdown_function('cleanup');

// ================================================================ A. environment and boundaries
$web = envFile('web.env');
$wk = envFile('worker.env');
foreach ([$web, $wk] as $e) {
    if (($e['DB_HOST'] ?? '') !== '127.0.0.1' || ($e['DB_PORT'] ?? '') !== (string) PORT || ($e['DB_DATABASE'] ?? '') !== DATABASE) {
        fwrite(STDERR, "[ABORT_NON_ACCEPTANCE_TARGET]\n");
        exit(2);
    }
}
$monitorCredentials = require $web['CIVIL_IMPORT_MONITOR_CREDENTIALS_FILE'];

try {
    check('A', '1 state files target 127.0.0.1:5433/famboook_u4_test', true);
    check('A', '1 gates closed and no authorization reference', $web['CIVIL_IMPORT_PUBLISH_ENABLED'] === 'false'
        && $wk['CIVIL_IMPORT_PUBLISH_ENABLED'] === 'false' && ! isset($wk['IDENTITY_REF_PRODUCTION_APPROVAL']));
    $verify = (string) shell_exec("{$U4} verify 2>&1");
    check('A', '2 verify-permissions passes, publication gate DISABLED', str_contains($verify, 'verification passed')
        && str_contains($verify, 'Civil import publication gate: DISABLED'));

    // Every login: the acceptance database, not a superuser, exactly one group.
    $logins = ['app' => [$web['DB_USERNAME'], $web['DB_PASSWORD'], null], 'monitor' => [$monitorCredentials['username'], $monitorCredentials['password'], 'famboook_identity_monitor'],
        'control' => [$wk['DB_USERNAME'], $wk['DB_PASSWORD'], 'famboook_civil_control'], 'import' => [$wk['IDENTITY_REF_DB_USERNAME'], $wk['IDENTITY_REF_DB_PASSWORD'], 'famboook_identity_writer']];
    $sessions = [];
    foreach ($logins as $name => [$user, $password, $group]) {
        $pdo = pg($user, $password);
        $me = $pdo->query("SELECT session_user AS u, (SELECT rolsuper FROM pg_roles WHERE rolname = session_user) AS su,
            (SELECT string_agg(g.rolname, ',' ORDER BY g.rolname) FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid JOIN pg_roles r ON r.oid = m.member WHERE r.rolname = session_user) AS groups")->fetch(PDO::FETCH_ASSOC);
        check('A', "3 {$name} login: acceptance DB, not superuser, groups=".($me['groups'] ?? '-'), ! $me['su'] && ($me['groups'] ?? null) === $group);
        $sessions[$name] = $pdo;
    }
    check('A', '4 application login has no identity access (42501)', sqlState($sessions['app'], 'SELECT 1 FROM identity_ref.import_runs') === '42501'
        && sqlState($sessions['app'], 'SELECT 1 FROM identity_ref_monitor.runs') === '42501');
    $sessions['monitor']->exec('SET ROLE famboook_identity_monitor');
    check('A', '4 monitor reads views only', sqlState($sessions['monitor'], 'SELECT 1 FROM identity_ref_monitor.runs') === null
        && sqlState($sessions['monitor'], 'SELECT 1 FROM identity_ref.records') === '42501');
    $sessions['control']->exec('SET ROLE famboook_civil_control');
    check('A', '4 control plane cannot read users or identity', sqlState($sessions['control'], 'SELECT 1 FROM users') === '42501'
        && sqlState($sessions['control'], 'SELECT 1 FROM identity_ref.import_runs') === '42501');
    $sessions['import']->exec('SET ROLE famboook_identity_writer');
    check('A', '4 import login cannot read the control plane', sqlState($sessions['import'], 'SELECT 1 FROM civil_import_requests') === '42501');
    $sessions = [];

    startApi();
    start('ui', "{$U4} ui", "{$STATE}/u4-ui.log");
    check('A', '5 API health: unauthenticated civil-registry call refused', http(null, 'GET', '/api/v1/civil-registry/overview')[0] === 401);
    $worker = workerOnce();
    check('A', '3 worker starts through its entry point', str_contains($worker, 'civil import worker u4-worker started'), trim(strtok($worker, "\n")));

    // ============================================================ B. authentication and roles
    $accounts = [];
    foreach (file("{$STATE}/accounts.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] !== '#') {
            [$email, , $password] = explode("\t", $line);
            $accounts[$email] = $password;
        }
    }
    $dev = new Staff('dev');
    $approver = new Staff('approver');
    $viewer = new Staff('viewer');
    $outsider = new Staff('outsider');
    check('B', '0 dev-login (requester, SUPER_ADMIN)', $dev->devLogin() === 200);
    check('B', '0 password logins', $approver->login('u4-approver@famboook.test', $accounts['u4-approver@famboook.test']) === 200
        && $viewer->login('u4-viewer@famboook.test', $accounts['u4-viewer@famboook.test']) === 200
        && $outsider->login('u4-outsider@famboook.test', $accounts['u4-outsider@famboook.test']) === 200);
    $accounts = [];
    // A request left RUNNING by an earlier, interrupted attempt (its worker was killed) is recovered as
    // WORKER_LOST, and a RUNNING run of that attempt is aborted — as an operator would.
    if (one("SELECT count(*) FROM civil_import_requests WHERE status = 'RUNNING'") > 0) {
        dba()->exec("UPDATE civil_import_requests SET heartbeat_at = (now() AT TIME ZONE 'UTC') - interval '30 minutes' WHERE status = 'RUNNING'");
        workerOnce();
        check('B', '9 request of the earlier attempt recovered WORKER_LOST', (int) one("SELECT count(*) FROM civil_import_requests WHERE status IN ('QUEUED', 'RUNNING')") === 0);
    }
    $leftover = one("SELECT id FROM identity_ref.import_runs WHERE status = 'RUNNING'");
    if ($leftover) {
        $prior = queueAndRun($dev, "/api/v1/civil-registry/imports/{$leftover}/abort", ['reason_code' => 'OPERATOR_DECISION']);
        check('B', '9 leftover run of the earlier attempt aborted', $prior['status'] === 'SUCCEEDED', (string) $leftover);
    }

    $overview = http($dev, 'GET', '/api/v1/civil-registry/overview')[1]['data'];
    // --once ends with STOPPED, so a just-finished iteration shows STALE; a running loop shows IDLE / BUSY.
    check('A', '3 overview shows the worker from its heartbeat', $overview['worker']['state'] !== 'NOT_INSTALLED'
        && $overview['worker']['last_seen_at'] !== null && strtotime($overview['worker']['last_seen_at']) > time() - 300, $overview['worker']['state']);
    $paths = ['/overview', '/imports', '/sources', '/releases', '/requests', '/publication-requests', '/audit'];
    $matrix = [
        'outsider' => [$outsider, [403, 403, 403, 403, 403, 403, 403]],
        'viewer' => [$viewer, [200, 200, 403, 200, 200, 200, 403]],
        'dev' => [$dev, [200, 200, 200, 200, 200, 200, 200]],
    ];
    foreach ($matrix as $who => [$s, $expected]) {
        $got = array_map(fn ($p) => http($s, 'GET', '/api/v1/civil-registry'.$p)[0], $paths);
        check('B', "1-3 {$who} read access ".implode(',', $got), $got === $expected);
    }
    $fake = '00000000-0000-4000-8000-000000000000';
    check('B', '2 viewer cannot queue or request', http($viewer, 'POST', "/api/v1/civil-registry/sources/{$fake}/validate")[0] === 403
        && http($viewer, 'POST', '/api/v1/civil-registry/releases/1/publication-requests', ['justification_code' => 'ROUTINE_REFRESH'])[0] === 403);
    check('B', '2 approver cannot approve while the gate is closed (no permission)',
        http($approver, 'POST', "/api/v1/civil-registry/publication-requests/{$fake}/approve", ['confirm_release_id' => 1, 'confirm_expected_presence' => 1])[0] === 403);
    $uiOk = waitUntil(fn () => http(null, 'GET', '/login', null, UI)[0] === 200
        || str_contains((string) @file_get_contents("{$STATE}/u4-ui.log"), 'Another next dev server is already running'), 180);
    $pages = [];
    foreach (['/login', '/dev-login', '/administration', '/administration/civil-registry', '/administration/civil-registry/imports',
        '/administration/civil-registry/imports/new', '/administration/civil-registry/releases', '/administration/civil-registry/approvals',
        '/administration/civil-registry/audit'] as $page) {
        $pages[$page] = http(null, 'GET', $page, null, UI)[0];
    }
    if (str_contains((string) @file_get_contents("{$STATE}/u4-ui.log"), 'Another next dev server is already running')) {
        blocked('B', '4 Staff Portal pages on :3010', 'another next dev server (not started by this run) is running from the same frontend directory on :3000; not stopped');
    } else {
        check('B', '4 Staff Portal pages served (client-rendered; permission states: Vitest)', $uiOk && ! array_diff($pages, [200]), json_encode($pages));
    }

    // ============================================================ C. sources and validation
    [$st, $src, $rawSources] = http($dev, 'GET', '/api/v1/civil-registry/sources');
    $labels = array_column($src['data'], 'label');
    sort($labels);
    check('C', '1 sources listed by label', array_diff(['u4-export-a', 'u4-export-b', 'u4-export-invalid-manifest', 'u4-export-tampered'], $labels) === [], implode(',', $labels));
    check('C', '1 no path, file name or checksum in the listing', ! str_contains($rawSources, $STATE) && ! str_contains($rawSources, 'famboook-u4')
        && ! str_contains($rawSources, 't03maindata_part') && preg_match('/[0-9a-f]{64}/', $rawSources) !== 1);
    $invalid = array_values(array_filter($src['data'], fn ($x) => $x['label'] === 'u4-export-invalid-manifest'))[0];
    check('C', '1 invalid manifest catalogued INVALID with codes only', $invalid['manifest_status'] === 'INVALID'
        && array_key_exists('MANIFEST_COLUMNS_MISMATCH', $invalid['manifest_problems']));
    [$s409, $j409] = http($dev, 'POST', "/api/v1/civil-registry/sources/{$invalid['id']}/validate");
    check('C', '1 invalid source cannot be validated or staged', $s409 === 409 && $j409['code'] === 'SOURCE_NOT_READY');
    $tampered = queueAndRun($dev, '/api/v1/civil-registry/sources/'.sourceId($dev, 'u4-export-tampered').'/validate');
    check('C', '2 tampered file: validation FAILED, one problem by code', $tampered['status'] === 'FAILED' && $tampered['reason_code'] === 'SOURCE_INVALID'
        && array_sum($tampered['result']['problems'] ?? []) === 1, json_encode($tampered['result']['problems'] ?? []));
    $valid = queueAndRun($dev, '/api/v1/civil-registry/sources/'.sourceId($dev, 'u4-export-a').'/validate');
    check('C', '3 export A validates: 2 files, 100 rows, no problems', $valid['status'] === 'SUCCEEDED' && $valid['result']['files'] === 2
        && $valid['result']['total_rows'] === 100 && $valid['result']['problems'] === []);

    // ============================================================ D. staging, cancellation, hard kill, resume, abort (export C)
    // A larger dedicated source (5000 rows) so that staging with 1-row chunks lasts long enough to cancel and to kill.
    $c = SyntheticIdentityExport::generated(5, 1000, 900_500_001);
    $cDir = "{$STATE}/sources/u4-export-d";
    @mkdir($cDir, 0700);
    foreach (scandir($c->dir) as $entry) {   // same content as an earlier attempt: generated deterministically
        if ($entry !== '.' && $entry !== '..') {
            copy($c->path($entry), "{$cDir}/{$entry}");
        }
    }
    $c->delete();
    workerOnce();
    $cId = sourceId($dev, 'u4-export-d');
    setEnvKey('worker.env', 'CIVIL_IMPORT_CHUNK_SIZE', '1');   // slow, many committed chunks

    // D.3 cancel a RUNNING staging at a committed chunk.
    $req = http($dev, 'POST', '/api/v1/civil-registry/imports', ['source_id' => $cId])[1]['data']['id'];
    start('worker-cancel', "{$U4} worker --once", "{$STATE}/u4-worker-cancel.log");
    waitUntil(fn () => (int) one('SELECT count(*) FROM identity_ref_staging.file_chunks') >= 10, 120);
    [$cs, $cj] = http($dev, 'DELETE', "/api/v1/civil-registry/requests/{$req}");
    check('D', '3 cancelling a running staging is recorded (202)', $cs === 202 && $cj['data']['cancel_requested'] === true && $cj['data']['status'] === 'RUNNING');
    waitUntil(fn () => one('SELECT status FROM civil_import_requests WHERE id = ?', [$req]) !== 'RUNNING', 120);
    stop('worker-cancel');
    $cancelled = http($dev, 'GET', "/api/v1/civil-registry/requests/{$req}")[1]['data'];
    $cRun = $cancelled['run_id'];
    $staged1 = (int) one('SELECT count(*) FROM identity_ref_staging.rows f JOIN identity_ref.import_files i ON i.id = f.file_id WHERE i.run_id = ?', [$cRun]);
    $detail = run($dev, $cRun);
    check('D', '3 CANCELLED at a checkpoint; run resumable', $cancelled['status'] === 'CANCELLED' && $cancelled['reason_code'] === 'CANCELLED_BY_OPERATOR'
        && $detail['phase'] === 'STAGING_INTERRUPTED' && $detail['allowed_actions'] === ['STAGE', 'ABORT'] && $staged1 < 5000, "staged {$staged1}/5000");

    // D.4 a REAL worker process killed hard (taskkill /F, like SIGKILL) mid-staging.
    $req = http($dev, 'POST', '/api/v1/civil-registry/imports', ['source_id' => $cId])[1]['data']['id'];
    start('worker-kill', "{$U4} worker --once", "{$STATE}/u4-worker-kill.log");
    $pids = [];
    waitUntil(function () use (&$pids) {
        return ($pids = workerPids()) !== [];
    }, 60);
    waitUntil(fn () => (int) one('SELECT count(*) FROM identity_ref_staging.rows') >= $staged1 + 20, 120);
    foreach ($pids as $pid) {
        exec("taskkill /F /PID {$pid} 2>NUL");   // immediately: the PID is already known
    }
    $gone = waitUntil(fn () => workerPids() === [], 15);
    stop('worker-kill');
    check('D', '4 the worker PHP process was killed hard (taskkill /F)', $gone);
    $staged2 = (int) one('SELECT count(*) FROM identity_ref_staging.rows');
    $total = 5000;
    check('D', '4 killed worker: request stays RUNNING, run RUNNING, partial staging', one('SELECT status FROM civil_import_requests WHERE id = ?', [$req]) === 'RUNNING'
        && one("SELECT status FROM identity_ref.import_runs WHERE id = ?", [$cRun]) === 'RUNNING' && $staged2 > $staged1 && $staged2 < $total, "staged {$staged2}/{$total}");
    check('D', '4 the import advisory lock died with the killed session', waitUntil(fn () => (int) one("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory'") === 0, 30));
    // The stale threshold is 10 minutes: backdate the heartbeat (acceptance-only DBA step) instead of waiting.
    dba()->exec("UPDATE civil_import_requests SET heartbeat_at = (now() AT TIME ZONE 'UTC') - interval '30 minutes' WHERE id = '{$req}'");   // naive UTC, as the application
    workerOnce();
    $lost = http($dev, 'GET', "/api/v1/civil-registry/requests/{$req}")[1]['data'];
    check('D', '4 next worker closes it WORKER_LOST', $lost['status'] === 'FAILED' && $lost['reason_code'] === 'WORKER_LOST');
    setEnvKey('worker.env', 'CIVIL_IMPORT_CHUNK_SIZE', '20');
    $resumed = queueAndRun($dev, '/api/v1/civil-registry/imports', ['source_id' => $cId]);
    check('D', '3/4 resume completes from checkpoints without duplicates', $resumed['status'] === 'SUCCEEDED' && ($resumed['result']['resumed'] ?? false) === true
        && (int) one('SELECT count(*) FROM identity_ref_staging.rows') === 5000 && (int) one('SELECT count(DISTINCT mainid) FROM identity_ref_staging.rows') === 5000);

    // ============================================================ E (C) + G.4 abort while pending
    $cPrepare = queueAndRun($dev, "/api/v1/civil-registry/imports/{$cRun}/prepare");
    $cVerify = queueAndRun($dev, "/api/v1/civil-registry/releases/{$cPrepare['release_id']}/verify");
    [$ps, $pj] = requestPublication($dev, (int) $cPrepare['release_id']);
    check('G', '4a publication request on candidate C (gate closed: requesting is allowed)', $ps === 201 && $pj['data']['status'] === 'PENDING_APPROVAL');
    $pendingC = $pj['data']['id'];
    $abort = queueAndRun($dev, "/api/v1/civil-registry/imports/{$cRun}/abort", ['reason_code' => 'OPERATOR_DECISION']);
    $pc = http($dev, 'GET', "/api/v1/civil-registry/publication-requests/{$pendingC}")[1]['data'];
    check('D', '5 abort: run ABORTED, staging purged, active release unchanged', $abort['status'] === 'SUCCEEDED'
        && run($dev, $cRun)['phase'] === 'ABORTED' && (int) one('SELECT count(*) FROM identity_ref_staging.rows') === 0 && activeRelease() === null);
    check('G', '4a pending request expires when its run is aborted', $pc['status'] === 'EXPIRED' && in_array($pc['outcome_code'], ['RELEASE_ABANDONED', 'RELEASE_CHANGED'], true), (string) $pc['outcome_code']);

    // ============================================================ E. assessment, preparation, verification (export A)
    $stageA = queueAndRun($dev, '/api/v1/civil-registry/imports', ['source_id' => sourceId($dev, 'u4-export-a')]);
    $runA = $stageA['run_id'];
    $dA = run($dev, $runA);
    check('E', '0 staging A: STAGED, files by number, actions', $stageA['status'] === 'SUCCEEDED' && $dA['phase'] === 'STAGED'
        && $dA['allowed_actions'] === ['ASSESS', 'PREPARE', 'ABORT'] && array_column($dA['files'], 'file_no') === [1, 2]);
    $assess = queueAndRun($dev, "/api/v1/civil-registry/imports/{$runA}/assess");
    check('E', '1 assessment: publishable, 100 new', $assess['status'] === 'SUCCEEDED' && $assess['result']['publishable'] === true
        && $assess['result']['counts']['new'] === 100 && $assess['result']['fatal'] === [], json_encode($assess['result']['warnings'] ?? []));
    $prepA = queueAndRun($dev, "/api/v1/civil-registry/imports/{$runA}/prepare");
    $relA = (int) $prepA['release_id'];
    $release = http($dev, 'GET', "/api/v1/civil-registry/releases/{$relA}")[1]['data'];
    check('E', '2 preparation: PREPARED under IDREF-POLICY-1', run($dev, $runA)['phase'] === 'PREPARED' && $release['preparation']['policy_id'] === 'IDREF-POLICY-1');
    queueAndRun($dev, "/api/v1/civil-registry/releases/{$relA}/verify");
    $dA = run($dev, $runA);
    check('E', '3 verification: VERIFIED, publication can be requested', $dA['phase'] === 'VERIFIED' && in_array('REQUEST_PUBLICATION', $dA['allowed_actions'], true));

    // ============================================================ F. gate CLOSED: the active release must not change
    [$ps, $pj] = requestPublication($dev, $relA);
    $pubF = $pj['data']['id'] ?? null;
    check('F', '1 request opened; run AWAITING_APPROVAL', $ps === 201 && run($dev, $runA)['phase'] === 'AWAITING_APPROVAL');
    $asApprover = http($approver, 'GET', "/api/v1/civil-registry/publication-requests/{$pubF}")[1]['data'];
    check('F', '2 approver: no approval possible (403, can_approve=false)', $asApprover['can_approve'] === false
        && http($approver, 'POST', "/api/v1/civil-registry/publication-requests/{$pubF}/approve", ['confirm_release_id' => $relA, 'confirm_expected_presence' => 100])[0] === 403);
    workerOnce();
    $ov = http($dev, 'GET', '/api/v1/civil-registry/overview')[1]['data'];
    check('F', '3 active release unchanged (none); gate shown closed', $ov['active_release'] === null && $ov['publication_enabled'] === false && activeRelease() === null);

    // ============================================================ G/H. gates OPEN — acceptance environment only
    if (envFile('web.env')['DB_DATABASE'] !== DATABASE || envFile('worker.env')['DB_DATABASE'] !== DATABASE) {
        throw new RuntimeException('ABORT_NON_ACCEPTANCE_TARGET');
    }
    try {
        gate('open');
        $w = envFile('worker.env');
        check('G', '0 gates opened (acceptance only): web + worker + reference', envFile('web.env')['CIVIL_IMPORT_PUBLISH_ENABLED'] === 'true'
            && $w['CIVIL_IMPORT_PUBLISH_ENABLED'] === 'true' && ($w['IDENTITY_REF_PRODUCTION_APPROVAL'] ?? '') === 'U4-LOCAL-ACCEPTANCE');
        $approve = fn (Staff $s, string $id, int $rel, int $presence) => http($s, 'POST', "/api/v1/civil-registry/publication-requests/{$id}/approve",
            ['confirm_release_id' => $rel, 'confirm_expected_presence' => $presence]);

        // G.1 same user
        $own = http($dev, 'GET', "/api/v1/civil-registry/publication-requests/{$pubF}")[1]['data'];
        [$ss, $sj] = $approve($dev, $pubF, $relA, 100);
        check('G', '1 requester can never approve their own request', $ss === 409 && $sj['code'] === 'SAME_USER_APPROVAL'
            && $own['is_own'] === true && $own['can_approve'] === false && $own['can_withdraw'] === true);
        // G.2 reject; no reuse
        [$rs, $rj] = http($approver, 'POST', "/api/v1/civil-registry/publication-requests/{$pubF}/reject", ['reason_code' => 'NEEDS_REVIEW']);
        check('G', '2 rejected by the second user; never reusable', $rs === 200 && $rj['data']['status'] === 'REJECTED'
            && $approve($approver, $pubF, $relA, 100)[1]['code'] === 'PUBLICATION_NOT_PENDING'
            && http($dev, 'POST', "/api/v1/civil-registry/publication-requests/{$pubF}/withdraw")[1]['code'] === 'PUBLICATION_NOT_PENDING');
        // G.3 withdraw
        $pubW = requestPublication($dev, $relA)[1]['data']['id'];
        [$ws, $wj] = http($dev, 'POST', "/api/v1/civil-registry/publication-requests/{$pubW}/withdraw");
        check('G', '3 withdrawn by the requester; never reusable', $ws === 200 && $wj['data']['status'] === 'WITHDRAWN'
            && $approve($approver, $pubW, $relA, 100)[1]['code'] === 'PUBLICATION_NOT_PENDING');
        // G.5 typed confirmation
        $pubA = requestPublication($dev, $relA)[1]['data']['id'];
        check('G', '5 wrong typed confirmation refused (422)', $approve($approver, $pubA, $relA, 99)[0] === 422 && $approve($approver, $pubA, $relA + 1, 100)[0] === 422);

        // H.1 the first synthetic publication, atomic
        $before = activeRelease();
        [$as, $aj] = $approve($approver, $pubA, $relA, 100);
        $queuedPhase = run($dev, $runA)['phase'];
        check('H', '1 approval queues the worker (202); nothing active yet', $as === 202 && $aj['data']['publish_request']['type'] === 'PUBLISH'
            && $queuedPhase === 'APPROVED' && activeRelease() === $before);
        workerOnce();
        $pa = http($dev, 'GET', "/api/v1/civil-registry/publication-requests/{$pubA}")[1]['data'];
        $ov = http($dev, 'GET', '/api/v1/civil-registry/overview')[1]['data'];
        check('H', '1 published atomically: release A active, 100 records, run PUBLISHED', $pa['status'] === 'EXECUTED' && activeRelease() === $relA
            && $ov['active_release']['id'] === $relA && $ov['active_release']['record_count'] === 100 && run($dev, $runA)['phase'] === 'PUBLISHED'
            && one('SELECT status FROM identity_ref.dataset_releases WHERE id = ?', [$relA]) === 'PUBLISHED',
            "active {$before} -> ".activeRelease());

        // H.2 second release B over A
        [$runB, $relB] = verifiedCandidate($dev, 'u4-export-b');
        [$bs, $bj] = requestPublication($dev, $relB);
        $snap = $bj['data']['snapshot'];
        check('H', '2 snapshot B: 15 new, 10 changed, 5 carried, 115 expected', $bs === 201 && $snap['new'] === 15 && $snap['changed'] === 10
            && $snap['carried_forward'] === 5 && $snap['expected_presence'] === 115 && $bj['data']['base_release_id'] === $relA, json_encode($snap));
        $approve($approver, $bj['data']['id'], $relB, 115);
        workerOnce();
        $ov = http($dev, 'GET', '/api/v1/civil-registry/overview')[1]['data'];
        check('H', '2 release B active, 115 records', activeRelease() === $relB && $ov['active_release']['record_count'] === 115, "active {$relA} -> ".activeRelease());

        // H.3a baseline change while pending
        [$run3, $rel3] = verifiedCandidate($dev, 'u4-export-a');
        $pub3 = requestPublication($dev, $rel3)[1]['data'];
        $cli = cliPublish($rel3);
        $p3 = http($approver, 'GET', "/api/v1/civil-registry/publication-requests/{$pub3['id']}")[1]['data'];
        check('H', '3a operator publishes from the shell: pending request EXPIRED BASE_CHANGED', str_contains($cli, 'Published') && activeRelease() === $rel3
            && $p3['status'] === 'EXPIRED' && $p3['outcome_code'] === 'BASE_CHANGED'
            && $approve($approver, $pub3['id'], $rel3, (int) $pub3['snapshot']['expected_presence'])[1]['code'] === 'PUBLICATION_EXPIRED');
        // H.3b baseline change after approval, before the worker
        [$run4, $rel4] = verifiedCandidate($dev, 'u4-export-b');
        $pub4 = requestPublication($dev, $rel4)[1]['data'];
        $approve($approver, $pub4['id'], $rel4, (int) $pub4['snapshot']['expected_presence']);
        cliPublish($rel4);
        workerOnce();
        $p4 = http($dev, 'GET', "/api/v1/civil-registry/publication-requests/{$pub4['id']}")[1]['data'];
        check('H', '3b approved then baseline changed: worker refuses BASE_CHANGED', $p4['status'] === 'FAILED' && $p4['outcome_code'] === 'BASE_CHANGED'
            && activeRelease() === $rel4);
        // H.4 missing authorization reference in the worker
        [$run5, $rel5] = verifiedCandidate($dev, 'u4-export-a');
        $pub5 = requestPublication($dev, $rel5)[1]['data'];
        $approve($approver, $pub5['id'], $rel5, (int) $pub5['snapshot']['expected_presence']);
        setEnvKey('worker.env', 'IDENTITY_REF_PRODUCTION_APPROVAL', null);
        workerOnce();
        setEnvKey('worker.env', 'IDENTITY_REF_PRODUCTION_APPROVAL', 'U4-LOCAL-ACCEPTANCE');
        $p5 = http($dev, 'GET', "/api/v1/civil-registry/publication-requests/{$pub5['id']}")[1]['data'];
        check('H', '4 no authorization reference: refused, active unchanged', $p5['status'] === 'FAILED' && $p5['outcome_code'] === 'AUTHORIZATION_MISSING'
            && activeRelease() === $rel4);
        $finalActive = activeRelease();
    } finally {
        // H.5 / guaranteed: close both gates, whatever happened above.
        gate('close');
    }
    $w = envFile('worker.env');
    $verify = (string) shell_exec("{$U4} verify 2>&1");
    check('H', '5 gates CLOSED again; verifier DISABLED', envFile('web.env')['CIVIL_IMPORT_PUBLISH_ENABLED'] === 'false'
        && $w['CIVIL_IMPORT_PUBLISH_ENABLED'] === 'false' && ! isset($w['IDENTITY_REF_PRODUCTION_APPROVAL'])
        && str_contains($verify, 'Civil import publication gate: DISABLED') && str_contains($verify, 'verification passed'));
    check('H', '5 approval impossible again (403)', http($approver, 'POST', "/api/v1/civil-registry/publication-requests/{$fake}/approve",
        ['confirm_release_id' => 1, 'confirm_expected_presence' => 1])[0] === 403);

    // ============================================================ I. audit and redaction
    $events = [];
    for ($page = 1; $page <= 20; $page++) {
        $chunk = http($dev, 'GET', "/api/v1/civil-registry/audit?page={$page}")[1];
        foreach ($chunk['data'] as $e) {
            $events[$e['event_type']] = ($events[$e['event_type']] ?? 0) + 1;
        }
        if ($page >= $chunk['meta']['last_page']) {
            break;
        }
    }
    [, $loc] = http($dev, 'GET', "/api/v1/civil-registry/imports/{$runA}/files/locations");
    $afterLoc = http($dev, 'GET', '/api/v1/civil-registry/audit')[1]['data'][0]['event_type'] ?? null;
    ksort($events);
    $needed = ['SOURCE_CATALOGUED', 'REQUEST_QUEUED', 'REQUEST_STARTED', 'REQUEST_SUCCEEDED', 'REQUEST_FAILED', 'REQUEST_CANCELLED',
        'PUBLICATION_REQUESTED', 'PUBLICATION_APPROVED', 'PUBLICATION_REJECTED', 'PUBLICATION_WITHDRAWN', 'PUBLICATION_EXPIRED',
        'PUBLICATION_REFUSED', 'RELEASE_PUBLISHED'];
    check('I', '1 audit trail holds every lifecycle event', array_diff($needed, array_keys($events)) === [], json_encode($events));
    check('I', '3 file names only through locations, and the read is audited', count($loc['data'] ?? []) === 2 && $afterLoc === 'LOCATIONS_VIEWED');
    $all = implode("\n", array_column($CAPTURED, 'raw'));
    $auditRows = json_encode(dba()->query('SELECT event_type, run_id, release_id, outcome_code, details FROM civil_import_audit_events')->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE)
        .json_encode(dba()->query('SELECT result, message FROM civil_import_requests')->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE)
        .json_encode(dba()->query('SELECT snapshot, outcome_code FROM civil_import_publication_requests')->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    $leaks = [];
    foreach (['/\b900[1-4]0\d{4}\b/' => 'national id', '/اسم-(تجريبي|معدل)/u' => 'name', '/[0-9a-f]{64}/' => 'sha256', '/U4-LOCAL-ACCEPTANCE/' => 'reference',
        '/@famboook\.test/' => 'e-mail', '/famboook-u4|AppData|\\\\Users\\\\/' => 'path', '/t03maindata_part/' => 'file name'] as $pattern => $what) {
        if (preg_match($pattern, $all) === 1 || preg_match($pattern, $auditRows) === 1) {
            $leaks[] = $what;
        }
    }
    // Where a leak occurs: the captured response (method, path, status) and an excerpt around the match.
    $where = [];
    if ($leaks !== []) {
        foreach ($CAPTURED as $c) {
            if (preg_match('/famboook-u4|AppData|\\\\Users\\\\|\b900[1-4]0\d{4}\b|[0-9a-f]{64}|U4-LOCAL-ACCEPTANCE|@famboook\.test|t03maindata_part/u', $c['raw'], $m, PREG_OFFSET_CAPTURE) === 1) {
                $where[] = $c['what'].' …'.substr($c['raw'], max(0, $m[0][1] - 60), 160).'…';
            }
        }
        if (preg_match('/famboook-u4|AppData|\\\\Users\\\\/', $auditRows) === 1) {
            $where[] = 'control-plane rows';
        }
    }
    check('I', '2 no identity value, name, path, file name, checksum, e-mail or reference in '.count($CAPTURED).' API responses and the control-plane rows',
        $leaks === [], implode(',', $leaks).($where === [] ? '' : ' | '.implode(' || ', array_slice($where, 0, 3))));

    // ============================================================ J.
    check('J', '1 final active synthetic release', $finalActive === activeRelease(), 'release '.activeRelease());
} catch (Throwable $e) {
    check('X', 'scenario aborted', false, get_class($e).': '.$e->getMessage());
} finally {
    cleanup();
    $w = envFile('worker.env');
    check('J', '1 gates closed and processes stopped at the end', envFile('web.env')['CIVIL_IMPORT_PUBLISH_ENABLED'] === 'false'
        && $w['CIVIL_IMPORT_PUBLISH_ENABLED'] === 'false' && ! isset($w['IDENTITY_REF_PRODUCTION_APPROVAL']) && ($w['CIVIL_IMPORT_CHUNK_SIZE'] ?? '') === '20');
    file_put_contents("{$STATE}/u4-results.json", json_encode($RESULTS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $failed = count(array_filter($RESULTS, fn ($r) => $r['result'] === 'FAIL'));
    say(sprintf('U4: %d checks, %d failed', count($RESULTS), $failed));
}
