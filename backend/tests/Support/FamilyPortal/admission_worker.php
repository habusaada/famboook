<?php

/*
 * TESTS ONLY (FB-FAMILY-ADD-01 ADD-WP-07B-2c): runs ONE AdmitRegistryChildAction in its own
 * PHP process, so AdmitRegistryChildConcurrencyTest can run real concurrent admissions
 * against the PostgreSQL test cluster. Usage: php admission_worker.php <spec.json>.
 * It refuses to run outside APP_ENV=testing, uses the synthetic source, the synthetic pin
 * fixture and the test-only admissibility, and prints one JSON line: the outcome only.
 */

use App\Actions\FamilyPortal\AdmitRegistryChildAction;
use App\Exceptions\RegistryAdmissionReferenceException;
use App\Exceptions\RegistryAdmissionRefusedException;
use App\Exceptions\RegistryAdmissionUnavailableException;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamilyPortal\AdmissionRegistrySource;
use Tests\Support\FamilyPortal\PendingGatesLiftedAdmissibility;
use Tests\Support\FamilyPortal\SyntheticReleasePinFixture;
use Tests\Support\FamilyPortal\SyntheticReleasePinVerifier;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->runningUnitTests()) {
    fwrite(STDERR, "The admission worker runs in tests only.\n");
    exit(2);
}

$spec = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
config($spec['config']);
DB::purge('pgsql');
// A debug-mode renderer would record queries with bindings; the action refuses to run then.
app('events')->forget(QueryExecuted::class);

// Optional barrier: booted and ready, then wait for the test's go signal (the boot is slow).
if (isset($spec['go'], $spec['ready'])) {
    touch($spec['ready']);
    $deadline = microtime(true) + 60;
    while (! file_exists($spec['go']) && microtime(true) < $deadline) {
        usleep(20000);
    }
}

// Optional: run later than now, e.g. to present an expired (but authentic) reference.
if (isset($spec['travel'])) {
    Carbon::setTestNow(Carbon::now()->addSeconds((int) $spec['travel']));
}

$context = app(FamilyAccessResolver::class)->familyContext(User::query()->findOrFail($spec['user']));
$action = new AdmitRegistryChildAction(
    AdmissionRegistrySource::fromArray($spec['source']),
    new SyntheticReleasePinVerifier(SyntheticReleasePinFixture::connection('lookup')),
    new PendingGatesLiftedAdmissibility,
);

try {
    $outcome = $action->handle($context, new RegistryAdmissionRequest($spec['ref'], $spec['key'], $spec['declaration'], true, null));
    $result = ['result' => 'ADMITTED'] + $outcome->toArray();
} catch (RegistryAdmissionRefusedException $e) {
    $result = ['result' => $e->reason, 'audit' => $e->auditCode];
} catch (RegistryAdmissionReferenceException $e) {
    $result = ['result' => $e->reason];
} catch (RegistryAdmissionUnavailableException $e) {
    $result = ['result' => 'UNAVAILABLE', 'retryable' => $e->retryable];
}
echo json_encode($result);
