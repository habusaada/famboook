<?php

namespace App\Support\Geography;

use App\Enums\GazaLocationLevel;
use App\Enums\GazaLocationReviewIssue;

/**
 * One version of the Gaza geographic classification workbook and what is
 * expected of it (ADR-FB-GEO-01 §3.2–§3.3).
 *
 * official() is the audited MoSD workbook (Stage 1A): it is kept OUTSIDE Git
 * (Stage 1B D-10) at data/references/ next to backend/, and is only accepted
 * with its audited SHA-256. A new source version needs a new audit, a new
 * checksum here and explicit authorization (Stage 1B G10).
 *
 * auditedFindings are the Stage 1A judgements that no objective rule can
 * reproduce (W1 parent-place mismatches, W2 unusual landmark types). They
 * belong to the audited version only; each must exist in the source or the
 * import aborts.
 */
final class GazaGeographySource
{
    public const SHEET = 'ALL_GEO_LAND_VW';

    /** The audited header, in order (Stage 1A §2). */
    public const COLUMNS = [
        'GOV_PK', 'GOV_TITLE', 'CIT_PK', 'CIT_TITLE', 'GEO_PK', 'GEO_TITLE', 'NEI_PK',
        'NEI_TITLE', 'LAN_TITLE', 'LAN_PK', 'LAN_TYPE', 'LAN_TYPE_TITLE', 'LAN_TITLE_NEW',
    ];

    /** Code and name columns per level. A landmark's own name is LAN_TITLE_NEW. */
    public const LEVEL_COLUMNS = [
        'GOVERNORATE' => ['GOV_PK', 'GOV_TITLE'],
        'CITY' => ['CIT_PK', 'CIT_TITLE'],
        'AREA' => ['GEO_PK', 'GEO_TITLE'],
        'NEIGHBORHOOD' => ['NEI_PK', 'NEI_TITLE'],
        'LANDMARK' => ['LAN_PK', 'LAN_TITLE_NEW'],
    ];

    public const OFFICIAL_RELATIVE_PATH = 'data/references/gaza-geographic-classification.xlsx';

    public const OFFICIAL_SHA256 = '37826e400b77e583dbd0c2e7fe34274db6465a293437ce11d0c4d20c4f945e38';

    public const OFFICIAL_AUTHORITY = 'Palestinian Ministry of Social Development (MoSD)';

    /**
     * @param  list<array{issue: GazaLocationReviewIssue, level: GazaLocationLevel, source_code: string, note: string}>  $auditedFindings
     * @param  ?string  $version  as stated by the source authority; NULL when unstated (never invented)
     */
    public function __construct(
        public readonly string $path,
        public readonly string $expectedSha256,
        public readonly array $auditedFindings = [],
        public readonly string $authority = 'Unspecified source',
        public readonly ?string $version = null,
    ) {}

    public static function official(?string $path = null): self
    {
        return new self(
            $path ?? dirname(base_path()).DIRECTORY_SEPARATOR.self::OFFICIAL_RELATIVE_PATH,
            self::OFFICIAL_SHA256,
            self::officialAuditedFindings(),
            self::OFFICIAL_AUTHORITY,
            // Dataset version unverified (Stage 1B D-1).
            null,
        );
    }

    /** @return list<array{issue: GazaLocationReviewIssue, level: GazaLocationLevel, source_code: string, note: string}> */
    private static function officialAuditedFindings(): array
    {
        $findings = [];

        // Stage 1A W1 (§7.1): named after a place other than the recorded parent.
        $rafahUnderKhanYounis = 'Name refers to Rafah; recorded under Khan Younis. Confirm with the source authority.';
        $jabaliaElsewhere = 'Name refers to Jabalia; recorded under another city. Confirm with the source authority.';
        foreach (['558', '562', '564', '566', '560', '546', '556'] as $code) {
            $findings[] = self::finding(GazaLocationReviewIssue::PARENT_PLACE_MISMATCH, GazaLocationLevel::NEIGHBORHOOD, $code, $rafahUnderKhanYounis);
        }
        foreach (['170', '156', '204'] as $code) {
            $findings[] = self::finding(GazaLocationReviewIssue::PARENT_PLACE_MISMATCH, GazaLocationLevel::NEIGHBORHOOD, $code, $jabaliaElsewhere);
        }

        // Stage 1A W2 (§6.2): type values from a non-landmark category.
        foreach (['1218', '1208', '1204', '2341', '964'] as $code) {
            $findings[] = self::finding(
                GazaLocationReviewIssue::UNUSUAL_LANDMARK_TYPE, GazaLocationLevel::LANDMARK, $code,
                'Landmark type is not a landmark category; type kept as supplied.',
            );
        }

        return $findings;
    }

    /** @return array{issue: GazaLocationReviewIssue, level: GazaLocationLevel, source_code: string, note: string} */
    private static function finding(GazaLocationReviewIssue $issue, GazaLocationLevel $level, string $code, string $note): array
    {
        return ['issue' => $issue, 'level' => $level, 'source_code' => $code, 'note' => $note];
    }
}
