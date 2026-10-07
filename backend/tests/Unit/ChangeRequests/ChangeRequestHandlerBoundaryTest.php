<?php

namespace Tests\Unit\ChangeRequests;

use PHPUnit\Framework\TestCase;

/**
 * Architecture boundary (PWA-5b): a Production Change Request handler never
 * writes registry data itself — its apply() calls existing canonical Domain
 * Actions only. Every PHP file under app/Support/ChangeRequests/Handlers is
 * scanned (comments ignored) for direct persistence calls. The detector is
 * proven on samples, so the check is not vacuous while no Production
 * handler exists yet (PWA-6 adds the first).
 */
class ChangeRequestHandlerBoundaryTest extends TestCase
{
    private const HANDLERS = __DIR__.'/../../../app/Support/ChangeRequests/Handlers';

    /** Direct persistence a handler must not contain. */
    private const FORBIDDEN = [
        '/->\s*(save|saveQuietly|update|updateQuietly|delete|forceDelete|insert|insertGetId|upsert|increment|decrement|forceFill|fill|push|touch|restore)\s*\(/i',
        '/::\s*(create|forceCreate|insert|insertGetId|upsert|updateOrCreate|firstOrCreate|updateOrInsert|destroy|truncate)\s*\(/i',
        '/\bDB\s*::\s*(table|statement|update|insert|delete|unprepared|affectingStatement)\s*\(/i',
    ];

    /** @return list<string> the forbidden calls found in PHP code, comments excluded */
    public static function violations(string $php): array
    {
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $found = [];
        foreach (self::FORBIDDEN as $pattern) {
            if (preg_match_all($pattern, $code, $m)) {
                array_push($found, ...$m[0]);
            }
        }

        return $found;
    }

    public function test_production_handlers_call_domain_actions_only(): void
    {
        $files = is_dir(self::HANDLERS) ? glob(self::HANDLERS.'/*.php') : [];
        foreach ($files as $file) {
            $this->assertSame([], self::violations((string) file_get_contents($file)), basename($file).' writes registry data directly');
        }
        $this->addToAssertionCount(1);
    }

    public function test_the_detector_finds_direct_registry_writes(): void
    {
        foreach ([
            '<?php $person->update(["mobile" => $x]);',
            '<?php $family->forceFill(["paper_form_no" => 1])->save();',
            '<?php Person::create($data);',
            '<?php Person::query()->whereKey(1)->delete();',
            '<?php DB::table("persons")->where("id", 1)->update([]);',
            '<?php \Illuminate\Support\Facades\DB::statement("update persons set x = 1");',
            '<?php FamilyResidence::updateOrCreate([], []);',
        ] as $sample) {
            $this->assertNotSame([], self::violations($sample), $sample);
        }
    }

    public function test_the_detector_allows_domain_action_calls_reads_and_comments(): void
    {
        foreach ([
            '<?php app(UpdateFamilyResidenceAction::class)->handle($family, $data, $userId);',
            '<?php $value = Family::query()->whereKey($id)->value("paper_form_no");',
            '<?php // never $person->save() here',
            '<?php /** Do not call DB::table(...) */ $x = 1;',
            '<?php $updated = $request->submitted_data["x"];',
        ] as $sample) {
            $this->assertSame([], self::violations($sample), $sample);
        }
        // The test-only fake handler respects the same boundary.
        $this->assertSame([], self::violations((string) file_get_contents(__DIR__.'/../../Support/ChangeRequests/FakeChangeRequestHandler.php')));
    }
}
