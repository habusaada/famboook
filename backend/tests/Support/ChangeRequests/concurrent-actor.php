<?php

/*
 * TEST-ONLY helper for the PostgreSQL deadlock regression tests: runs ONE
 * real Domain Action (or holds a gate lock) in its OWN PHP process and
 * database session, so two transactions genuinely interleave. Started by
 * the test with proc_open; it inherits the test's database environment.
 *
 * It refuses to run unless the connection is PostgreSQL and the database
 * name ends in _test (never a development or Production database).
 *
 * Commands (argv):
 *   gate <national_id>                    hold the National ID advisory lock
 *   correct <person_id> <national_id>     CorrectNationalIdAction
 *   add-member <family_id> <national_id>  AddFamilyMemberAction (Staff path)
 *
 * Synchronization (no guessed boot times): after printing READY each actor
 * waits for one line on STDIN — "<milliseconds>" — then waits that long and
 * proceeds (the gate releases its lock; the others start their action).
 *
 * Output: "READY" when started (gate: once the lock is held), then "OK" or
 * "FAIL <exception class> <SQLSTATE or reason>". Never a value.
 */

use App\Actions\AddFamilyMemberAction;
use App\Actions\CorrectNationalIdAction;
use App\Models\Family;
use App\Models\Person;
use App\Models\RelationshipType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = static function (string $line): void {
    fwrite(STDOUT, $line.PHP_EOL);
    fflush(STDOUT);
};

if (DB::getDriverName() !== 'pgsql' || ! str_ends_with((string) DB::getDatabaseName(), '_test')) {
    $out('FAIL refused not-a-test-database');
    exit(2);
}

// Block until the test says go, then wait the given milliseconds.
$wait = static function (): void {
    $line = fgets(STDIN);
    usleep(max(0, (int) trim((string) $line)) * 1000);
};

[, $command] = $argv + [null, null];

try {
    switch ($command) {
        case 'gate':
            [, , $nationalId] = $argv;
            DB::transaction(function () use ($nationalId, $out, $wait) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['famboook.national_id:'.$nationalId]);
                $out('READY');
                $wait();
            });
            break;

        case 'correct':
            [, , $personId, $nationalId] = $argv;
            $out('READY');
            $wait();
            app(CorrectNationalIdAction::class)->handle(Person::findOrFail((int) $personId), $nationalId, null);
            break;

        case 'add-member':
            [, , $familyId, $nationalId] = $argv;
            $out('READY');
            $wait();
            app(AddFamilyMemberAction::class)->handle(Family::findOrFail((int) $familyId), [
                'full_name' => 'تسجيل موظف متزامن', 'national_id' => $nationalId, 'gender' => 'MALE',
                'relationship_type_id' => RelationshipType::where('code', 'SON')->value('id'),
            ], null);
            break;

        default:
            $out('FAIL unknown-command');
            exit(2);
    }
    $out('OK');
} catch (QueryException $e) {
    $out('FAIL '.$e::class.' '.$e->getCode());
    exit(1);
} catch (Throwable $e) {
    $out('FAIL '.$e::class);
    exit(1);
}
