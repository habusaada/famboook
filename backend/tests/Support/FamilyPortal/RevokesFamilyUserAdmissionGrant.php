<?php

namespace Tests\Support\FamilyPortal;

use App\Actions\FamilyPortal\AdmitRegistryChildAction;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * FB-FAMILY-ADD-01: FAMILY_USER holds family-member.admit-from-registry (owner decision
 * 2026-11-10, general release; docs/06 §61d-2). A test of the "permission missing" path,
 * or of the per-account PILOT model, removes that role grant first so that the permission
 * check itself stays under test.
 */
trait RevokesFamilyUserAdmissionGrant
{
    protected function revokeFamilyUserAdmissionGrant(): void
    {
        Role::findByName('FAMILY_USER', 'web')->revokePermissionTo(AdmitRegistryChildAction::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
