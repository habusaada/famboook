<?php

namespace App\Console\Commands;

use App\Enums\FamilyStatus;
use App\Enums\FamilySubmissionMode;
use App\Models\Family;
use App\Support\ChangeRequests\FamilySubmissionPolicy;
use Illuminate\Console\Command;

/**
 * Change Request submission readiness (docs/08 §16b, PWA-6.1b) — READ-ONLY.
 * Prints the EFFECTIVE family submission mode and counts / YES-NO values
 * only: never a Family id, code or name from the pilot allowlist.
 *
 * Warnings (exit code 1): a mode value that is not OFF / PILOT / GENERAL
 * (it is treated as OFF), a malformed allowlist (no Family may submit),
 * PILOT with no usable allowlist entry, allowlisted ids that are not an
 * active Family, and the replaced boolean CHANGE_REQUESTS_FAMILY_SUBMISSION_
 * ENABLED still being set (it opens nothing, but should be removed).
 */
class ChangeRequestsCheck extends Command
{
    protected $signature = 'famboook:change-requests-check';

    protected $description = 'Read-only check of the family Change Request submission mode and pilot allowlist (counts only).';

    public function handle(): int
    {
        $warnings = [];
        $mode = FamilySubmissionPolicy::mode();
        $raw = config('change_requests.family_submission_mode');
        $rawValid = is_string($raw) && FamilySubmissionMode::tryFrom(strtoupper(trim($raw))) !== null;
        if (! $rawValid) {
            $warnings[] = 'CHANGE_REQUESTS_FAMILY_SUBMISSION_MODE is missing or invalid — treated as OFF.';
        }

        $ids = FamilySubmissionPolicy::pilotFamilyIds();
        $active = $ids === null || $ids === [] ? 0 : Family::query()->whereKey($ids)->where('status', FamilyStatus::ACTIVE)->count();

        $this->line('Family submission mode (effective): '.$mode->value);
        $this->line('Pilot allowlist: '.match (true) {
            $ids === null => 'INVALID (no Family may submit in PILOT)',
            $ids === [] => 'empty',
            default => count($ids).' id(s), '.$active.' active Family(ies)',
        });
        $this->line('Legacy CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED set: '.(config('change_requests.legacy_family_submission_enabled') === null ? 'NO' : 'YES'));

        if ($ids === null) {
            $warnings[] = 'CHANGE_REQUESTS_PILOT_FAMILY_IDS is malformed (canonical Family ids, comma-separated).';
        } elseif ($active !== count($ids)) {
            $warnings[] = 'Some allowlisted ids are not active Families.';
        }
        if ($mode === FamilySubmissionMode::PILOT && ($ids === null || $active === 0)) {
            $warnings[] = 'PILOT mode with no usable allowlisted Family: nobody can submit.';
        }
        if (config('change_requests.legacy_family_submission_enabled') !== null) {
            $warnings[] = 'Remove CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED: it is replaced by CHANGE_REQUESTS_FAMILY_SUBMISSION_MODE and opens nothing.';
        }

        if ($warnings === []) {
            $this->info('Change Request submission: no warnings.');

            return self::SUCCESS;
        }
        foreach ($warnings as $warning) {
            $this->warn("WARN: {$warning}");
        }

        return self::FAILURE;
    }
}
