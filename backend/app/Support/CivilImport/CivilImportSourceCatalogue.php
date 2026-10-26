<?php

namespace App\Support\CivilImport;

use App\Models\CivilImportSource;
use App\Support\IdentityRef\IdentityManifest;
use App\Support\IdentityRef\IdentityManifestException;
use Illuminate\Support\Carbon;

/**
 * The server-side source catalogue (FB-CIVIL-01A-UI U2, D-UI-3, ADR §6).
 * WORKER ONLY: the web process never reads the source root.
 *
 * A source is a direct sub-directory of the configured root whose name
 * matches CivilImportSource::LABEL_PATTERN and that holds a manifest.json.
 * Refused, both when cataloguing and again when a request executes:
 * symlinked directories, entries resolving outside the root, unsafe names,
 * a symlinked root. Recorded per source: an opaque id, the label, the
 * manifest verdict as codes, table, file count, total rows and the manifest
 * SHA-256 (change detection only). Never a path, a file name or content.
 */
final class CivilImportSourceCatalogue
{
    public function __construct(private readonly ?string $root) {}

    public static function fromConfig(): self
    {
        $root = config('civil_import.worker.source_root');

        return new self(is_string($root) && $root !== '' ? $root : null);
    }

    /**
     * Re-scans the root and updates the catalogue.
     *
     * @return array{catalogued: int, unavailable: int, sources: int}
     */
    public function scan(): array
    {
        $root = $this->rootPath();
        $seen = [];
        $catalogued = 0;
        foreach ($root === null ? [] : (scandir($root) ?: []) as $entry) {
            $dir = $this->sourceDirectory($root, $entry);
            if ($dir === null || ! is_file($dir.DIRECTORY_SEPARATOR.IdentityManifest::FILE_NAME)) {
                continue;
            }
            $seen[] = $entry;
            [$source, $new] = $this->record($entry, $dir);
            if ($new || $source->wasChanged('available')) {
                $catalogued++;
                CivilImportAudit::record('SOURCE_CATALOGUED', null, details: array_filter([
                    'files' => $source->file_count, 'rows' => $source->total_rows,
                    'code' => $source->manifest_status === CivilImportSource::VALID ? null : 'MANIFEST_INVALID',
                ], fn ($v) => $v !== null));
            }
        }

        $unavailable = 0;
        foreach (CivilImportSource::query()->where('available', true)->whereNotIn('label', $seen)->get() as $gone) {
            $gone->forceFill(['available' => false, 'updated_at' => Carbon::now()])->save();
            CivilImportAudit::record('SOURCE_UNAVAILABLE', null);
            $unavailable++;
        }

        return ['catalogued' => $catalogued, 'unavailable' => $unavailable, 'sources' => count($seen)];
    }

    /**
     * The validated manifest of a catalogued source, re-checked now: the
     * directory must still be a safe sub-directory of the root and its
     * manifest unchanged since cataloguing.
     *
     * @throws CivilImportSourceException
     */
    public function manifest(CivilImportSource $source): IdentityManifest
    {
        $root = $this->rootPath();
        $dir = $root === null ? null : $this->sourceDirectory($root, (string) $source->label);
        $manifestPath = $dir === null ? null : $dir.DIRECTORY_SEPARATOR.IdentityManifest::FILE_NAME;
        if ($manifestPath === null || is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new CivilImportSourceException(CivilImportReason::SOURCE_UNAVAILABLE);
        }
        if ($source->manifest_sha256 === null || ! hash_equals($source->manifest_sha256, (string) hash_file('sha256', $manifestPath))) {
            throw new CivilImportSourceException(CivilImportReason::SOURCE_CHANGED_SINCE_CATALOGUE);
        }
        try {
            return IdentityManifest::fromDirectory($dir, $source->file_count, $source->total_rows);
        } catch (IdentityManifestException $e) {
            throw new CivilImportSourceException('MANIFEST_INVALID', CivilImportResult::problemCodes($e->problems));
        }
    }

    /** The real path of the root, or null when unset, missing, not a directory or a symlink. */
    private function rootPath(): ?string
    {
        if ($this->root === null || is_link(rtrim($this->root, '\\/'))) {
            return null;
        }
        $real = realpath($this->root);

        return $real !== false && is_dir($real) ? $real : null;
    }

    /** The real path of a safe, direct, non-symlinked sub-directory of $root named $entry, or null. */
    private function sourceDirectory(string $root, string $entry): ?string
    {
        if (preg_match(CivilImportSource::LABEL_PATTERN, $entry) !== 1) {
            return null;
        }
        $path = $root.DIRECTORY_SEPARATOR.$entry;
        if (is_link($path) || ! is_dir($path)) {
            return null;
        }
        $real = realpath($path);

        return $real !== false && dirname($real) === $root && basename($real) === $entry ? $real : null;
    }

    /** @return array{0: CivilImportSource, 1: bool} */
    private function record(string $label, string $dir): array
    {
        $manifestPath = $dir.DIRECTORY_SEPARATOR.IdentityManifest::FILE_NAME;
        $attributes = ['manifest_sha256' => is_link($manifestPath) ? null : (hash_file('sha256', $manifestPath) ?: null),
            'available' => true, 'last_seen_at' => Carbon::now()];
        try {
            $manifest = IdentityManifest::fromDirectory($dir);
            $attributes += ['manifest_status' => CivilImportSource::VALID, 'manifest_problems' => null,
                'source_table' => $manifest->table, 'file_count' => count($manifest->files), 'total_rows' => $manifest->totalRows];
        } catch (IdentityManifestException $e) {
            $attributes += ['manifest_status' => CivilImportSource::INVALID, 'manifest_problems' => CivilImportResult::problemCodes($e->problems),
                'source_table' => null, 'file_count' => null, 'total_rows' => null];
        }
        $source = CivilImportSource::query()->firstWhere('label', $label);
        $new = $source === null;
        $source ??= new CivilImportSource(['label' => $label]);
        $source->fill($attributes);
        $source->updated_at = Carbon::now();
        $source->save();

        return [$source, $new];
    }
}
