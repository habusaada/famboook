<?php

namespace Tests\Feature\CivilRegistry;

use App\Models\CivilImportAuditEvent;
use App\Models\CivilImportSource;
use App\Support\CivilImport\CivilImportSourceCatalogue;
use App\Support\CivilImport\CivilImportSourceException;
use App\Support\IdentityRef\IdentityManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U2 — server-side source discovery (D-UI-3, ADR §6): only
 * safe, direct, non-symlinked sub-directories of the configured root with a
 * manifest are catalogued; the catalogue holds a label and codes, never a
 * path or a file name; a source is re-checked (still safe, manifest
 * unchanged) when used. SYNTHETIC data only.
 */
class CivilImportSourceCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    /** @var list<SyntheticIdentityExport> */
    private array $exports = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'famboook-u2-cat-'.bin2hex(random_bytes(5));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
        foreach ($this->exports as $export) {
            $export->delete();
        }
        parent::tearDown();
    }

    private function place(string $label, ?string $root = null): string
    {
        $export = $this->exports[] = SyntheticIdentityExport::generated(2, 3);
        $dir = ($root ?? $this->root).DIRECTORY_SEPARATOR.$label;
        mkdir($dir, 0700);
        foreach (scandir($export->dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                copy($export->path($entry), $dir.DIRECTORY_SEPARATOR.$entry);
            }
        }

        return $dir;
    }

    private function symlinkOrSkip(string $target, string $link): void
    {
        if (! @symlink($target, $link)) {
            $this->markTestSkipped('This machine does not allow creating symlinks (Windows without Developer Mode).');
        }
    }

    public function test_only_safe_direct_sub_directories_with_a_manifest_are_catalogued(): void
    {
        $this->place('export-2026-11-01');
        $this->place('bad label');                        // unsafe name
        $this->place('.hidden');                          // unsafe name
        mkdir($this->root.DIRECTORY_SEPARATOR.'no-manifest');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'plain-file', 'x');
        mkdir($this->root.DIRECTORY_SEPARATOR.'nested');
        $this->place('deeper', $this->root.DIRECTORY_SEPARATOR.'nested');   // not a direct child

        $result = (new CivilImportSourceCatalogue($this->root))->scan();
        $this->assertSame(['catalogued' => 1, 'unavailable' => 0, 'sources' => 1], $result);
        $source = CivilImportSource::query()->sole();
        $this->assertSame(['export-2026-11-01', 'VALID', 't03maindata', 2, 6], [$source->label, $source->manifest_status, $source->source_table,
            $source->file_count, $source->total_rows]);
        $stored = (string) json_encode(CivilImportSource::query()->get()->makeVisible('manifest_sha256')->toArray())
            .CivilImportAuditEvent::query()->get()->toJson();
        foreach ([$this->root, 'famboook-u2-cat', 't03maindata_part'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored);
        }
        $this->assertSame(['SOURCE_CATALOGUED'], CivilImportAuditEvent::query()->pluck('event_type')->all());
    }

    public function test_an_unset_missing_or_symlinked_root_catalogues_nothing(): void
    {
        $this->place('export-a');
        $this->assertSame(0, (new CivilImportSourceCatalogue(null))->scan()['sources']);
        $this->assertSame(0, (new CivilImportSourceCatalogue($this->root.DIRECTORY_SEPARATOR.'missing'))->scan()['sources']);
        $link = sys_get_temp_dir().DIRECTORY_SEPARATOR.'famboook-u2-rootlink-'.bin2hex(random_bytes(4));
        $this->symlinkOrSkip($this->root, $link);
        try {
            $this->assertSame(0, (new CivilImportSourceCatalogue($link))->scan()['sources']);
        } finally {
            @unlink($link) || @rmdir($link);
        }
    }

    public function test_symlinked_sources_and_symlinked_files_are_refused(): void
    {
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'famboook-u2-outside-'.bin2hex(random_bytes(4));
        mkdir($outside, 0700);
        try {
            $this->place('elsewhere', $outside);
            $this->symlinkOrSkip($outside.DIRECTORY_SEPARATOR.'elsewhere', $this->root.DIRECTORY_SEPARATOR.'escape');
            $dir = $this->place('export-with-link');
            unlink($dir.DIRECTORY_SEPARATOR.'t03maindata_part_00002.csv');
            $this->symlinkOrSkip($outside.DIRECTORY_SEPARATOR.'elsewhere'.DIRECTORY_SEPARATOR.'t03maindata_part_00002.csv',
                $dir.DIRECTORY_SEPARATOR.'t03maindata_part_00002.csv');

            (new CivilImportSourceCatalogue($this->root))->scan();
            $this->assertSame(['export-with-link'], CivilImportSource::query()->pluck('label')->all());
            $source = CivilImportSource::query()->sole();
            $this->assertSame('INVALID', $source->manifest_status);
            $this->assertSame(['FILE_IS_SYMLINK' => 1], $source->manifest_problems);
        } finally {
            self::removeTree($outside);
        }
    }

    public function test_a_source_is_rechecked_when_used(): void
    {
        $dir = $this->place('export-recheck');
        $catalogue = new CivilImportSourceCatalogue($this->root);
        $catalogue->scan();
        $source = CivilImportSource::query()->sole();
        $this->assertCount(2, $catalogue->manifest($source)->files);

        // The manifest changed after cataloguing.
        $manifest = json_decode(file_get_contents($dir.DIRECTORY_SEPARATOR.IdentityManifest::FILE_NAME), true);
        $manifest['encoding'] = 'UTF-8';
        file_put_contents($dir.DIRECTORY_SEPARATOR.IdentityManifest::FILE_NAME, json_encode($manifest));
        $this->assertRefused($catalogue, $source, 'SOURCE_CHANGED_SINCE_CATALOGUE');

        // The directory is gone: unavailable, and the next scan marks it so.
        self::removeTree($dir);
        $this->assertRefused($catalogue, $source, 'SOURCE_UNAVAILABLE');
        $this->assertSame(['catalogued' => 0, 'unavailable' => 1, 'sources' => 0], $catalogue->scan());
        $this->assertFalse($source->fresh()->available);
        $this->assertTrue(CivilImportAuditEvent::query()->where('event_type', 'SOURCE_UNAVAILABLE')->exists());
    }

    public function test_an_invalid_manifest_is_catalogued_with_codes_only(): void
    {
        $dir = $this->place('export-bad');
        $manifest = json_decode(file_get_contents($dir.DIRECTORY_SEPARATOR.IdentityManifest::FILE_NAME), true);
        $manifest['columns'] = array_reverse($manifest['columns']);
        $manifest['files'][] = ['file' => '../../etc/passwd.csv', 'rows' => 1, 'sha256' => str_repeat('c', 64)];
        file_put_contents($dir.DIRECTORY_SEPARATOR.IdentityManifest::FILE_NAME, json_encode($manifest));
        file_put_contents($dir.DIRECTORY_SEPARATOR.'extra.csv', 'x');

        (new CivilImportSourceCatalogue($this->root))->scan();
        $source = CivilImportSource::query()->sole();
        $this->assertSame('INVALID', $source->manifest_status);
        $this->assertEqualsCanonicalizing(['MANIFEST_COLUMNS_MISMATCH', 'MANIFEST_FILE_NAME_UNSAFE', 'UNEXPECTED_ENTRIES'], array_keys($source->manifest_problems));   // jsonb orders keys itself
        $this->assertNull($source->file_count);
        $this->assertStringNotContainsString('passwd', (string) json_encode($source->manifest_problems));
    }

    private function assertRefused(CivilImportSourceCatalogue $catalogue, CivilImportSource $source, string $reason): void
    {
        try {
            $catalogue->manifest($source);
            $this->fail('accepted the source');
        } catch (CivilImportSourceException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path)) {
                @unlink($path) || @rmdir($path);
            } elseif (is_dir($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
