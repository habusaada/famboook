<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * FB-FAMILY-ADD-01 PILOT access (G-07; docs/06 §61d-2). The ONLY way the admission
 * permission is meant to reach an account during the pilot: per account, never through a
 * role.
 *
 * - grant: to the ONE account of an allowlisted Family's eligible household head, chosen
 *   here from the Family (never a user id from the operator), with the G-07 approval
 *   reference. Refused when the Family is not in FAMILY_REGISTRY_ADMISSION_PILOT_FAMILY_IDS,
 *   when the head has no eligible account, or when any role already holds the permission.
 * - revoke: from the accounts linked to that Family's head.
 * - revoke-all: every direct grant (the pilot shutdown; needs nothing else).
 *
 * Grants and revocations are logged with internal ids and the approval reference only;
 * the output names no account, Person or National ID. It activates nothing by itself:
 * the mode, the allowlist, the binding and the gates still decide.
 */
class RegistryAdmissionPilotAccess extends Command
{
    protected $signature = 'famboook:registry-admission-pilot-access
        {operation : grant, revoke or revoke-all}
        {--family= : The Family code (FAM-…) of an allowlisted pilot Family}
        {--approval= : The recorded G-07 approval reference (grant only)}';

    /** The admission permission (named, not imported: nothing outside the action uses the action). */
    private const PERMISSION = 'family-member.admit-from-registry';

    protected $description = 'Grant or revoke family-member.admit-from-registry for a pilot household head account only (FB-FAMILY-ADD-01, G-07).';

    public function handle(FamilyAccessResolver $resolver): int
    {
        $permission = Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->first();
        if ($permission === null) {
            return $this->refuse('the admission permission does not exist (run the RolePermissionSeeder first)');
        }

        return match ($this->argument('operation')) {
            'grant' => $this->grant($resolver, $permission),
            'revoke' => $this->revoke($permission),
            'revoke-all' => $this->revokeAll($permission),
            default => $this->refuse('the operation must be grant, revoke or revoke-all'),
        };
    }

    private function grant(FamilyAccessResolver $resolver, Permission $permission): int
    {
        $approval = $this->option('approval');
        if (! is_string($approval) || preg_match('#\A[A-Za-z0-9._:/-]{3,80}\z#', $approval) !== 1) {
            return $this->refuse('--approval must name the recorded G-07 decision ([A-Za-z0-9._:/-], 3 … 80 characters)');
        }
        $family = $this->family();
        if ($family === null) {
            return $this->refuse('no such Family');
        }
        if (! in_array((int) $family->getKey(), (array) config('family_registry_admission.pilot_family_ids'), true)) {
            return $this->refuse('the Family is not in FAMILY_REGISTRY_ADMISSION_PILOT_FAMILY_IDS');
        }
        if ($permission->roles()->exists()) {
            return $this->refuse('a role holds the admission permission; a PILOT grant is per account only');
        }
        // Exactly one eligible account: the head's, whose resolved Family context is this Family.
        $eligible = $this->headAccounts($family)->filter(function (User $user) use ($resolver, $family) {
            $context = $resolver->familyContext($user);

            return $context->hasFamilyContext() && $context->family->is($family) && $user->can('family-portal.access');
        });
        if ($eligible->count() !== 1) {
            return $this->refuse('the Family\'s household head has no single eligible Family Portal account');
        }
        $user = $eligible->first();
        $user->givePermissionTo($permission);
        Log::notice('family_registry_admission.pilot_access.granted',
            ['family_id' => $family->getKey(), 'user_id' => $user->getKey(), 'approval_reference' => $approval]);
        $this->info('Granted the admission permission to the household head account of this Family (1 account).');

        return self::SUCCESS;
    }

    private function revoke(Permission $permission): int
    {
        $family = $this->family();
        if ($family === null) {
            return $this->refuse('no such Family');
        }
        $revoked = 0;
        foreach ($this->headAccounts($family) as $user) {
            if ($user->hasDirectPermission($permission)) {
                $user->revokePermissionTo($permission);
                $revoked++;
            }
        }
        Log::notice('family_registry_admission.pilot_access.revoked', ['family_id' => $family->getKey(), 'accounts' => $revoked]);
        $this->info("Revoked the admission permission from {$revoked} account(s) of this Family's household head.");

        return self::SUCCESS;
    }

    private function revokeAll(Permission $permission): int
    {
        $revoked = DB::table(config('permission.table_names.model_has_permissions'))->where('permission_id', $permission->getKey())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::notice('family_registry_admission.pilot_access.revoked_all', ['accounts' => $revoked]);
        $this->info("Revoked every direct grant of the admission permission ({$revoked}).");

        return self::SUCCESS;
    }

    private function family(): ?Family
    {
        $code = $this->option('family');

        return is_string($code) && preg_match('/\AFAM-[0-9]{6,}\z/', $code) === 1 ? Family::query()->where('family_code', $code)->first() : null;
    }

    /** @return Collection<int, User> the accounts currently linked to the Family's active head */
    private function headAccounts(Family $family)
    {
        $head = $family->householdHeadMembership()->first();
        if ($head === null) {
            return collect();
        }

        return User::query()->whereIn('id', UserPersonLink::query()->current()->where('person_id', $head->person_id)->select('user_id'))->get();
    }

    private function refuse(string $why): int
    {
        $this->error('Refused: '.$why.'.');

        return self::FAILURE;
    }
}
