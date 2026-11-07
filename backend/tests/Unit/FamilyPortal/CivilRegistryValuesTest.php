<?php

namespace Tests\Unit\FamilyPortal;

use App\Enums\CivilValueStatus as S;
use App\Enums\Gender;
use App\Enums\LifeEvidence;
use App\Enums\MaritalStatus;
use App\Support\CivilLookup\CivilRecord;
use App\Support\FamilyPortal\RegistryAdmission\CivilRegistryValues as V;
use App\Support\FamilyPortal\RegistryAdmission\ProofOfLife;
use PHPUnit\Framework\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-05: the strict value adapter and the fail-closed proof of
 * life. No database. Synthetic values only.
 */
class CivilRegistryValuesTest extends TestCase
{
    /** @param array<string, ?string> $payload */
    private function record(array $payload = [], string $mainid = '901234567'): CivilRecord
    {
        return new CivilRecord(1, 1, $mainid, $payload);
    }

    public function test_only_the_listed_gender_values_are_mapped_and_mappings_are_not_approved(): void
    {
        $this->assertFalse(V::MAPPINGS_APPROVED);
        $this->assertSame([S::MAPPED, Gender::MALE], [V::gender('ذكر')->status, V::gender('ذكر')->value]);
        $this->assertSame([S::MAPPED, Gender::FEMALE], [V::gender('أنثى')->status, V::gender('أنثى')->value]);
        $this->assertSame(S::INDETERMINATE, V::gender('غير معروف')->status);
        $this->assertNull(V::gender('غير معروف')->value);
        $this->assertSame(S::MISSING, V::gender(null)->status);
        $this->assertSame(S::MISSING, V::gender('')->status);
    }

    public function test_the_gender_table_is_exactly_the_three_observed_codes_pinned_by_their_bytes(): void
    {
        // G-04 (still open): the table under review is exactly these three stored values, as UTF-8
        // bytes, so a normalized, re-encoded or added code fails here rather than mapping silently.
        $this->assertSame([
            "\u{0630}\u{0643}\u{0631}" => Gender::MALE,                                         // ذكر
            "\u{0623}\u{0646}\u{062B}\u{0649}" => Gender::FEMALE,                               // أنثى
            "\u{063A}\u{064A}\u{0631} \u{0645}\u{0639}\u{0631}\u{0648}\u{0641}" => null,         // غير معروف
        ], V::GENDER);
        // MALE and FEMALE only; unknown, null and empty never carry a value.
        foreach (["\u{063A}\u{064A}\u{0631} \u{0645}\u{0639}\u{0631}\u{0648}\u{0641}", null, ''] as $raw) {
            $this->assertNull(V::gender($raw)->value, var_export($raw, true));
        }
        // Decomposed or presentation forms of the same letters are other values, never these.
        foreach (["\u{0627}\u{0654}\u{0646}\u{062B}\u{0649}", "\u{FEAB}\u{FEDB}\u{FEAE}"] as $raw) {
            $this->assertSame(S::UNSUPPORTED, V::gender($raw)->status);
        }
    }

    public function test_similar_or_coded_gender_values_are_never_guessed(): void
    {
        foreach (['1', '2', 'M', 'F', 'male', 'ذكر ', ' ذكر', 'انثى', 'أنثي', 'ذكور', 'Male'] as $raw) {
            $this->assertSame(S::UNSUPPORTED, V::gender($raw)->status, $raw);
            $this->assertNull(V::gender($raw)->value, $raw);
        }
    }

    public function test_only_the_listed_marital_values_are_mapped(): void
    {
        $expected = ['اعزب' => MaritalStatus::SINGLE, 'متزوج' => MaritalStatus::MARRIED, 'أرمل' => MaritalStatus::WIDOWED, 'مطلق' => MaritalStatus::DIVORCED];
        foreach ($expected as $raw => $status) {
            $this->assertSame([S::MAPPED, $status], [V::maritalStatus($raw)->status, V::maritalStatus($raw)->value], $raw);
        }
        foreach (['متعدد الزوجات', 'غير مسجل'] as $raw) {
            $this->assertSame(S::INDETERMINATE, V::maritalStatus($raw)->status, $raw);
        }
        foreach (['أعزب', 'متزوجة', 'ارمل', 'مطلقة', 'SINGLE', '1', 'متزوج '] as $raw) {
            $this->assertSame(S::UNSUPPORTED, V::maritalStatus($raw)->status, $raw);
        }
        $this->assertSame(S::MISSING, V::maritalStatus('')->status);
    }

    public function test_national_id_validity_is_exact(): void
    {
        $this->assertTrue(V::validNationalId('901234567'));
        $this->assertTrue(V::validNationalId('012345678'));
        foreach ([null, '', '12', '9012345678', '90123456a', ' 901234567', '901 234 567', '٩٠١٢٣٤٥٦٧', '000000000', '999999999'] as $raw) {
            $this->assertFalse(V::validNationalId($raw), var_export($raw, true));
        }
    }

    public function test_the_parent_link_uses_the_parent_fields_only(): void
    {
        $head = '911111111';
        $this->assertSame('FATHER', V::parentLink($this->record(['fatherid' => $head, 'motherid' => '922222222']), $head));
        $this->assertSame('MOTHER', V::parentLink($this->record(['fatherid' => '', 'motherid' => $head]), $head));
        $this->assertSame('BOTH', V::parentLink($this->record(['fatherid' => $head, 'motherid' => $head]), $head));
        $this->assertNull(V::parentLink($this->record(['fatherid' => '', 'motherid' => '']), $head));
        $this->assertNull(V::parentLink($this->record(['fatherid' => ' '.$head, 'motherid' => null]), $head));
        // Same family name, same father name: still not a child.
        $this->assertNull(V::parentLink($this->record(['family' => 'عائلة-تجريبية', 'father' => 'اسم-الأب', 'fatherid' => '933333333']), $head));
    }

    public function test_dates_are_read_in_the_observed_shape_only(): void
    {
        $this->assertSame('2010-01-15', V::date('2010-01-15T00:00:00'));
        foreach (['', null, '2010-01-15', '15/01/2010', '2010-02-30T00:00:00', '2010-13-01T00:00:00', 'غير معروف'] as $raw) {
            $this->assertNull(V::date($raw), var_export($raw, true));
        }
    }

    public function test_an_empty_deathdate_or_presonstate_is_never_proof_of_life(): void
    {
        foreach ([[], ['deathdate' => ''], ['deathdate' => null], ['presonstate' => ''], ['deathdate' => '', 'presonstate' => '']] as $payload) {
            $this->assertSame(LifeEvidence::UNVERIFIED, ProofOfLife::evaluate($this->record($payload)), json_encode($payload));
        }
    }

    public function test_presonstate_age_and_marital_status_never_prove_life(): void
    {
        foreach (['حي', 'ALIVE', '1', 'على قيد الحياة', 'متوفى'] as $state) {
            $this->assertSame(LifeEvidence::UNVERIFIED, ProofOfLife::evaluate($this->record(['presonstate' => $state])), $state);
        }
        $this->assertSame(LifeEvidence::UNVERIFIED, ProofOfLife::evaluate($this->record([
            'age' => '25', 'datebirth' => '2001-01-01T00:00:00', 'maritalst' => 'متزوج',
        ])));
    }

    public function test_death_evidence_and_conflicts_are_never_alive(): void
    {
        $this->assertSame(LifeEvidence::DECEASED, ProofOfLife::evaluate($this->record(['deathdate' => '2020-05-01T00:00:00'])));
        $this->assertSame(LifeEvidence::DECEASED, ProofOfLife::evaluate($this->record(['deathdate' => '2020-05-01T00:00:00', 'presonstate' => 'حي'])));
        $this->assertSame(LifeEvidence::INDETERMINATE, ProofOfLife::evaluate($this->record(['deathdate' => '01/05/2020'])));
        $this->assertSame(LifeEvidence::INDETERMINATE, ProofOfLife::evaluate($this->record(['deathdate' => 'غير معروف'])));
    }

    public function test_there_is_no_alive_outcome_at_all(): void
    {
        $this->assertSame(['DECEASED', 'INDETERMINATE', 'UNVERIFIED'], array_column(LifeEvidence::cases(), 'value'));
    }
}
