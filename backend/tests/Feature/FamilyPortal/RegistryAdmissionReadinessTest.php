<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\RegistryChildReason as Reason;
use App\Enums\RegistryChildStatus;
use App\Exceptions\CivilLookupException;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;
use App\Support\CivilLookup\CivilRegistryReader;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\CivilRegistryChildSource;
use App\Support\FamilyPortal\RegistryAdmission\GatedRegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\LifeStatus;
use App\Support\FamilyPortal\RegistryAdmission\LifeVerificationStatus as Says;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionBindings;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionDeclarations;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRefs;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildEligibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryLifePolicy;
use App\Support\FamilyPortal\RegistryAdmission\RegistryLifeStatus;
use App\Support\FamilyPortal\RegistryAdmission\RegistryLifeVerifier;
use App\Support\FamilyPortal\RegistryAdmission\RegistryReleasePinVerifier;
use App\Support\FamilyPortal\RegistryAdmission\SuggestedChildrenDiscovery;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\FamilyPortal\AdmissionRegistrySource;
use Tests\Support\FamilyPortal\PendingGatesLiftedAdmissibility;
use Tests\Support\FamilyPortal\SyntheticDeclaration;
use Tests\Support\FamilyPortal\SyntheticLifeVerifier;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 RR-02, on both drivers: the versioned declaration, proof of life
 * through an authoritative verifier only, references issued only when the admission
 * admits, the civil registry adapter (over a fake CivilRegistrySource), the binding
 * switch and the DBA file's agreement with the schema and the ADR. Synthetic data only;
 * the real registry is never reached. The PostgreSQL side is
 * RegistryAdmissionCivilRegistryPostgresTest.
 */
class RegistryAdmissionReadinessTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const HEAD = '123456789';

    private const CHILD = '401234567';

    private const DRAFT = '2026-11-draft-1';

    private const ADR_TEXT = 'أُقرّ بأن هذا الابن / هذه الابنة من أفراد أسرتي ويقيم/تقيم فعليًا ضمن هذه الأسرة، وأن هذا الإقرار صحيح على مسؤوليتي.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->app->instance(CivilRegistrySource::class, new class
        {
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('The civil registry must not be read.');
            }
        });
    }

    private function record(string $mainid = self::CHILD, array $fields = []): CivilRecord
    {
        return new CivilRecord(7, 1, $mainid, ['mainid' => $mainid, 'fullname' => 'اسم-تجريبي', 'gender' => 'ذكر',
            'fatherid' => self::HEAD, 'motherid' => '', 'datebirth' => '2010-01-15T00:00:00', 'deathdate' => '', 'presonstate' => '', ...$fields]);
    }

    private function headContext(): FamilyAccessResult
    {
        return app(FamilyAccessResolver::class)->familyContext($this->activatedHead(self::HEAD)['user']);
    }

    private function verifier(): SyntheticLifeVerifier
    {
        $verifier = new SyntheticLifeVerifier;
        $this->app->instance(RegistryLifeVerifier::class, $verifier);

        return $verifier;
    }

    // ---------------------------------------------------------------- declaration (O-K, G-02)

    public function test_the_shipped_catalogue_approves_only_2026_11_v1_with_the_draft_2_wording_verbatim(): void
    {
        // G-02 wording approved 2026-11-05: 2026-11-v1 carries draft-2's two texts, byte for byte.
        $catalogue = RegistryAdmissionDeclarations::catalogue();
        $this->assertSame([self::DRAFT, '2026-11-draft-2', '2026-11-v1'], array_keys($catalogue));
        $this->assertSame(['2026-11-v1'], array_keys(array_filter($catalogue, fn ($entry) => ($entry['approved'] ?? null) === true)));
        $this->assertSame(['approved', 'text', 'life_text'], array_keys($catalogue['2026-11-v1']));
        $this->assertSame($catalogue['2026-11-draft-2']['text'], $catalogue['2026-11-v1']['text']);
        $this->assertSame($catalogue['2026-11-draft-2']['life_text'], $catalogue['2026-11-v1']['life_text']);
        $this->assertSame('أُقرّ بأن هذا الابن / هذه الابنة من أفراد أسرتي ويقيم/تقيم فعليًا ضمن هذه الأسرة، وأن هذا الإقرار صحيح على مسؤوليتي.', $catalogue['2026-11-v1']['text']);
        $this->assertSame('وأُقرّ بأن هذا الابن / هذه الابنة على قيد الحياة في تاريخ هذا الإقرار، وأتحمّل مسؤولية صحة ذلك.', $catalogue['2026-11-v1']['life_text']);

        // Nothing is delivered until the environment names it (no default) …
        $this->assertNull(config('family_registry_admission.declaration_version'));
        $this->assertNull(RegistryAdmissionDeclarations::active());
        // … and once named it is delivered with its separate statement of current life.
        config(['family_registry_admission.declaration_version' => '2026-11-v1']);
        $this->assertSame(['version' => '2026-11-v1', 'text' => $catalogue['2026-11-v1']['text'], 'life_text' => $catalogue['2026-11-v1']['life_text']],
            RegistryAdmissionDeclarations::active()?->toArray(true));
        $this->assertTrue(RegistryAdmissionDeclarations::accepts('2026-11-v1'));
        $this->assertFalse(RegistryAdmissionDeclarations::accepts('2026-11-draft-2'));
        config(['family_registry_admission.declaration_version' => null]);
    }

    public function test_the_drafts_stay_unapproved_and_deliver_nothing(): void
    {
        // RR-02A adds draft-2: the same wording plus a separate statement of current life. Neither is approved.
        $this->assertFalse(RegistryAdmissionDeclarations::catalogue()[self::DRAFT]['approved']);
        $this->assertFalse(RegistryAdmissionDeclarations::catalogue()['2026-11-draft-2']['approved']);
        $this->assertSame(self::ADR_TEXT, RegistryAdmissionDeclarations::catalogue()['2026-11-draft-2']['text']);
        $this->assertStringContainsString('على قيد الحياة', RegistryAdmissionDeclarations::catalogue()['2026-11-draft-2']['life_text']);
        // The draft is the ADR §6 proposal, word for word, and its text is pinned: a new wording is a new version.
        $this->assertSame(self::ADR_TEXT, RegistryAdmissionDeclarations::catalogue()[self::DRAFT]['text']);
        $adr = (string) file_get_contents(base_path('../docs/adr/FB-FAMILY-ADD-01-DIRECT-ADMISSION.md'));
        $this->assertStringContainsString(str_replace(' / ', ' / ', self::ADR_TEXT), str_replace("\n> ", ' ', str_replace("\r\n", "\n", $adr)));
        $this->assertNull(config('family_registry_admission.declaration_version'));
        $this->assertNull(RegistryAdmissionDeclarations::active());

        // Even naming the draft as active delivers and accepts nothing.
        config(['family_registry_admission.declaration_version' => self::DRAFT]);
        $this->assertNull(RegistryAdmissionDeclarations::active());
        $this->assertFalse(RegistryAdmissionDeclarations::accepts(self::DRAFT));
    }

    public function test_only_an_approved_entry_with_a_text_is_active(): void
    {
        config(SyntheticDeclaration::approved('v-test-1'));
        $this->assertSame(['version' => 'v-test-1', 'text' => SyntheticDeclaration::TEXT, 'life_text' => null], RegistryAdmissionDeclarations::active()?->toArray());
        config(SyntheticDeclaration::approved('v-test-1', withLife: true));
        $this->assertSame(SyntheticDeclaration::LIFE_TEXT, RegistryAdmissionDeclarations::active()?->toArray(true)['life_text']);
        $this->assertNull(RegistryAdmissionDeclarations::active()?->toArray()['life_text'], 'delivered only on request');
        config(['family_registry_admission.declarations.v-test-1.life_text' => '  ']);
        $this->assertNull(RegistryAdmissionDeclarations::active(), 'an unusable life statement makes the entry unusable');
        config(SyntheticDeclaration::approved('v-test-1'));
        $this->assertTrue(RegistryAdmissionDeclarations::accepts('v-test-1'));
        $this->assertFalse(RegistryAdmissionDeclarations::accepts('v-test-2'));
        $this->assertFalse(RegistryAdmissionDeclarations::accepts('v-test-1 '));

        foreach ([
            'approved as a string' => ['approved' => 'true', 'text' => 'x'],
            'approved missing' => ['text' => 'x'],
            'no text' => ['approved' => true],
            'blank text' => ['approved' => true, 'text' => '   '],
            'not an entry' => 'x',
        ] as $label => $entry) {
            config(['family_registry_admission.declarations' => ['v-test-1' => $entry]]);
            $this->assertNull(RegistryAdmissionDeclarations::active(), $label);
        }
        config(SyntheticDeclaration::approved('v-test-1'));
        foreach ([' v-test-1', 'v-test-1 ', str_repeat('v', 41), '', null, 7] as $version) {
            config(['family_registry_admission.declaration_version' => $version]);
            $this->assertNull(RegistryAdmissionDeclarations::active(), var_export($version, true));
        }
        config(['family_registry_admission.declarations' => 'not-a-catalogue']);
        $this->assertSame([], RegistryAdmissionDeclarations::catalogue());
    }

    // ---------------------------------------------------------------- life (D-06, G-05)

    public function test_no_verifier_means_no_life_is_ever_proven(): void
    {
        $this->assertFalse($this->app->bound(RegistryLifeVerifier::class));
        $this->assertSame(LifeStatus::UNVERIFIED, RegistryLifeStatus::of($this->record()));
        $this->assertSame(LifeStatus::UNVERIFIED, RegistryLifeStatus::of($this->record(fields: ['presonstate' => 'حي'])), 'presonstate is never read');
        $this->assertSame(LifeStatus::DECEASED, RegistryLifeStatus::of($this->record(fields: ['deathdate' => '2020-02-02T00:00:00'])));
        $this->assertSame(LifeStatus::UNVERIFIED, RegistryLifeStatus::of($this->record(fields: ['deathdate' => 'غير معروف'])));
    }

    public function test_only_fresh_alive_evidence_from_the_verifier_proves_a_life(): void
    {
        $verifier = $this->verifier();
        $now = new DateTimeImmutable('@'.now()->getTimestamp());
        $cases = [
            'fresh ALIVE' => [Says::ALIVE, $now->modify('-1 hour'), LifeStatus::PROVEN_ALIVE],
            'ALIVE at the limit' => [Says::ALIVE, $now->modify('-24 hours'), LifeStatus::PROVEN_ALIVE],
            'stale ALIVE' => [Says::ALIVE, $now->modify('-25 hours'), LifeStatus::UNVERIFIED],
            'future ALIVE' => [Says::ALIVE, $now->modify('+5 minutes'), LifeStatus::UNVERIFIED],
            'undated ALIVE' => [Says::ALIVE, null, LifeStatus::UNVERIFIED],
            'UNKNOWN' => [Says::UNKNOWN, $now, LifeStatus::UNVERIFIED],
            'DECEASED' => [Says::DECEASED, $now, LifeStatus::DECEASED],
            'undated DECEASED' => [Says::DECEASED, null, LifeStatus::DECEASED],
        ];
        foreach ($cases as $label => [$says, $at, $expected]) {
            $verifier->answer(self::CHILD, $says, $at);
            $this->assertSame($expected, RegistryLifeStatus::of($this->record()), $label);
        }

        // The registry's own death evidence is never overridden, and is not even sent to the verifier.
        $verifier->answer('402234567', Says::ALIVE, $now);
        $verifier->asked = [];
        $this->assertSame(LifeStatus::DECEASED, RegistryLifeStatus::of($this->record('402234567', ['deathdate' => '2020-02-02T00:00:00'])));
        $this->assertSame(LifeStatus::UNVERIFIED, RegistryLifeStatus::of($this->record('402234567', ['deathdate' => 'xx'])));
        $this->assertSame([], $verifier->asked);

        // An invalid age limit proves nothing.
        $verifier->answer(self::CHILD, Says::ALIVE, $now);
        foreach ([0, -1, '24', null] as $hours) {
            config(['family_registry_admission.life_verification.max_age_hours' => $hours]);
            $this->assertSame(LifeStatus::UNVERIFIED, RegistryLifeStatus::of($this->record()), var_export($hours, true));
        }
    }

    public function test_a_failing_verifier_is_unverified_and_logs_no_identifier(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        $this->verifier()->fail(self::CHILD);

        $this->assertSame(LifeStatus::UNVERIFIED, RegistryLifeStatus::of($this->record()));
        $this->assertCount(1, $logged);
        $this->assertStringNotContainsString(self::CHILD, implode("\n", $logged));
    }

    public function test_a_proven_life_with_the_approved_gender_table_is_admissible(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $context = $this->headContext();
        $this->verifier()->answer(self::CHILD, Says::ALIVE, new DateTimeImmutable('@'.now()->getTimestamp()));

        $assessment = RegistryChildEligibility::evaluate($context, $this->record());
        $this->assertTrue($assessment->livingProven);
        $this->assertNotContains(Reason::LIFE_UNVERIFIED, $assessment->reasons);
        // G-04 approved: with a proven life nothing refuses.
        $this->assertSame([], $assessment->reasons);
        $this->assertSame(RegistryChildStatus::ELIGIBLE, $assessment->publicStatus());
        $admission = (new GatedRegistryChildAdmissibility)->assess($context, $this->record(), []);
        $this->assertTrue($admission->livingProven);
        $this->assertTrue($admission->admissible());
    }

    // ---------------------------------------------------------------- revised life policy (RR-02A, D-06R)

    public function test_the_revised_life_policy_is_off_by_default_and_needs_every_piece(): void
    {
        $this->assertSame('VERIFIER_ONLY', config('family_registry_admission.life_policy.mode'));
        $this->assertNull(config('family_registry_admission.life_policy.approval_reference'));
        $this->assertSame([], RegistryLifePolicy::releases());
        $this->assertFalse(RegistryLifePolicy::scopeAndDeclarationActive());

        config(SyntheticDeclaration::revisedLifePolicy('v-life-1', '7:2026-10-25'));
        $this->assertTrue(RegistryLifePolicy::scopeAndDeclarationActive());
        $this->assertSame('2026-10-25', RegistryLifePolicy::extractedOn(7));
        $this->assertNull(RegistryLifePolicy::extractedOn(8), 'an unlisted release');

        foreach ([
            'another mode' => ['family_registry_admission.life_policy.mode' => 'registry_scope_and_declaration'],
            'no approval' => ['family_registry_admission.life_policy.approval_reference' => null],
            'a malformed approval' => ['family_registry_admission.life_policy.approval_reference' => 'x y'],
            'a too short approval' => ['family_registry_admission.life_policy.approval_reference' => 'ab'],
            'a declaration without a life statement' => SyntheticDeclaration::approved('v-life-1'),
        ] as $label => $override) {
            config(SyntheticDeclaration::revisedLifePolicy('v-life-1', '7:2026-10-25'));
            config($override);
            $this->assertFalse(RegistryLifePolicy::scopeAndDeclarationActive(), $label);
            $this->assertNull(RegistryLifePolicy::extractedOn(7), $label);
        }

        config(SyntheticDeclaration::revisedLifePolicy('v-life-1', ''));
        $this->assertSame([7 => '2026-10-25', 9 => '2026-11-01'], (function () {
            config(['family_registry_admission.life_policy.living_scope_releases' => ' 7:2026-10-25 , 9:2026-11-01']);

            return RegistryLifePolicy::releases();
        })());
        foreach (['7:2026-10-25,x', '7:2026-10-25,7:2026-10-26', '7:2026-02-30', '7:'.now()->addDay()->toDateString(), '0:2026-10-25', '7-2026-10-25', '7:26-10-25'] as $raw) {
            config(['family_registry_admission.life_policy.living_scope_releases' => $raw]);
            $this->assertSame([], RegistryLifePolicy::releases(), $raw);
        }
    }

    public function test_under_the_revised_policy_an_unproven_life_is_declaration_in_scope_and_never_alive(): void
    {
        config(SyntheticDeclaration::revisedLifePolicy('v-life-1', '7:2026-10-25'));
        $this->assertSame(LifeStatus::DECLARATION_IN_SCOPE, RegistryLifeStatus::of($this->record()));
        $this->assertSame(LifeStatus::UNVERIFIED, RegistryLifeStatus::of(new CivilRecord(8, 1, self::CHILD, $this->record()->payload)), 'another release');
        $this->assertSame(LifeStatus::DECEASED, RegistryLifeStatus::of($this->record(fields: ['deathdate' => '2020-02-02T00:00:00'])), 'a known death');
        $this->assertSame(LifeStatus::UNVERIFIED, RegistryLifeStatus::of($this->record(fields: ['deathdate' => 'xx'])), 'contradictory evidence');

        // The verifier gate is never bypassed: a deceased answer or a failure refuses; only no proof falls back.
        $verifier = $this->verifier();
        $now = new DateTimeImmutable('@'.now()->getTimestamp());
        $verifier->answer(self::CHILD, Says::DECEASED, $now);
        $this->assertSame(LifeStatus::DECEASED, RegistryLifeStatus::of($this->record()));
        $verifier->fail(self::CHILD);
        $this->assertSame(LifeStatus::UNVERIFIED, RegistryLifeStatus::of($this->record()));
        $verifier->answer(self::CHILD, Says::UNKNOWN, $now);
        $this->assertSame(LifeStatus::DECLARATION_IN_SCOPE, RegistryLifeStatus::of($this->record()));
        $verifier->answer(self::CHILD, Says::ALIVE, $now->modify('-1 hour'));
        $this->assertSame(LifeStatus::PROVEN_ALIVE, RegistryLifeStatus::of($this->record()));

        // Switched off: nothing in scope.
        config(['family_registry_admission.life_policy.mode' => 'VERIFIER_ONLY']);
        $verifier->answer(self::CHILD, Says::UNKNOWN, $now);
        $this->assertSame(LifeStatus::UNVERIFIED, RegistryLifeStatus::of($this->record()));
    }

    public function test_a_declared_life_adds_no_reason_and_is_never_proven(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $context = $this->headContext();
        config(SyntheticDeclaration::revisedLifePolicy('v-life-1', '7:2026-10-25'));

        $assessment = RegistryChildEligibility::evaluate($context, $this->record());
        $this->assertFalse($assessment->livingProven);
        $this->assertTrue($assessment->livingByDeclaration);
        $this->assertSame([], $assessment->reasons);
        $admission = (new GatedRegistryChildAdmissibility)->assess($context, $this->record(), []);
        $this->assertTrue($admission->livingByDeclaration);
        $this->assertFalse($admission->livingProven, 'a declared life is never a proven one');
    }

    // ---------------------------------------------------------------- references (ADR §7a)

    public function test_discovery_issues_a_reference_only_when_asked_and_the_admission_admits(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $context = $this->headContext();
        $source = (new AdmissionRegistrySource(7))->add(self::CHILD, ['fatherid' => self::HEAD])
            ->add('402234567', ['fatherid' => self::HEAD, 'deathdate' => '2020-02-02T00:00:00']);
        $lifted = new PendingGatesLiftedAdmissibility;

        $gated = (new SuggestedChildrenDiscovery($source))->discover($context, issueRefs: true);
        $this->assertSame([null, null], array_map(fn ($c) => $c->ref, $gated->children), 'the gated admissibility admits no one');
        $notAsked = (new SuggestedChildrenDiscovery($source, $lifted))->discover($context);
        $this->assertSame([null, null], array_map(fn ($c) => $c->ref, $notAsked->children));

        $page = (new SuggestedChildrenDiscovery($source, $lifted))->discover($context, issueRefs: true);
        [$alive, $dead] = $page->children;
        $this->assertSame(RegistryChildStatus::ELIGIBLE, $alive->status);
        $this->assertIsString($alive->ref);
        $this->assertSame(RegistryChildStatus::NOT_ELIGIBLE, $dead->status);
        $this->assertNull($dead->ref, 'a deceased child never gets a reference');
        // The reference is the strict admission reference of this head, this release and this child.
        $read = RegistryAdmissionRefs::readForAdmission($alive->ref, $context);
        $this->assertSame(7, $read->releaseId);
        $this->assertStringNotContainsString(self::CHILD, $alive->ref);
    }

    // ---------------------------------------------------------------- civil registry adapter

    public function test_the_adapter_reads_the_bounded_set_in_pages_of_25_in_one_release(): void
    {
        $fake = new FakeCivilRegistry(release: 7, children: 60);
        $page = (new CivilRegistryChildSource($fake))->childrenOf(self::HEAD, 0, CivilRegistrySource::LINKS_CAP);

        $this->assertSame([[0, 25], [25, 25], [50, 25]], $fake->calls);
        $this->assertSame([7, 60, 60, 100], [$page->releaseId, $page->total, count($page->rows), $page->cap]);
        $this->assertSame(60, count(array_unique(array_map(fn (CivilRecord $r) => $r->mainid, $page->rows))));

        // Exactly the cap: four pages. Over the cap: one call, no rows, the capped total.
        $this->assertSame(100, count((new CivilRegistryChildSource(new FakeCivilRegistry(7, 100)))->childrenOf(self::HEAD, 0, 100)->rows));
        $broad = new FakeCivilRegistry(7, 101);
        $tooBroad = (new CivilRegistryChildSource($broad))->childrenOf(self::HEAD, 0, 100);
        $this->assertSame([[0, 25]], $broad->calls);
        $this->assertTrue($tooBroad->tooBroad());
        $this->assertSame([], $tooBroad->rows);
        // None at all, and no published release: one call each.
        $this->assertSame(0, (new CivilRegistryChildSource(new FakeCivilRegistry(7, 0)))->childrenOf(self::HEAD, 0, 100)->total);
        $this->assertNull((new CivilRegistryChildSource(new FakeCivilRegistry(null, 0)))->childrenOf(self::HEAD, 0, 100)->releaseId);
    }

    public function test_the_adapter_never_mixes_releases(): void
    {
        foreach (['release' => fn (FakeCivilRegistry $f) => $f->releaseAfterFirst = 8, 'total' => fn (FakeCivilRegistry $f) => $f->totalAfterFirst = 59] as $label => $change) {
            $fake = new FakeCivilRegistry(7, 60);
            $change($fake);
            try {
                (new CivilRegistryChildSource($fake))->childrenOf(self::HEAD, 0, 100);
                $this->fail("A changed {$label} must be refused.");
            } catch (CivilLookupException $e) {
                $this->assertSame(CivilLookupException::RELEASE_CHANGED, $e->reason, $label);
            }
        }

        $fake = new FakeCivilRegistry(7, 0);
        $source = new CivilRegistryChildSource($fake);
        $fake->records[self::HEAD] = $this->record(self::HEAD);
        $this->assertSame(self::HEAD, $source->recordOf(self::HEAD, 7)?->mainid);
        $this->assertNull($source->recordOf('402234567', 7), 'no record in the same release');
        foreach ([fn () => $source->recordOf(self::HEAD, 6), fn () => $source->recordOf('402234567', 6)] as $read) {
            try {
                $read();
                $this->fail('Another release must be RELEASE_CHANGED.');
            } catch (CivilLookupException $e) {
                $this->assertSame(CivilLookupException::RELEASE_CHANGED, $e->reason);
            }
        }
    }

    // ---------------------------------------------------------------- bindings

    public function test_nothing_is_bound_by_default_and_the_switch_binds_only_the_registry(): void
    {
        $this->assertFalse(config('family_registry_admission.civil_registry_binding'));
        foreach ([RegistryChildSource::class, RegistryReleasePinVerifier::class, RegistryLifeVerifier::class, RegistryChildAdmissibility::class] as $abstract) {
            $this->assertFalse($this->app->bound($abstract), $abstract);
        }

        config(['family_registry_admission.civil_registry_binding' => 'true']);
        RegistryAdmissionBindings::register($this->app);
        $this->assertFalse($this->app->bound(RegistryChildSource::class), 'only a real boolean true binds');

        $this->app->forgetInstance(CivilRegistrySource::class);
        $this->app->scoped(CivilRegistrySource::class, CivilRegistryReader::class);
        config(['family_registry_admission.civil_registry_binding' => true]);
        RegistryAdmissionBindings::register($this->app);
        $this->assertInstanceOf(CivilRegistryChildSource::class, $this->app->make(RegistryChildSource::class));
        $this->assertInstanceOf(CivilRegistryReader::class, $this->app->make(RegistryReleasePinVerifier::class));
        // G-05 and the admission rules are never bound by it.
        $this->assertFalse($this->app->bound(RegistryLifeVerifier::class));
        $this->assertFalse($this->app->bound(RegistryChildAdmissibility::class));
    }

    // ---------------------------------------------------------------- readiness command

    public function test_the_readiness_command_reports_every_open_control_and_exits_non_zero(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->artisan('famboook:registry-admission-readiness')
            ->expectsOutputToContain('mode: OFF')
            ->expectsOutputToContain('roles holding the admission permission: 0; accounts holding it directly: 0')
            ->expectsOutputToContain('[BLOCKED] O-K / G-02')
            ->expectsOutputToContain('[PASS] G-04')
            ->expectsOutputToContain('[BLOCKED] G-05 / D-06R')
            ->expectsOutputToContain('[BLOCKED] SOURCE')
            ->expectsOutputToContain('[BLOCKED] PIN')
            ->expectsOutputToContain('[EXTERNAL] G-07')
            ->assertExitCode(1);

        // Everything technical that a test can supply here: G-04 passes; the registry source is still not bound.
        config([...SyntheticDeclaration::approved('v-test-1'), 'family_registry_admission.mode' => 'PILOT',
            'family_registry_admission.pilot_family_ids' => [987654], 'app.debug' => false]);
        $this->verifier();
        $this->artisan('famboook:registry-admission-readiness', ['--probe-pin' => true])
            ->expectsOutputToContain('pilot families allowed: 1')
            ->expectsOutputToContain('[PASS] O-K / G-02')
            ->expectsOutputToContain('[PASS] G-05 / D-06R')
            ->expectsOutputToContain('[PASS] G-04')
            ->expectsOutputToContain('[BLOCKED] SOURCE')
            ->doesntExpectOutputToContain('987654')
            ->assertExitCode(1);
    }

    // ---------------------------------------------------------------- DBA file

    public function test_the_dba_file_changes_publish_release_only_by_the_pin_and_matches_the_adr(): void
    {
        $sql = static fn (string $f) => str_replace("\r\n", "\n", (string) file_get_contents(base_path('../deploy/sql/identity-ref/'.$f)));
        $function = static function (string $text): string {
            preg_match('/FUNCTION identity_ref\.publish_release\(p_release bigint\).*?\n\$\$;\n/s', $text, $m);

            return $m[0] ?? '';
        };
        $dba = $sql('10-registry-admission-pin.dba.sql');
        $original = $function($sql('01-schema.sql'));
        $pinned = $function($dba);
        $this->assertNotSame('', $original);

        // Remove exactly what DBA-1 adds: the result is the original, character for character.
        $block = preg_replace('/    -- DBA-1 \(FB-FAMILY-ADD-01.*?    PERFORM pg_catalog\.set_config\(\'lock_timeout\', previous_timeout, true\);\n/s', '', $pinned, 1, $count);
        $this->assertSame(1, $count);
        $this->assertSame($original, str_replace("    previous_timeout text;\n", '', (string) $block));

        // DBA-2 and DBA-3 are there, with EXECUTE for the reader only.
        $this->assertStringContainsString('CREATE TRIGGER active_release_pinned_forward BEFORE INSERT OR UPDATE ON identity_ref.active_release', $dba);
        $this->assertStringContainsString('REVOKE ALL ON FUNCTION identity_ref_lookup.release_pin_held(integer, integer) FROM PUBLIC;', $dba);
        $this->assertStringContainsString('GRANT EXECUTE ON FUNCTION identity_ref_lookup.release_pin_held(integer, integer) TO famboook_identity_reader;', $dba);
        $this->assertSame(1, substr_count($dba, 'GRANT '));
        // The DBA-3 body is the ADR contract once its operators and casts are qualified with pg_catalog.
        $normalize = static fn (string $s) => preg_replace('/\s+/', ' ', preg_replace('/--[^\n]*/', '', str_replace('::pg_catalog.', '::', preg_replace('/OPERATOR\(pg_catalog\.([^)]+)\)/', '$1', $s))));
        preg_match('/(CREATE OR REPLACE FUNCTION identity_ref_lookup\.release_pin_held.*?\$\$;)/s', $dba, $ours);
        preg_match('/(CREATE OR REPLACE FUNCTION identity_ref_lookup\.release_pin_held.*?\$\$;)/s', RegistryReleasePinContractTest::documentedDba3(), $adr);
        $this->assertSame(trim($normalize($adr[1] ?? 'missing')), trim($normalize($ours[1] ?? 'missing')));
    }

    public function test_the_rollback_restores_publish_release_verbatim_and_the_checks_change_nothing(): void
    {
        $sql = static fn (string $f) => str_replace("\r\n", "\n", (string) file_get_contents(base_path('../deploy/sql/identity-ref/'.$f)));
        $function = static function (string $text): string {
            preg_match('/FUNCTION identity_ref\.publish_release\(p_release bigint\).*?\n\$\$;\n/s', $text, $m);

            return $m[0] ?? '';
        };
        $original = $function($sql('01-schema.sql'));
        $this->assertNotSame('', $original);
        $this->assertSame($original, $function($sql('13-registry-admission-pin-rollback.dba.sql')));

        // The precheck and the verification are read-only: no DDL, DML, grant or role change outside comments.
        foreach (['11-registry-admission-pin-precheck.sql', '12-registry-admission-pin-verify.sql'] as $file) {
            $code = preg_replace('/--[^\n]*/', '', $sql($file));
            $this->assertDoesNotMatchRegularExpression('/\b(CREATE|ALTER|DROP|GRANT|REVOKE|INSERT|UPDATE|DELETE|TRUNCATE|SET ROLE|set_config)\b/i', (string) $code, $file);
        }
    }
}

/** A fake CivilRegistrySource for the adapter: synthetic children of one parent, read in pages. */
final class FakeCivilRegistry implements CivilRegistrySource
{
    /** @var list<array{int, int}> */
    public array $calls = [];

    /** @var array<string, CivilRecord> */
    public array $records = [];

    public ?int $releaseAfterFirst = null;

    public ?int $totalAfterFirst = null;

    public function __construct(private readonly ?int $release, private readonly int $children) {}

    public function children(string $mainid, int $offset, int $limit): CivilRecordPage
    {
        $first = $this->calls === [];
        $this->calls[] = [$offset, $limit];
        $release = ! $first && $this->releaseAfterFirst !== null ? $this->releaseAfterFirst : $this->release;
        $total = ! $first && $this->totalAfterFirst !== null ? $this->totalAfterFirst : min($this->children, self::LINKS_CAP + 1);
        if ($this->release === null) {
            return new CivilRecordPage(null, 0, [], self::LINKS_CAP);
        }
        $rows = [];
        if ($total <= self::LINKS_CAP) {
            for ($i = $offset; $i < min($offset + $limit, $total); $i++) {
                $id = (string) (500000000 + $i);
                $rows[] = new CivilRecord($release, $i + 1, $id, ['mainid' => $id, 'fatherid' => $mainid]);
            }
        }

        return new CivilRecordPage($release, $total, $rows, self::LINKS_CAP);
    }

    public function byMainid(string $mainid): ?CivilRecord
    {
        return $this->records[$mainid] ?? null;
    }

    public function activeReleaseId(): ?int
    {
        return $this->release;
    }

    public function byRecord(int $recordId): ?CivilRecord
    {
        throw new RuntimeException('not used');
    }

    public function existingMainids(array $mainids): array
    {
        throw new RuntimeException('not used');
    }

    public function searchFullname(array $tokens, int $offset, int $limit): CivilRecordPage
    {
        throw new RuntimeException('not used');
    }

    public function searchParts(array $parts, int $offset, int $limit): CivilRecordPage
    {
        throw new RuntimeException('not used');
    }

    public function householdMembers(string $householdId, string $excludeMainid, int $offset, int $limit): CivilRecordPage
    {
        throw new RuntimeException('not used');
    }
}
