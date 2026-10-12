<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Support\FamilyAuth\FamilyNationalId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * National ID integrity audit (CO-01D.2) — STRICTLY READ-ONLY.
 *
 * Prepares database-level global uniqueness of persons.national_id by
 * classifying every Person — active AND soft-deleted (a soft-deleted Person
 * keeps its National ID) — and grouping duplicates after normalization:
 *
 *   MISSING        NULL, or blank after trimming
 *   CANONICAL      stored exactly as nine ASCII digits
 *   NON_CANONICAL  not stored canonically, but the strict normalizer
 *                  (FamilyNationalId: Arabic-Indic / Persian digits to ASCII,
 *                  spaces, invisible marks, dashes, dots, slashes and
 *                  underscores dropped) yields exactly nine digits
 *   PLACEHOLDER    nine identical digits (e.g. 000000000): never a real ID
 *   INVALID        anything the normalizer cannot turn into nine digits
 *                  (letters, wrong length, malformed text) — classified,
 *                  never guessed or corrected
 *
 * Duplicate groups are formed from the canonical value of CANONICAL and
 * NON_CANONICAL Persons only. Each group is reported by an anonymous
 * reference (G-001, …) with counts; the Person ids and Family codes appear
 * only with --references, which is refused outside a local or testing
 * environment.
 *
 * Never printed or written anywhere: a National ID value (raw, canonical,
 * masked or hashed), a name or a mobile number. Nothing is persisted: the
 * report is console output only. The whole run happens in a transaction
 * that is always rolled back — on PostgreSQL a READ ONLY transaction, so any
 * write attempt would fail.
 */
class NationalIdAudit extends Command
{
    protected $signature = 'famboook:national-id-audit
        {--references : Also list Person ids and Family codes per duplicate group (local / testing only)}';

    protected $description = 'Read-only National ID integrity audit (counts and anonymous duplicate groups; never an ID value).';

    /** Rows read per chunk: memory stays bounded on a large registry. */
    public const CHUNK = 2000;

    public const MISSING = 'MISSING';

    public const CANONICAL = 'CANONICAL';

    public const NON_CANONICAL = 'NON_CANONICAL';

    public const PLACEHOLDER = 'PLACEHOLDER';

    public const INVALID = 'INVALID';

    public function handle(): int
    {
        if ($this->option('references') && ! app()->environment(['local', 'testing'])) {
            $this->error('--references is restricted to a local or testing environment.');

            return self::FAILURE;
        }

        // READ ONLY must be the first statement of a top-level transaction;
        // nested (e.g. inside a test's transaction) the rollback still
        // discards anything, and the audit issues no write either way.
        $outermost = DB::transactionLevel() === 0;
        DB::beginTransaction();
        try {
            if ($outermost && DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }
            $report = $this->audit();
        } finally {
            DB::rollBack();
        }

        $this->print($report);

        return $report['unique_safe'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Classifies every Person and builds the duplicate groups. The canonical
     * values live only in this method's memory, keyed for grouping; nothing
     * derived from them leaves it except counts and Person ids.
     *
     * @return array<string, mixed>
     */
    private function audit(): array
    {
        $counts = [
            'total' => 0, 'active' => 0, 'deleted' => 0,
            self::CANONICAL => 0, self::NON_CANONICAL => 0, self::MISSING => 0, self::PLACEHOLDER => 0, self::INVALID => 0,
        ];
        /** @var array<string, list<array{id: int, deleted: bool, stored: string}>> $byValue */
        $byValue = [];

        Person::withTrashed()
            ->select(['id', 'national_id', 'deleted_at'])
            ->lazyById(self::CHUNK)
            ->each(function (Person $person) use (&$counts, &$byValue) {
                $deleted = $person->deleted_at !== null;
                $counts['total']++;
                $counts[$deleted ? 'deleted' : 'active']++;

                $stored = $person->getRawOriginal('national_id');
                [$class, $canonical] = self::classify($stored);
                $counts[$class]++;
                if ($canonical !== null) {
                    // A digest of the stored text, only to tell exact duplicates
                    // from formatting variants inside this run; never printed.
                    $byValue[$canonical][] = ['id' => (int) $person->id, 'deleted' => $deleted, 'stored' => hash('sha256', (string) $stored)];
                }
            });

        $groups = [];
        foreach ($byValue as $members) {
            if (count($members) > 1) {
                $groups[] = $members;
            }
        }
        unset($byValue);
        // A stable, value-independent order: by the smallest Person id.
        usort($groups, fn (array $a, array $b) => min(array_column($a, 'id')) <=> min(array_column($b, 'id')));

        $families = $this->activeFamilies(array_merge(...array_map(fn (array $g) => array_column($g, 'id'), $groups ?: [[]])));

        $report = ['counts' => $counts, 'groups' => []];
        foreach ($groups as $i => $members) {
            $codes = [];
            foreach ($members as $member) {
                $codes[$member['id']] = $families[$member['id']] ?? null;
            }
            $distinctFamilies = array_values(array_unique(array_filter($codes)));
            $report['groups'][] = [
                'ref' => sprintf('G-%03d', $i + 1),
                'size' => count($members),
                'deleted' => count(array_filter($members, fn (array $m) => $m['deleted'])),
                // Every member stored the same text: an exact duplicate;
                // otherwise the stored texts differ only by formatting.
                'format_variant' => count(array_unique(array_column($members, 'stored'))) > 1,
                'families' => count($distinctFamilies),
                'without_family' => count(array_filter($codes, fn (?string $c) => $c === null)),
                'members' => $codes,
            ];
        }

        $report['duplicate_persons'] = array_sum(array_column($report['groups'], 'size'));
        $report['unique_safe'] = $report['groups'] === [];

        return $report;
    }

    /**
     * The class of a stored value and, when it identifies someone, its
     * canonical nine digits. Pure: reused by the tests.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function classify(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [self::MISSING, null];
        }
        $canonical = FamilyNationalId::normalize($stored);
        if ($canonical === null) {
            return [self::INVALID, null];
        }
        if (preg_match('/\A([0-9])\1{8}\z/', $canonical) === 1) {
            return [self::PLACEHOLDER, null];
        }

        return [$stored === $canonical ? self::CANONICAL : self::NON_CANONICAL, $canonical];
    }

    /**
     * Person id => the Family code of their ACTIVE membership (at most one
     * by the registry rules), for the given Persons only. Chunked.
     *
     * @param  list<int>  $personIds
     * @return array<int, string>
     */
    private function activeFamilies(array $personIds): array
    {
        $codes = [];
        foreach (array_chunk($personIds, 1000) as $chunk) {
            DB::table('family_memberships')
                ->join('families', 'families.id', '=', 'family_memberships.family_id')
                ->whereIn('family_memberships.person_id', $chunk)
                ->where('family_memberships.is_active', true)
                ->orderBy('family_memberships.id')
                ->get(['family_memberships.person_id', 'families.family_code'])
                ->each(function (object $row) use (&$codes) {
                    $codes[(int) $row->person_id] ??= (string) $row->family_code;
                });
        }

        return $codes;
    }

    /** @param array<string, mixed> $report */
    private function print(array $report): void
    {
        $c = $report['counts'];
        $groups = $report['groups'];
        $withDeleted = count(array_filter($groups, fn (array $g) => $g['deleted'] > 0));
        $acrossFamilies = count(array_filter($groups, fn (array $g) => $g['families'] > 1));
        $formatVariants = count(array_filter($groups, fn (array $g) => $g['format_variant']));

        $this->line('National ID integrity audit (read-only; no National ID value is shown)');
        $this->line('');
        $this->line('A. Total Persons:                              '.$c['total']);
        $this->line('B. Active Persons:                             '.$c['active']);
        $this->line('C. Soft-deleted Persons:                       '.$c['deleted']);
        $this->line('D. Valid National IDs (stored canonically):    '.$c[self::CANONICAL]);
        $this->line('E. Missing National IDs (NULL or blank):       '.$c[self::MISSING]);
        $this->line('F. Invalid National IDs:                       '.($c[self::INVALID] + $c[self::PLACEHOLDER]));
        $this->line('     not normalizable to nine digits:          '.$c[self::INVALID]);
        $this->line('     placeholder (nine identical digits):      '.$c[self::PLACEHOLDER]);
        $this->line('G. Non-canonical (valid after normalization):  '.$c[self::NON_CANONICAL]);
        $this->line('H. Duplicate National ID groups:               '.count($groups));
        $this->line('     of which differ only in formatting:       '.$formatVariants);
        $this->line('I. Person records involved in duplicates:      '.$report['duplicate_persons']);
        $this->line('J. Duplicate groups with soft-deleted Persons: '.$withDeleted);
        $this->line('K. Duplicate groups spanning several Families: '.$acrossFamilies);
        $this->line('L. Global UNIQUE constraint on the canonical value can be applied safely: '.($report['unique_safe'] ? 'YES' : 'NO'));
        if (! $report['unique_safe']) {
            $this->line('     blocked by '.count($groups).' duplicate group(s); resolve them first (no automatic resolution).');
        }
        if ($c[self::MISSING] + $c[self::INVALID] + $c[self::PLACEHOLDER] + $c[self::NON_CANONICAL] > 0) {
            $this->line('     note: missing, invalid, placeholder and non-canonical values do not block a partial UNIQUE index');
            $this->line('     on the canonical value, but they block NOT NULL and a "stored exactly as nine digits" CHECK.');
        }

        if ($groups === []) {
            return;
        }
        $this->line('');
        $this->line('Duplicate groups (anonymous references):');
        $this->table(
            ['Group', 'Persons', 'Soft-deleted', 'Families', 'Without active family', 'Format variant'],
            array_map(fn (array $g) => [$g['ref'], $g['size'], $g['deleted'], $g['families'], $g['without_family'], $g['format_variant'] ? 'yes' : 'no'], $groups),
        );

        if (! $this->option('references')) {
            $this->line('Person ids and Family codes are omitted; use --references (local / testing only) to list them.');

            return;
        }
        $this->line('');
        $this->warn('RESTRICTED: Person ids and Family codes. Do not copy this output into tickets, chats or files.');
        $rows = [];
        foreach ($groups as $g) {
            foreach ($g['members'] as $personId => $familyCode) {
                $rows[] = [$g['ref'], $personId, $familyCode ?? '—'];
            }
        }
        $this->table(['Group', 'Person id', 'Active family code'], $rows);
    }
}
