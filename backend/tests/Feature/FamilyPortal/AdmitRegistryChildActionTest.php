<?php

namespace Tests\Feature\FamilyPortal;

use App\Actions\EndFamilyMembershipAction;
use App\Actions\FamilyPortal\AdmitRegistryChildAction as Action;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\LifeStatus;
use App\Exceptions\RegistryAdmissionReferenceException as RefException;
use App\Exceptions\RegistryAdmissionRefusedException as Refused;
use App\Exceptions\RegistryAdmissionUnavailableException as Unavailable;
use App\Models\ChangeRequest;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\CivilLookup\CivilRecord;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\FamilyRegistryAdmissionAudit as Audit;
use App\Support\FamilyPortal\RegistryAdmission\GatedRegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\PostgresRegistryReleasePin as Pin;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionOutcome;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRefs as Refs;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRequest as Request;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmission;
use App\Support\RelationshipTypes;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Exceptions\Renderer\Listener as DebugQueryListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\FamilyPortal\AdmissionRegistrySource;
use Tests\Support\FamilyPortal\PendingGatesLiftedAdmissibility;
use Tests\Support\FamilyPortal\SyntheticDeclaration;
use Tests\Support\FamilyPortal\SyntheticReleasePinFixture as Fixture;
use Tests\Support\FamilyPortal\SyntheticReleasePinVerifier as Verifier;
use Tests\TestCase;
use Throwable;

/**
 * FB-FAMILY-ADD-01 ADD-WP-07B-2c: the atomic admission in ONE session. Successes,
 * refusals under the locks and failures need PostgreSQL (the release pin); the context,
 * flag, declaration, reference and replay paths run on both drivers. Concurrency with
 * separate processes is AdmitRegistryChildConcurrencyTest. Synthetic data, a synthetic
 * source and the synthetic pin fixture only: no registry is read, nothing is bound.
 */
class AdmitRegistryChildActionTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const HEAD = '123456789';

    private const CHILD = '401234567';

    private const RELEASE = 7;

    private const DECLARATION = '2026-11-v1';

    private array $head;

    private FamilyAccessResult $context;

    private AdmissionRegistrySource $source;

    private ?Fixture $fixture = null;

    private ?Connection $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        // With APP_DEBUG on, Laravel's exception renderer keeps every query WITH its bindings;
        // the admission refuses to run then (asserted below). Production runs with debug off.
        app('events')->forget(QueryExecuted::class);
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        config(['family_registry_admission.mode' => 'ON', ...SyntheticDeclaration::approved(self::DECLARATION)]);

        $this->head = $this->activatedHead(self::HEAD);
        $this->head['user']->givePermissionTo(Action::PERMISSION);
        $this->context = $this->contextOf($this->head['user']);
        $this->source = new AdmissionRegistrySource(self::RELEASE);

        if ($this->pgsql()) {
            $this->fixture = new Fixture;
            $this->admin = Fixture::connection('admin');
            $this->fixture->install($this->admin, self::RELEASE);
        }
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            DB::disconnect('synthetic_pin_lookup');
            $this->fixture->uninstall($this->admin);
            DB::disconnect('synthetic_pin_admin');
        }
        parent::tearDown();
    }

    private function pgsql(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    private function requirePgsql(): void
    {
        if (! $this->pgsql()) {
            $this->markTestSkipped('An admission takes the release pin, which needs PostgreSQL.');
        }
    }

    private function contextOf($user): FamilyAccessResult
    {
        return app(FamilyAccessResolver::class)->familyContext($user);
    }

    private function action(?RegistryChildAdmissibility $admissibility = null): Action
    {
        return new Action($this->source, new Verifier(Fixture::connection('lookup')), $admissibility ?? new PendingGatesLiftedAdmissibility);
    }

    private function child(string $nationalId = self::CHILD, array $fields = []): void
    {
        $this->source->add($nationalId, ['fatherid' => self::HEAD, ...$fields]);
    }

    private function ref(string $nationalId = self::CHILD, string $link = 'FATHER', ?FamilyAccessResult $context = null): string
    {
        return Refs::issue($context ?? $this->context, self::RELEASE, $nationalId, $link);
    }

    private function request(?string $ref = null, ?string $key = null, string $declaration = self::DECLARATION, bool $accepted = true): Request
    {
        return new Request($ref ?? $this->ref(), $key ?? (string) Str::uuid(), $declaration, $accepted, '192.168.10.20');
    }

    private function admit(Request $request, ?RegistryChildAdmissibility $admissibility = null, ?FamilyAccessResult $context = null): RegistryAdmissionOutcome
    {
        return $this->action($admissibility)->handle($context ?? $this->context, $request);
    }

    /** The exception an admission throws, with the reason expected. */
    private function refused(callable $admit, string $code, ?string $audit = null): Throwable
    {
        try {
            $admit();
            $this->fail("Admitted; expected {$code}.");
        } catch (Refused $e) {
            $this->assertSame($code, $e->reason);
            if ($audit !== null) {
                $this->assertSame($audit, $e->auditCode);
            }

            return $e;
        } catch (RefException $e) {
            $this->assertSame($code, $e->reason);

            return $e;
        } catch (Unavailable $e) {
            $this->assertSame($code, 'REGISTRY_UNAVAILABLE');

            return $e;
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'persons' => Person::withTrashed()->where('national_id', 'like', '40%')->count(),
            'memberships' => FamilyMembership::query()->where('family_id', $this->head['family']->id)->where('is_household_head', false)->count(),
            'evidence' => DB::table('family_registry_admissions')->count(),
            'succeeded' => DB::table('family_registry_admission_events')->where('event_type', 'ADMISSION_SUCCEEDED')->count(),
            'activities' => DB::table('family_activities')->where('family_id', $this->head['family']->id)->count(),
        ];
    }

    private function lastEvent(): object
    {
        return DB::table('family_registry_admission_events')->orderByDesc('id')->first();
    }

    private function nothingWritten(array $before): void
    {
        $this->assertSame($before, $this->counts());
    }

    // ---------------------------------------------------------------- successes

    public function test_a_new_person_is_admitted_atomically_with_evidence_and_audit(): void
    {
        $this->requirePgsql();
        $this->child(fields: ['gender' => 'أنثى', 'maritalst' => 'متزوج', 'datebirth' => '2001-03-04T00:00:00', 'fullname' => 'ابنة-تجريبية']);
        $request = $this->request();

        $outcome = $this->admit($request);

        $this->assertFalse($outcome->replayed);
        $this->assertNotNull($outcome->memberRef);
        $person = Person::query()->where('national_id', self::CHILD)->sole();
        $this->assertSame(['ابنة-تجريبية', 'FEMALE', 'MARRIED', '2001-03-04', LifeStatus::ALIVE], [$person->full_name, $person->gender->value,
            $person->marital_status->value, $person->birth_date?->toDateString(), $person->life_status]);
        $membership = FamilyMembership::query()->where('person_id', $person->id)->sole();
        $this->assertTrue($membership->is_active && ! $membership->is_household_head);
        $this->assertSame(RelationshipTypes::required('DAUGHTER'), (int) $membership->relationship_type_id);
        $this->assertSame((int) $this->head['family']->id, (int) $membership->family_id);

        $evidence = DB::table('family_registry_admissions')->sole();
        $fingerprint = Audit::referenceFingerprint(self::CHILD);
        $this->assertSame([(int) $this->head['family']->id, (int) $person->id, (int) $membership->id, (int) $this->head['user']->id,
            (int) $this->head['person']->id, $request->clientReference, 'PERSON_CREATED', 'FATHER', $fingerprint['fingerprint'], 1, self::RELEASE, self::DECLARATION],
            [(int) $evidence->family_id, (int) $evidence->person_id, (int) $evidence->family_membership_id, (int) $evidence->admitted_by,
                (int) $evidence->admitted_by_person_id, $evidence->client_reference, $evidence->outcome_type, $evidence->parent_link,
                $evidence->reference_fingerprint, (int) $evidence->reference_key_version, (int) $evidence->release_id, $evidence->declaration_version]);
        $event = $this->lastEvent();
        $this->assertSame(['ADMISSION_SUCCEEDED', 'PERSON_CREATED', self::RELEASE, $fingerprint['fingerprint']],
            [$event->event_type, $event->outcome_code, (int) $event->release_id, $event->reference_fingerprint]);
        $this->assertSame(1, DB::table('family_activities')->where('family_id', $this->head['family']->id)->where('event_type', 'FAMILY_MEMBER_ADDED')->count());

        // No civil lookup audit or quota is used, and nothing in the rows names the child.
        $this->assertSame(0, DB::table('civil_lookup_audit_events')->count());
        $this->assertSame(0, DB::table('civil_lookup_daily_usage')->count());
        $dump = json_encode([DB::table('family_registry_admissions')->get(), DB::table('family_registry_admission_events')->get()]);
        foreach ([self::CHILD, 'ابنة-تجريبية', '192.168.10.20', $request->ref] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $dump);
        }
    }

    public function test_an_existing_registry_person_is_attached_as_recorded(): void
    {
        $this->requirePgsql();
        $existing = Person::factory()->create(['national_id' => self::CHILD, 'full_name' => 'الاسم-المسجّل', 'life_status' => LifeStatus::ALIVE->value, 'is_active' => true]);
        $this->child(fields: ['fullname' => 'اسم-مختلف-في-السجل']);

        $outcome = $this->admit($this->request());

        $this->assertNotNull($outcome->memberRef);
        $this->assertSame('الاسم-المسجّل', $existing->fresh()->full_name, 'the Person is attached, never edited');
        $this->assertSame(1, Person::query()->where('national_id', self::CHILD)->count());
        $this->assertSame('PERSON_ATTACHED', DB::table('family_registry_admissions')->value('outcome_type'));
        $this->assertSame(RelationshipTypes::required('SON'), (int) FamilyMembership::query()->where('person_id', $existing->id)->value('relationship_type_id'));
    }

    // ---------------------------------------------------------------- eligibility, fail closed

    public function test_the_operational_eligibility_admits_no_one(): void
    {
        $this->requirePgsql();
        $this->child();
        $before = $this->counts();

        $this->refused(fn () => $this->admit($this->request(), new GatedRegistryChildAdmissibility), Refused::NOT_ELIGIBLE, 'LIFE_UNVERIFIED');
        $this->nothingWritten($before);
        $this->assertSame(['ADMISSION_REFUSED', 'LIFE_UNVERIFIED'], [$this->lastEvent()->event_type, $this->lastEvent()->outcome_code]);
        $this->assertNotNull($this->lastEvent()->reference_fingerprint, 'the refused child is recorded by fingerprint');
    }

    public function test_death_and_uncertain_life_are_never_lifted(): void
    {
        $this->requirePgsql();
        $this->child('401234567', ['deathdate' => '2020-05-01T00:00:00']);
        $this->child('402234567', ['deathdate' => 'not-a-date']);
        $before = $this->counts();

        $this->refused(fn () => $this->admit($this->request($this->ref('401234567'))), Refused::NOT_ELIGIBLE, 'DECEASED');
        $this->refused(fn () => $this->admit($this->request($this->ref('402234567'))), Refused::NOT_ELIGIBLE, 'LIFE_UNVERIFIED');
        $this->nothingWritten($before);
        $this->assertSame(0, Person::query()->where('life_status', LifeStatus::DECEASED->value)->count());
    }

    public function test_every_eligibility_refusal_writes_nothing(): void
    {
        $this->requirePgsql();
        $other = $this->activatedHead('223456789');
        $cases = [];
        // E-3: not a child of the head; both parents are the head.
        $cases['NOT_A_CHILD_OF_HEAD'] = ['403234567', fn () => $this->source->add('403234567', ['fatherid' => '999999999'])];
        $cases['PARENT_LINK_INDETERMINATE'] = ['404234567', fn () => $this->child('404234567', ['motherid' => self::HEAD])];
        // E-5: an unmapped gender; a record without a name.
        $cases['GENDER_UNSUPPORTED'] = ['405234567', fn () => $this->child('405234567', ['gender' => 'غير معروف'])];
        $cases['SOURCE_INCOMPLETE'] = ['406234567', fn () => $this->child('406234567', ['fullname' => ''])];
        // E-7 / E-8: the registry holder.
        $cases['ACTIVE_MEMBERSHIP_ELSEWHERE'] = ['407234567', function () use ($other) {
            $this->child('407234567');
            FamilyMembership::factory()->create(['family_id' => $other['family']->id,
                'person_id' => Person::factory()->create(['national_id' => '407234567'])->id, 'is_household_head' => false]);
        }];
        $cases['ALREADY_MEMBER'] = ['408234567', function () {
            $this->child('408234567');
            FamilyMembership::factory()->create(['family_id' => $this->head['family']->id,
                'person_id' => Person::factory()->create(['national_id' => '408234567'])->id, 'is_household_head' => false]);
        }];
        $cases['PERSON_NOT_ATTACHABLE'] = ['409234567', function () {
            $this->child('409234567');
            Person::factory()->create(['national_id' => '409234567', 'life_status' => LifeStatus::DECEASED->value]);
        }];
        $cases['SOFT_DELETED_HOLDER'] = ['410234567', function () {
            $this->child('410234567');
            Person::factory()->create(['national_id' => '410234567'])->delete();
        }];
        // E-9: an open request for this child in this household.
        $cases['REQUEST_OPEN'] = ['411234567', function () {
            $this->child('411234567');
            ChangeRequest::factory()->create(['family_id' => $this->head['family']->id, 'type' => ChangeRequestType::ADD_FAMILY_MEMBER,
                'status' => ChangeRequestStatus::SUBMITTED, 'submitted_data' => ['full_name' => 'اسم-تجريبي', 'national_id' => '411234567', 'gender' => 'MALE', 'relationship' => 'SON']]);
        }];
        // Discovery's cross-record reasons.
        $cases['DUPLICATE_SOURCE_ROWS'] = ['412234567', function () {
            $this->child('412234567');
            $this->source->duplicated[] = '412234567';
        }];
        $cases['PARENTAGE_ANOMALY'] = ['413234567', fn () => $this->child('413234567', ['motherid' => '412234567'])];

        foreach ($cases as $reason => [$nationalId, $arrange]) {
            $arrange();
            $before = $this->counts();
            $events = DB::table('family_registry_admission_events')->count();
            $e = $this->refused(fn () => $this->admit($this->request($this->ref($nationalId))), Refused::NOT_ELIGIBLE, $reason);
            $this->assertSame(422, $e->status(), $reason);
            $this->assertSame(['message', 'code'], array_keys($e->render()->getData(true)));
            $this->assertSame('NOT_ELIGIBLE', $e->render()->getData(true)['code'], 'one public answer');
            $this->nothingWritten($before);
            $this->assertSame($events + 1, DB::table('family_registry_admission_events')->count(), "{$reason}: one refusal row");
            $this->assertSame($reason, $this->lastEvent()->outcome_code);
        }
    }

    // ---------------------------------------------------------------- context, flag, declaration (both drivers)

    public function test_context_flag_permission_and_declaration_are_checked_first_and_recorded_nowhere(): void
    {
        $this->child();
        $events = DB::table('family_registry_admission_events')->count();

        $member = $this->familyUser();
        $this->refused(fn () => $this->admit($this->request(), context: $this->contextOf($member)), Refused::FAMILY_CONTEXT_UNAVAILABLE);

        config(['family_registry_admission.mode' => 'OFF']);
        $this->refused(fn () => $this->admit($this->request()), Refused::ADMISSION_UNAVAILABLE);
        config(['family_registry_admission.mode' => 'PILOT', 'family_registry_admission.pilot_family_ids' => [999999]]);
        $this->refused(fn () => $this->admit($this->request()), Refused::ADMISSION_UNAVAILABLE);
        config(['family_registry_admission.mode' => 'ON']);
        $this->head['user']->revokePermissionTo(Action::PERMISSION);
        $this->refused(fn () => $this->admit($this->request(), context: $this->contextOf($this->head['user']->fresh())), Refused::ADMISSION_UNAVAILABLE);
        $this->head['user']->givePermissionTo(Action::PERMISSION);

        $this->refused(fn () => $this->admit($this->request(accepted: false)), Refused::DECLARATION_REQUIRED);
        $this->refused(fn () => $this->admit($this->request(declaration: '2025-01-v0')), Refused::DECLARATION_REQUIRED);
        config(['family_registry_admission.declaration_version' => null]);
        $this->refused(fn () => $this->admit($this->request()), Refused::DECLARATION_REQUIRED);

        $this->assertSame($events, DB::table('family_registry_admission_events')->count());
        $this->assertSame(0, $this->source->reads, 'the registry is not read');
    }

    public function test_an_invalid_or_old_reference_is_refused_and_recorded(): void
    {
        $this->child();
        $before = $this->counts();

        $this->refused(fn () => $this->admit($this->request('not-a-reference')), RefException::REFERENCE_INVALID);
        $this->assertSame(['ADMISSION_REFUSED', 'REFERENCE_INVALID', null], [$this->lastEvent()->event_type, $this->lastEvent()->outcome_code, $this->lastEvent()->reference_fingerprint]);

        $other = $this->activatedHead('223456789');
        $this->refused(fn () => $this->admit($this->request($this->ref(context: $this->contextOf($other['user'])))), RefException::REFERENCE_INVALID);

        $old = $this->ref();
        $this->travel(Refs::TTL_SECONDS + 1)->seconds();
        $this->refused(fn () => $this->admit($this->request($old)), RefException::REFERENCE_INVALID);
        $this->travelBack();

        $this->source->publish(self::RELEASE + 1);
        $this->refused(fn () => $this->admit($this->request()), RefException::RELEASE_CHANGED);
        $this->assertSame(['RELEASE_CHANGED', self::RELEASE], [$this->lastEvent()->outcome_code, (int) $this->lastEvent()->release_id]);
        $this->nothingWritten($before);
    }

    // ---------------------------------------------------------------- replay and idempotency (ADR §10, 2c-P §4)

    public function test_replay_scenarios_after_a_committed_admission(): void
    {
        $this->requirePgsql();
        $this->child();
        $this->child('402234567');
        $request = $this->request();
        $first = $this->admit($request);
        $after = $this->counts();

        // 1. Same key, same request: the same answer, and only a REPLAYED row.
        $replay = $this->admit($request);
        $this->assertSame([true, $first->memberRef], [$replay->replayed, $replay->memberRef]);
        $this->nothingWritten($after);
        $this->assertSame(['ADMISSION_REPLAYED', 'REPLAYED'], [$this->lastEvent()->event_type, $this->lastEvent()->outcome_code]);

        // 4. Same key, another child: a conflict, nothing written.
        $this->refused(fn () => $this->admit($this->request($this->ref('402234567'), $request->clientReference)), Refused::ADMISSION_IDEMPOTENCY_CONFLICT, 'IDEMPOTENCY_CONFLICT');
        // 4b. Same key, another (approved, RR-02) declaration version.
        config(SyntheticDeclaration::approved('2026-12-v2'));
        $this->refused(fn () => $this->admit($this->request(key: $request->clientReference, declaration: '2026-12-v2')), Refused::ADMISSION_IDEMPOTENCY_CONFLICT);
        config(SyntheticDeclaration::approved(self::DECLARATION));

        // 5. Another key, the same child: already a member.
        $this->refused(fn () => $this->admit($this->request()), Refused::NOT_ELIGIBLE, 'ALREADY_MEMBER');

        // 8. A later release: the replay reads no registry and still answers.
        $this->source->publish(self::RELEASE + 1);
        $reads = $this->source->reads;
        $this->assertTrue($this->admit($request)->replayed);
        $this->assertSame($reads, $this->source->reads);
        $this->source->publish(self::RELEASE);

        // 3. An expired reference within 24 hours still replays; beyond, it is invalid.
        $this->travel(Refs::TTL_SECONDS + 60)->seconds();
        $this->assertTrue($this->admit($request)->replayed);
        $this->travel(Refs::REPLAY_WINDOW_SECONDS)->seconds();
        $this->refused(fn () => $this->admit($request), RefException::REFERENCE_INVALID);
        $this->travelBack();

        // 9 / 10. Fingerprint key rotation: the stored version verifies while it is usable.
        $v1 = config('family_auth.fingerprint.key');
        config(['family_auth.fingerprint' => ['key' => 'test-only-family-auth-key-rotated-9876543210', 'key_version' => 2, 'previous_key' => $v1, 'previous_key_version' => 1]]);
        $this->assertTrue($this->admit($request)->replayed);
        config(['family_auth.fingerprint' => ['key' => 'test-only-family-auth-key-rotated-9876543210', 'key_version' => 2, 'previous_key' => null, 'previous_key_version' => null]]);
        $this->refused(fn () => $this->admit($request), Refused::ADMISSION_IDEMPOTENCY_CONFLICT);
        $this->useFamilyAuthKey();

        // 11. APP_KEY retired: the reference no longer opens.
        $old = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => []]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();
        $this->refused(fn () => $this->admit($request), RefException::REFERENCE_INVALID);
        config(['app.key' => $old]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();

        // 7. The membership ended: a replay answers without a member reference, and writes nothing.
        $membership = FamilyMembership::query()->where('family_id', $this->head['family']->id)->where('is_household_head', false)->sole();
        app(EndFamilyMembershipAction::class)->handle($this->head['family'], $membership->person, 'test', null);
        $ended = $this->admit($request);
        $this->assertSame([true, null], [$ended->replayed, $ended->memberRef]);
        $this->assertSame(1, DB::table('family_registry_admissions')->count(), 'never a second admission');

        // 13. The household context is gone: refused before anything.
        $this->refused(fn () => $this->admit($request, context: $this->contextOf($this->familyUser())), Refused::FAMILY_CONTEXT_UNAVAILABLE);
    }

    public function test_a_refusal_does_not_consume_the_key(): void
    {
        $this->requirePgsql();
        $this->child();
        $request = $this->request();

        $this->refused(fn () => $this->admit($request, new GatedRegistryChildAdmissibility), Refused::NOT_ELIGIBLE);
        $this->assertSame(0, DB::table('family_registry_admissions')->count());
        $this->assertFalse($this->admit($request)->replayed, 'the same key admits once eligible');
    }

    public function test_a_replay_of_stored_evidence_needs_no_registry_or_pin(): void
    {
        // Both drivers: evidence committed earlier (built row by row), then replayed.
        $this->child();
        $person = Person::factory()->create(['national_id' => self::CHILD]);
        $membership = FamilyMembership::factory()->create(['family_id' => $this->head['family']->id, 'person_id' => $person->id, 'is_household_head' => false]);
        $request = $this->request();
        $reference = Audit::referenceFingerprint(self::CHILD);
        DB::table('family_registry_admissions')->insert([
            'uuid' => (string) Str::uuid(), 'family_id' => $this->head['family']->id, 'person_id' => $person->id, 'family_membership_id' => $membership->id,
            'admitted_by' => $this->head['user']->id, 'admitted_by_person_id' => $this->head['person']->id, 'client_reference' => $request->clientReference,
            'outcome_type' => 'PERSON_ATTACHED', 'parent_link' => 'FATHER', 'reference_fingerprint' => $reference['fingerprint'],
            'reference_key_version' => $reference['key_version'], 'release_id' => self::RELEASE, 'declaration_version' => self::DECLARATION,
            'declared_at' => now(), 'created_at' => now(),
        ]);

        $outcome = $this->admit($request);
        $this->assertTrue($outcome->replayed);
        $this->assertNotNull($outcome->memberRef);
        $this->assertSame(0, $this->source->reads);
    }

    // ---------------------------------------------------------------- failures: fail closed, roll back, sanitize

    public function test_registry_and_pin_failures_answer_503_and_write_nothing(): void
    {
        $this->child();
        $before = $this->counts();

        if (! $this->pgsql()) {
            // SQLite: the pin cannot exist, so every admission fails closed.
            $this->assertFalse($this->refused(fn () => $this->admit($this->request()), 'REGISTRY_UNAVAILABLE')->retryable);
            $this->nothingWritten($before);
            $this->assertSame('REGISTRY_UNAVAILABLE', $this->lastEvent()->outcome_code);

            return;
        }

        $publisher = Fixture::connection('publisher');
        $publisher->beginTransaction();
        $publisher->select('SELECT pg_advisory_xact_lock(?::bigint)', [Pin::PIN_KEY]);
        $this->assertTrue($this->refused(fn () => $this->admit($this->request()), 'REGISTRY_UNAVAILABLE')->retryable, 'PIN_BUSY');
        $publisher->rollBack();
        DB::disconnect('synthetic_pin_publisher');
        $this->assertSame('REGISTRY_UNAVAILABLE', $this->lastEvent()->outcome_code);

        $this->fixture->publish(Fixture::connection('publisher'), self::RELEASE + 1, 200);
        $this->refused(fn () => $this->admit($this->request()), RefException::RELEASE_CHANGED);
        DB::disconnect('synthetic_pin_publisher');

        $action = new Action($this->source, new Verifier(Fixture::connection('lookup'), 'synthetic_release_pin_missing'), new PendingGatesLiftedAdmissibility);
        try {
            $action->handle($this->context, $this->request());
            $this->fail('Admitted without a verifier.');
        } catch (Unavailable $e) {
            $this->assertFalse($e->retryable);
        }
        $this->nothingWritten($before);
    }

    public function test_an_evidence_or_audit_failure_rolls_everything_back_and_logs_no_identity(): void
    {
        $this->requirePgsql();
        $this->child();
        $before = $this->counts();
        $logged = [];
        Log::shouldReceive('error')->andReturnUsing(function (string $m, array $c) use (&$logged) {
            $logged[] = $c;
        });

        foreach ([
            'evidence' => 'ALTER TABLE family_registry_admissions ADD CONSTRAINT test_refuse_evidence CHECK (false) NOT VALID',
            'success event' => "ALTER TABLE family_registry_admission_events ADD CONSTRAINT test_refuse_success CHECK (event_type <> 'ADMISSION_SUCCEEDED') NOT VALID",
        ] as $label => $ddl) {
            DB::statement('SAVEPOINT failure_case');
            DB::statement($ddl);
            try {
                $this->admit($this->request());
                $this->fail("Admitted although the {$label} insert fails.");
            } catch (Unavailable $e) {
                $this->assertSame(503, $e->render()->getStatusCode());
                $body = json_encode($e->render()->getData(true));
                foreach ([self::CHILD, 'insert into', 'family_registry', 'SQLSTATE'] as $secret) {
                    $this->assertStringNotContainsString($secret, (string) $body, $label);
                }
            }
            $this->nothingWritten($before);
            DB::statement('ROLLBACK TO SAVEPOINT failure_case');
        }
        $this->assertSame(['DATABASE_ERROR', 'DATABASE_ERROR'], array_column($logged, 'failure'));
        $this->assertStringNotContainsString(self::CHILD, json_encode($logged));
    }

    public function test_a_unique_violation_under_the_locks_rolls_back_as_a_lost_race(): void
    {
        $this->requirePgsql();
        $this->child();
        $before = $this->counts();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION test_lost_race() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'simulated race' USING ERRCODE = 'unique_violation'; END $$;
            CREATE TRIGGER test_lost_race BEFORE INSERT ON family_memberships FOR EACH ROW EXECUTE FUNCTION test_lost_race();
        SQL);

        $this->refused(fn () => $this->admit($this->request()), Refused::NOT_ELIGIBLE, 'LOST_RACE');
        $this->nothingWritten($before);
        $this->assertSame(0, Person::query()->where('national_id', self::CHILD)->count(), 'the Person created before the failure is rolled back');
        // The trigger and its function are rolled back with the test transaction.
    }

    public function test_query_capture_stops_the_admission_before_anything(): void
    {
        $this->child();
        $before = $this->counts();
        DB::enableQueryLog();
        $this->refused(fn () => $this->admit($this->request()), 'REGISTRY_UNAVAILABLE');
        DB::disableQueryLog();
        DB::flushQueryLog();

        DB::listen(fn () => null);
        $this->refused(fn () => $this->admit($this->request()), 'REGISTRY_UNAVAILABLE');
        app('events')->forget(QueryExecuted::class);

        // Laravel's own debug-mode renderer (APP_DEBUG=true) records queries with their bindings.
        (new DebugQueryListener)->registerListeners(app('events'));
        $this->refused(fn () => $this->admit($this->request()), 'REGISTRY_UNAVAILABLE');
        app('events')->forget(QueryExecuted::class);

        $this->nothingWritten($before);
        $this->assertSame(0, $this->source->reads);
    }

    public function test_test_only_admissibility_is_refused_outside_the_test_runner(): void
    {
        $verifier = new Verifier(DB::connection());
        $this->app['env'] = 'production';
        try {
            new Action($this->source, $verifier, new class implements RegistryChildAdmissibility
            {
                public function assess(FamilyAccessResult $context, CivilRecord $record, array $sourceReasons): RegistryChildAdmission
                {
                    throw new LogicException('never');
                }
            });
            $this->fail('A non-gated admissibility was accepted outside tests.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('gated', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertInstanceOf(Action::class, new Action($this->source, $verifier));
    }
}
