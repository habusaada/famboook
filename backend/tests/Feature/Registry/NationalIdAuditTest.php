<?php

namespace Tests\Feature\Registry;

use App\Console\Commands\NationalIdAudit;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CO-01D.2: the read-only National ID integrity audit. Synthetic National
 * IDs, names and mobiles only — invented values that prove nothing leaks.
 */
class NationalIdAuditTest extends TestCase
{
    use RefreshDatabase;

    /** Runs the audit; returns [exit code, output]. */
    private function audit(array $options = []): array
    {
        $code = Artisan::call('famboook:national-id-audit', $options);

        return [$code, Artisan::output()];
    }

    private function person(?string $nationalId, array $attributes = []): Person
    {
        return Person::factory()->create(['national_id' => $nationalId, ...$attributes]);
    }

    private function inFamily(Person $person): Family
    {
        $family = Family::factory()->create();
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => $person->id, 'is_active' => true]);

        return $family;
    }

    /** The value printed after a report line's label. */
    private function value(string $output, string $label): string
    {
        $this->assertSame(1, preg_match('/^[ \t]*'.preg_quote($label, '/').'\s*(.+)$/m', $output, $m), "Missing line: {$label}");

        return trim($m[1]);
    }

    // ---------------------------------------------------------- classification

    /** @return array<string, array{0: ?string, 1: string, 2: ?string}> */
    public static function storedValues(): array
    {
        return [
            'canonical' => ['401234567', NationalIdAudit::CANONICAL, '401234567'],
            'surrounding spaces' => [' 401234567 ', NationalIdAudit::NON_CANONICAL, '401234567'],
            'inner spaces and dashes' => ['401-234 567', NationalIdAudit::NON_CANONICAL, '401234567'],
            'Arabic-Indic digits' => ['٤٠١٢٣٤٥٦٧', NationalIdAudit::NON_CANONICAL, '401234567'],
            'Persian digits' => ['۴۰۱۲۳۴۵۶۷', NationalIdAudit::NON_CANONICAL, '401234567'],
            'dots and slashes' => ['401.234/567', NationalIdAudit::NON_CANONICAL, '401234567'],
            'null' => [null, NationalIdAudit::MISSING, null],
            'empty' => ['', NationalIdAudit::MISSING, null],
            'blank' => ['   ', NationalIdAudit::MISSING, null],
            'eight digits' => ['40123456', NationalIdAudit::INVALID, null],
            'ten digits' => ['4012345678', NationalIdAudit::INVALID, null],
            'letters' => ['A40123456', NationalIdAudit::INVALID, null],
            'passport-like' => ['P1234567', NationalIdAudit::INVALID, null],
            'placeholder zeros' => ['000000000', NationalIdAudit::PLACEHOLDER, null],
            'placeholder nines' => ['999999999', NationalIdAudit::PLACEHOLDER, null],
        ];
    }

    #[DataProvider('storedValues')]
    public function test_each_stored_value_is_classified_never_guessed(?string $stored, string $class, ?string $canonical): void
    {
        $this->assertSame([$class, $canonical], NationalIdAudit::classify($stored));
    }

    // ------------------------------------------------------------------ report

    public function test_unique_valid_ids_are_safe_for_a_unique_constraint(): void
    {
        $this->person('401234567');
        $this->person('402345678');
        $this->person('403456789');

        [$code, $out] = $this->audit();

        $this->assertSame(0, $code);
        $this->assertSame('3', $this->value($out, 'A. Total Persons:'));
        $this->assertSame('3', $this->value($out, 'D. Valid National IDs (stored canonically):'));
        $this->assertSame('0', $this->value($out, 'H. Duplicate National ID groups:'));
        $this->assertSame('YES', $this->value($out, 'L. Global UNIQUE constraint on the canonical value can be applied safely:'));
        $this->assertStringNotContainsString('Duplicate groups (anonymous references)', $out);
    }

    public function test_every_category_is_counted(): void
    {
        $this->person('401234567');
        $this->person('402 345 678');
        $this->person(null);
        $this->person('  ');
        $this->person('A1234');
        $this->person('000000000');
        $this->person('403456789')->delete();

        [, $out] = $this->audit();

        $this->assertSame('7', $this->value($out, 'A. Total Persons:'));
        $this->assertSame('6', $this->value($out, 'B. Active Persons:'));
        $this->assertSame('1', $this->value($out, 'C. Soft-deleted Persons:'));
        $this->assertSame('2', $this->value($out, 'D. Valid National IDs (stored canonically):'));
        $this->assertSame('2', $this->value($out, 'E. Missing National IDs (NULL or blank):'));
        $this->assertSame('2', $this->value($out, 'F. Invalid National IDs:'));
        $this->assertSame('1', $this->value($out, 'not normalizable to nine digits:'));
        $this->assertSame('1', $this->value($out, 'placeholder (nine identical digits):'));
        $this->assertSame('1', $this->value($out, 'G. Non-canonical (valid after normalization):'));
        $this->assertSame('0', $this->value($out, 'H. Duplicate National ID groups:'));
    }

    public function test_exact_duplicates_form_one_group(): void
    {
        $this->person('401234567');
        $this->person('401234567');
        $this->person('402345678');

        [$code, $out] = $this->audit();

        $this->assertSame(1, $code);
        $this->assertSame('1', $this->value($out, 'H. Duplicate National ID groups:'));
        $this->assertSame('0', $this->value($out, 'of which differ only in formatting:'));
        $this->assertSame('2', $this->value($out, 'I. Person records involved in duplicates:'));
        $this->assertSame('NO', $this->value($out, 'L. Global UNIQUE constraint on the canonical value can be applied safely:'));
        $this->assertMatchesRegularExpression('/G-001\s*\|\s*2\s*\|/', $out);
    }

    public function test_identical_non_canonical_values_are_an_exact_duplicate(): void
    {
        $this->person('401 234 567');
        $this->person('401 234 567');

        [, $out] = $this->audit();

        $this->assertSame('1', $this->value($out, 'H. Duplicate National ID groups:'));
        $this->assertSame('0', $this->value($out, 'of which differ only in formatting:'));
        $this->assertSame('2', $this->value($out, 'G. Non-canonical (valid after normalization):'));
    }

    public function test_equivalent_ids_with_different_formatting_are_duplicates(): void
    {
        $this->person('401234567');
        $this->person('٤٠١٢٣٤٥٦٧');
        $this->person('401-234-567');

        [, $out] = $this->audit();

        $this->assertSame('1', $this->value($out, 'H. Duplicate National ID groups:'));
        $this->assertSame('1', $this->value($out, 'of which differ only in formatting:'));
        $this->assertSame('3', $this->value($out, 'I. Person records involved in duplicates:'));
        $this->assertSame('2', $this->value($out, 'G. Non-canonical (valid after normalization):'));
    }

    public function test_missing_invalid_and_placeholder_values_never_form_duplicate_groups(): void
    {
        $this->person(null);
        $this->person(null);
        $this->person('');
        $this->person('A1234');
        $this->person('A1234');
        $this->person('000000000');
        $this->person('000000000');

        [$code, $out] = $this->audit();

        $this->assertSame(0, $code);
        $this->assertSame('0', $this->value($out, 'H. Duplicate National ID groups:'));
        $this->assertSame('YES', $this->value($out, 'L. Global UNIQUE constraint on the canonical value can be applied safely:'));
        $this->assertStringContainsString('they block NOT NULL', $out);
    }

    public function test_soft_deleted_persons_keep_their_id_and_count_in_duplicates(): void
    {
        $this->person('401234567');
        $this->person('401234567')->delete();
        // Both soft-deleted: still a duplicate (a deleted Person keeps its ID).
        $this->person('402345678')->delete();
        $this->person('402 345 678')->delete();

        [, $out] = $this->audit();

        $this->assertSame('2', $this->value($out, 'H. Duplicate National ID groups:'));
        $this->assertSame('2', $this->value($out, 'J. Duplicate groups with soft-deleted Persons:'));
        $this->assertSame('4', $this->value($out, 'I. Person records involved in duplicates:'));
        $this->assertSame('3', $this->value($out, 'C. Soft-deleted Persons:'));
    }

    public function test_duplicates_across_families_are_identified(): void
    {
        $this->inFamily($this->person('401234567'));
        $this->inFamily($this->person('401234567'));
        // Same Family twice: one family only.
        $a = $this->person('402345678');
        $family = $this->inFamily($a);
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => $this->person('402345678')->id, 'is_active' => true]);
        // One member without an active family.
        $this->inFamily($this->person('403456789'));
        $this->person('403456789');

        [, $out] = $this->audit();

        $this->assertSame('3', $this->value($out, 'H. Duplicate National ID groups:'));
        $this->assertSame('1', $this->value($out, 'K. Duplicate groups spanning several Families:'));
        $this->assertMatchesRegularExpression('/G-001\s*\|\s*2\s*\|\s*0\s*\|\s*2\s*\|\s*0\s*\|/', $out);
        $this->assertMatchesRegularExpression('/G-002\s*\|\s*2\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|/', $out);
        $this->assertMatchesRegularExpression('/G-003\s*\|\s*2\s*\|\s*0\s*\|\s*1\s*\|\s*1\s*\|/', $out);
    }

    // --------------------------------------------------------------- disclosure

    public function test_no_national_id_name_or_mobile_is_ever_printed(): void
    {
        $values = ['401234567', '٤٠١٢٣٤٥٦٧', '402-345-678', '402345678', 'A9876543', '000000000'];
        foreach ($values as $i => $value) {
            $person = $this->person($value, ['full_name' => "اسم سري {$i}", 'mobile' => '059876543'.$i]);
            $this->inFamily($person);
        }

        foreach ([[], ['--references' => true]] as $options) {
            [, $out] = $this->audit($options);
            foreach ([...$values, '401234567', '402345678', 'اسم سري', '059876543', '*****', '4567', '5678'] as $secret) {
                $this->assertStringNotContainsString($secret, $out, 'Leaked: '.$secret);
            }
            // Not even a hash of a canonical value.
            foreach (['401234567', '402345678'] as $canonical) {
                $this->assertStringNotContainsString(hash('sha256', $canonical), $out);
                $this->assertStringNotContainsString(md5($canonical), $out);
            }
        }
    }

    public function test_person_ids_and_family_codes_appear_only_with_the_restricted_option(): void
    {
        $first = $this->person('401234567');
        $second = $this->person('401234567');
        $family = $this->inFamily($first);

        [, $plain] = $this->audit();
        $this->assertStringNotContainsString($family->family_code, $plain);
        $this->assertStringContainsString('use --references', $plain);

        [, $restricted] = $this->audit(['--references' => true]);
        $this->assertStringContainsString('RESTRICTED', $restricted);
        $this->assertStringContainsString($family->family_code, $restricted);
        $this->assertMatchesRegularExpression('/G-001\s*\|\s*'.$first->id.'\s*\|\s*'.preg_quote($family->family_code, '/').'/', $restricted);
        $this->assertMatchesRegularExpression('/G-001\s*\|\s*'.$second->id.'\s*\|\s*—/', $restricted);
    }

    public function test_the_restricted_option_is_refused_outside_local_and_testing(): void
    {
        $this->person('401234567');
        $this->person('401234567');
        $this->app['env'] = 'production';

        [$code, $out] = $this->audit(['--references' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('restricted to a local or testing environment', $out);
        $this->assertStringNotContainsString('G-001', $out);
    }

    // --------------------------------------------------------------- read-only

    public function test_the_audit_writes_nothing(): void
    {
        $this->inFamily($this->person('401234567'));
        $this->person('401 234 567')->delete();
        $this->person(null);
        $before = DB::table('persons')->orderBy('id')->get()->toJson();
        $updatedAt = DB::table('persons')->max('updated_at');

        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->audit();
        $this->audit(['--references' => true]);

        $this->assertSame([], $writes);
        $this->assertSame($before, DB::table('persons')->orderBy('id')->get()->toJson());
        $this->assertSame($updatedAt, DB::table('persons')->max('updated_at'));
        // Stored values are untouched — including the non-canonical one.
        $this->assertSame(1, DB::table('persons')->where('national_id', '401 234 567')->count());
    }

    public function test_it_writes_no_report_file(): void
    {
        $this->person('401234567');
        $this->person('401234567');
        $storage = storage_path();
        $before = $this->files($storage);

        $this->audit(['--references' => true]);

        $this->assertSame($before, $this->files($storage));
    }

    /** @return list<string> */
    private function files(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    // ------------------------------------------------------------------ scale

    public function test_a_large_registry_is_audited_in_chunks(): void
    {
        $now = now();
        $rows = [];
        // 5,000 Persons across several chunks: 4,990 unique, 5 duplicate pairs.
        for ($i = 1; $i <= 4990; $i++) {
            $rows[] = ['person_code' => sprintf('PER-9%05d', $i), 'full_name' => 'شخص', 'national_id' => sprintf('5%08d', $i),
                'life_status' => 'ALIVE', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ['person_code' => sprintf('PER-8%05d', $i), 'full_name' => 'شخص', 'national_id' => sprintf('5%08d', (int) ceil($i / 2)),
                'life_status' => 'ALIVE', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('persons')->insert($chunk);
        }

        [$code, $out] = $this->audit();

        $this->assertGreaterThan(NationalIdAudit::CHUNK * 2, 5000);
        $this->assertSame(1, $code);
        $this->assertSame('5000', $this->value($out, 'A. Total Persons:'));
        $this->assertSame('5', $this->value($out, 'H. Duplicate National ID groups:'));
        $this->assertSame('15', $this->value($out, 'I. Person records involved in duplicates:'));
    }
}
