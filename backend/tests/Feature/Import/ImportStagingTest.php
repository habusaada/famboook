<?php

namespace Tests\Feature\Import;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Models\Family;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use ValueError;

/**
 * Import staging foundation (docs/02 §88a, docs/04 §83a): batches and
 * staged rows only. Nothing here parses a file or writes registry data.
 */
class ImportStagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_batch_with_uuid_status_and_users(): void
    {
        $uploader = User::factory()->create();
        $applier = User::factory()->create();

        $batch = ImportBatch::create([
            'source_filename' => 'synthetic.xlsx',
            'source_checksum' => hash('sha256', 'synthetic'),
            'status' => ImportBatchStatus::UPLOADED,
            'row_count' => 3,
            'uploaded_by' => $uploader->id,
        ]);

        $batch = $batch->fresh();
        $this->assertTrue(Str::isUuid($batch->uuid));
        $this->assertSame('uuid', $batch->getRouteKeyName());
        $this->assertSame(ImportBatchStatus::UPLOADED, $batch->status);
        $this->assertSame(3, $batch->row_count);
        $this->assertTrue($batch->uploader->is($uploader));
        $this->assertNull($batch->applier);

        $batch->update(['status' => ImportBatchStatus::APPLIED, 'applied_by' => $applier->id, 'applied_at' => now()]);
        $this->assertTrue($batch->fresh()->applier->is($applier));
        $this->assertNotNull($batch->fresh()->applied_at);
    }

    public function test_statuses_only_accept_known_values(): void
    {
        $this->expectException(ValueError::class);
        ImportBatch::factory()->create(['status' => 'DONE']);
    }

    public function test_row_statuses_only_accept_known_values(): void
    {
        $this->expectException(ValueError::class);
        ImportRow::factory()->create(['status' => 'OK']);
    }

    public function test_creates_rows_with_json_payload_and_issues(): void
    {
        $batch = ImportBatch::factory()->create();
        $payload = [
            'الاسم' => 'اسم تجريبي البريم',
            'رقم الهوية' => '000000000',
            'أفراد الأسرة' => '7',
            'wives' => [['هوية الزوجة' => '000000001', 'الزوجة' => 'زوجة تجريبية']],
        ];
        $issues = [['code' => 'BRANCH_UNMATCHED', 'severity' => 'FLAG']];

        $row = ImportRow::create([
            'import_batch_id' => $batch->id,
            'row_number' => 2,
            'raw_payload' => $payload,
            'status' => ImportRowStatus::FLAGGED,
            'issues' => $issues,
        ])->fresh();

        $this->assertSame($payload, $row->raw_payload);
        $this->assertSame($issues, $row->issues);
        $this->assertSame(ImportRowStatus::FLAGGED, $row->status);
        $this->assertTrue($row->batch->is($batch));
        $this->assertNull($row->family);
    }

    public function test_batch_rows_are_ordered_by_source_row_number(): void
    {
        $batch = ImportBatch::factory()->create();
        foreach ([5, 2, 9] as $n) {
            ImportRow::factory()->create(['import_batch_id' => $batch->id, 'row_number' => $n]);
        }

        $this->assertSame([2, 5, 9], $batch->rows->pluck('row_number')->all());
        $this->assertSame(1, ImportRow::withStatus(ImportRowStatus::PENDING)->where('row_number', 5)->count());
    }

    public function test_row_number_is_unique_within_a_batch(): void
    {
        $batch = ImportBatch::factory()->create();
        ImportRow::factory()->create(['import_batch_id' => $batch->id, 'row_number' => 2]);
        // Same number in another batch is fine.
        ImportRow::factory()->create(['row_number' => 2]);

        $this->expectException(QueryException::class);
        ImportRow::factory()->create(['import_batch_id' => $batch->id, 'row_number' => 2]);
    }

    public function test_an_applied_row_references_its_family_and_a_family_has_at_most_one_row(): void
    {
        $family = Family::factory()->create();
        $row = ImportRow::factory()->create(['status' => ImportRowStatus::APPLIED, 'family_id' => $family->id]);

        $this->assertTrue($row->fresh()->family->is($family));

        $this->expectException(QueryException::class);
        ImportRow::factory()->create(['status' => ImportRowStatus::APPLIED, 'family_id' => $family->id]);
    }

    public function test_raw_payload_is_hidden_from_serialization(): void
    {
        $row = ImportRow::factory()->create(['raw_payload' => ['رقم الهوية' => '000000000']]);

        $this->assertArrayNotHasKey('raw_payload', $row->toArray());
        $this->assertStringNotContainsString('000000000', $row->toJson());
    }

    public function test_a_batch_with_rows_cannot_be_deleted(): void
    {
        $row = ImportRow::factory()->create();

        $this->expectException(QueryException::class);
        $row->batch->delete();
    }
}
