<?php

namespace Tests\Support\IdentityRef;

use App\Actions\PublishIdentityReleaseAction;
use App\Support\IdentityRef\IdentityImportPolicy;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * FB-CIVIL-01A-P3 PostgreSQL helpers on top of IdentityRefDatabase and
 * IdentityRefStaging: stage → prepare → verify → publish through the real
 * actions, each step in a fresh LOGIN session of a throwaway writer member.
 * SYNTHETIC data only.
 */
trait IdentityRefRelease
{
    protected function releases(): PublishIdentityReleaseAction
    {
        return app(PublishIdentityReleaseAction::class);
    }

    /** @param array<string, string|null> ...$rows */
    protected function export(array ...$rows): SyntheticIdentityExport
    {
        return $this->syntheticExport(SyntheticIdentityExport::withFiles(['t03maindata_part_00001.csv' => $rows]));
    }

    /** Synthetic rows for the given mainids (mainid => overrides). */
    protected function rows(array $identities): array
    {
        return array_map(fn ($id, $over) => SyntheticIdentityExport::row((string) $id, $over), array_keys($identities), $identities);
    }

    protected function stagedRun(SyntheticIdentityExport $export): string
    {
        return $this->stage($export)['run_id'];
    }

    protected function prepareRun(string $runId, ?IdentityImportPolicy $policy = null, ?Closure $hook = null, int $chunk = 2, ?RecordingLogger $logger = null): array
    {
        return $this->releases()->prepare($this->writerConnection(), $runId, $policy ?? IdentityImportPolicy::approved(), 'famboook_test', $chunk, $logger, $hook);
    }

    protected function verifyRelease(int $releaseId): array
    {
        return $this->releases()->verify($this->writerConnection(), $releaseId, 'famboook_test');
    }

    protected function publishRelease(int $releaseId, ?Closure $hook = null, ?RecordingLogger $logger = null): array
    {
        return $this->releases()->publish($this->writerConnection(), $releaseId, 'famboook_test', $logger, $hook);
    }

    /** Stage, prepare, verify and publish one synthetic source; returns the publish report. */
    protected function importAndPublish(array $identities, ?IdentityImportPolicy $policy = null): array
    {
        $run = $this->stagedRun($this->syntheticExport(SyntheticIdentityExport::withFiles(['t03maindata_part_00001.csv' => $this->rows($identities)])));
        $prepared = $this->prepareRun($run, $policy);
        $this->verifyRelease($prepared['release_id']);

        return $this->publishRelease($prepared['release_id']);
    }

    protected function activeReleaseId(): ?int
    {
        $id = DB::selectOne('SELECT release_id FROM identity_ref.active_release WHERE id = 1')?->release_id;

        return $id === null ? null : (int) $id;
    }

    /** @return list<string> "mainid:SOURCE_STATE" of the active release, in byte order (read as the administrator) */
    protected function activeSet(): array
    {
        return array_map(fn ($r) => $r->mainid.':'.$r->source_state,
            DB::select('SELECT mainid, source_state FROM identity_ref.active_records ORDER BY mainid COLLATE "C"'));
    }

    protected function activePayload(string $mainid): array
    {
        return json_decode(DB::selectOne('SELECT source_payload FROM identity_ref.active_records WHERE mainid = ?', [$mainid])->source_payload, true);
    }

    /** @return array<string, int> table => rows of the reference store */
    protected function referenceCounts(): array
    {
        return collect(['records', 'record_versions', 'dataset_releases', 'release_presence', 'release_preparations'])
            ->mapWithKeys(fn ($t) => [$t => (int) DB::selectOne("SELECT count(*) AS n FROM identity_ref.{$t}")->n])->all();
    }
}
