<?php

namespace Tests\Feature\Import;

use App\Models\ImportRow;
use App\Support\ImportRawPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * docs/03 §96a: the source fields هويتك and الديانة are never persisted —
 * the parser drops them (sanitize) and the ImportRow model refuses them
 * (assertClean) on every write path.
 */
class ImportPayloadPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function excludedHeaders(): array
    {
        return [
            'هويتك' => ['هويتك'],
            'الديانة' => ['الديانة'],
            'padded' => ['  هويتك '],
            'teh marbuta written as heh' => ['الديانه'],
            'tatweel' => ['الديـانة'],
            'diacritics' => ['هُوِيَّتُكَ'],
        ];
    }

    #[DataProvider('excludedHeaders')]
    public function test_excluded_headers_are_recognised(string $header): void
    {
        $this->assertTrue(ImportRawPayload::isExcluded($header));
    }

    public function test_approved_headers_are_not_excluded(): void
    {
        foreach (['رقم الهوية', 'هوية الزوجة', 'الاسم', 'المدينة', 'حالة الوفاة', 'الجوال'] as $header) {
            $this->assertFalse(ImportRawPayload::isExcluded($header), $header);
        }
    }

    public function test_sanitize_drops_excluded_fields_at_any_depth_and_keeps_the_rest(): void
    {
        $clean = ImportRawPayload::sanitize([
            'هويتك' => '000000009',
            'رقم الهوية' => '000000000',
            'الديانة' => 'قيمة',
            'الاسم' => 'اسم تجريبي',
            'wives' => [['هوية الزوجة' => '000000001', 'الديانه' => 'قيمة']],
        ]);

        $this->assertSame([
            'رقم الهوية' => '000000000',
            'الاسم' => 'اسم تجريبي',
            'wives' => [['هوية الزوجة' => '000000001']],
        ], $clean);
    }

    #[DataProvider('excludedHeaders')]
    public function test_an_import_row_refuses_to_persist_an_excluded_field(string $header): void
    {
        try {
            ImportRow::factory()->create(['raw_payload' => ['الاسم' => 'اسم تجريبي', $header => 'قيمة']]);
            $this->fail('Expected the excluded field to be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, DB::table('import_rows')->count());
    }

    public function test_nested_excluded_fields_are_refused_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImportRow::factory()->create(['raw_payload' => ['wives' => [['الزوجة' => 'زوجة', 'الديانة' => 'قيمة']]]]);
    }

    public function test_an_update_cannot_add_an_excluded_field(): void
    {
        $row = ImportRow::factory()->create();

        try {
            $row->update(['raw_payload' => [...$row->raw_payload, 'هويتك' => '000000009']]);
            $this->fail('Expected the excluded field to be refused.');
        } catch (InvalidArgumentException $e) {
            // The value is never echoed.
            $this->assertStringNotContainsString('000000009', $e->getMessage());
        }

        $this->assertStringNotContainsString('هويتك', DB::table('import_rows')->value('raw_payload'));
    }

    public function test_a_sanitized_payload_is_stored_without_the_excluded_fields(): void
    {
        ImportRow::factory()->create(['raw_payload' => ImportRawPayload::sanitize([
            'هويتك' => '000000009',
            'الديانة' => 'قيمة',
            'الاسم' => 'اسم تجريبي',
        ])]);

        $stored = DB::table('import_rows')->value('raw_payload');
        $this->assertStringNotContainsString('000000009', $stored);
        $this->assertSame(['الاسم' => 'اسم تجريبي'], json_decode($stored, true));
    }
}
