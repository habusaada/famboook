<?php

namespace App\Console\Commands;

use App\Enums\CoordinatorSubmissionMode;
use App\Enums\FamilyStatus;
use App\Enums\FamilySubmissionMode;
use App\Models\Family;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\ChangeRequests\CoordinatorSubmissionPolicy;
use App\Support\ChangeRequests\FamilySubmissionPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Change Request submission readiness (docs/08 §16b, PWA-6.1b; CO-02.7c) —
 * READ-ONLY. Prints the EFFECTIVE submission modes and counts / YES-NO
 * values only: never a Family id, code or name, never a user id or name
 * from an allowlist.
 *
 * Family channel (unchanged output). Warnings (exit code 1): a mode value
 * that is not OFF / PILOT / GENERAL (it is treated as OFF), a malformed
 * allowlist (no Family may submit), PILOT with no usable allowlist entry,
 * allowlisted ids that are not an active Family, and the replaced boolean
 * CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED still being set (it opens
 * nothing, but should be removed).
 *
 * Coordinator channel (CO-02, docs/adr/CO-02-COORDINATOR-REQUESTS.md §11,
 * §20). Warnings: an invalid mode (treated as OFF), a malformed allowlist
 * (nobody may submit), PILOT with no eligible allowlisted Coordinator,
 * allowlisted ids that are not an eligible Coordinator account (active,
 * COORDINATOR role, coordinator-request.submit), the four
 * coordinator-request.* permissions not held by COORDINATOR only, and a
 * CO-02 migration that has not run.
 */
class ChangeRequestsCheck extends Command
{
    protected $signature = 'famboook:change-requests-check';

    protected $description = 'Read-only check of the Change Request submission modes, pilot allowlists, coordinator permissions and CO-02 migrations (counts only).';

    /** The coordinator-request permissions — COORDINATOR only (docs/06 AUTH-ADR-093). */
    public const COORDINATOR_PERMISSIONS = [
        'coordinator-request.view',
        'coordinator-request.submit',
        'coordinator-request.resubmit',
        'coordinator-request.cancel',
    ];

    /** The migrations the CO-02 release depends on, in order. */
    public const CO02_MIGRATIONS = [
        '2026_10_19_090000_add_family_id_to_auth_security_events',
        '2026_10_20_090000_enforce_unique_person_national_id',
        '2026_10_21_090000_add_coordinator_change_requests',
    ];

    public function handle(): int
    {
        $warnings = [...$this->familyChannel(), ...$this->coordinatorChannel(), ...$this->coordinatorPermissions(), ...$this->migrations()];

        if ($warnings === []) {
            $this->info('Change Request submission: no warnings.');

            return self::SUCCESS;
        }
        foreach ($warnings as $warning) {
            $this->warn("WARN: {$warning}");
        }

        return self::FAILURE;
    }

    /** @return list<string> the family channel's warnings (output unchanged since PWA-6.1b) */
    private function familyChannel(): array
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

        return $warnings;
    }

    /** @return list<string> the coordinator channel's warnings (CO-02.7c) */
    private function coordinatorChannel(): array
    {
        $warnings = [];
        $mode = CoordinatorSubmissionPolicy::mode();
        $raw = config('change_requests.coordinator_submission_mode');
        if (! is_string($raw) || CoordinatorSubmissionMode::tryFrom(strtoupper(trim($raw))) === null) {
            $warnings[] = 'CHANGE_REQUESTS_COORDINATOR_SUBMISSION_MODE is missing or invalid — treated as OFF.';
        }

        $ids = CoordinatorSubmissionPolicy::pilotUserIds();
        $eligible = $ids === null || $ids === [] ? 0 : $this->eligibleCoordinators($ids);

        $this->line('Coordinator submission mode (effective): '.$mode->value);
        $this->line('Coordinator pilot allowlist: '.match (true) {
            $ids === null => 'INVALID (no Coordinator may submit in PILOT)',
            $ids === [] => 'empty',
            default => count($ids).' id(s), '.$eligible.' eligible Coordinator account(s)',
        });

        if ($ids === null) {
            $warnings[] = 'CHANGE_REQUESTS_COORDINATOR_PILOT_USER_IDS is malformed (canonical user ids, comma-separated).';
        } elseif ($eligible !== count($ids)) {
            $warnings[] = 'Some coordinator allowlisted ids are not eligible Coordinator accounts.';
        }
        if ($mode === CoordinatorSubmissionMode::PILOT && ($ids === null || $eligible === 0)) {
            $warnings[] = 'Coordinator PILOT mode with no eligible allowlisted Coordinator: nobody can submit.';
        }

        return $warnings;
    }

    /**
     * Allowlisted ids that are an eligible Coordinator account: active, the
     * COORDINATOR role and coordinator-request.submit. (Whether the account
     * also has an effective scope and household-head eligibility is decided
     * per request by CoordinatorScopes; it is not repeated here.)
     *
     * @param  list<int>  $ids
     */
    private function eligibleCoordinators(array $ids): int
    {
        return User::query()->whereKey($ids)->where('is_active', true)->get()
            ->filter(fn (User $user) => $user->hasRole(AccountSide::COORDINATOR) && $user->can('coordinator-request.submit'))
            ->count();
    }

    /** @return list<string> the coordinator-request permissions held by COORDINATOR — and by no other role */
    private function coordinatorPermissions(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $present = Permission::query()->whereIn('name', self::COORDINATOR_PERMISSIONS)->pluck('name')->all();
        $coordinator = Role::query()->where('name', AccountSide::COORDINATOR)->first();
        $held = $coordinator === null ? [] : $coordinator->permissions()->whereIn('name', self::COORDINATOR_PERMISSIONS)->pluck('name')->all();
        $elsewhere = Role::query()->where('name', '!=', AccountSide::COORDINATOR)
            ->whereHas('permissions', fn ($q) => $q->whereIn('name', self::COORDINATOR_PERMISSIONS))
            ->count();

        $ok = count($present) === count(self::COORDINATOR_PERMISSIONS) && count($held) === count(self::COORDINATOR_PERMISSIONS) && $elsewhere === 0;
        $this->line('Coordinator request permissions: '.($ok
            ? 'OK (4 of 4, COORDINATOR only)'
            : count($present).' of 4 seeded, '.count($held).' held by COORDINATOR, '.$elsewhere.' other role(s) hold some'));

        return $ok ? [] : ['Coordinator request permissions are not as approved: run RolePermissionSeeder and famboook:verify-permissions.'];
    }

    /** @return list<string> the CO-02 release migrations that have not run */
    private function migrations(): array
    {
        $ran = Schema::hasTable('migrations')
            ? DB::table('migrations')->whereIn('migration', self::CO02_MIGRATIONS)->pluck('migration')->all()
            : [];
        $warnings = [];
        foreach (self::CO02_MIGRATIONS as $migration) {
            $applied = in_array($migration, $ran, true);
            $this->line("Migration {$migration}: ".($applied ? 'applied' : 'PENDING'));
            if (! $applied) {
                $warnings[] = "Migration {$migration} has not run.";
            }
        }

        return $warnings;
    }
}
