<?php

namespace App\Support;

/**
 * Staff roles (docs/06 §14, §59c, AUTH-ADR-057). A Staff user holds exactly
 * one of these in V1. FAMILY_USER is an external role: never a Staff Portal
 * user and never assignable from Staff administration.
 */
final class StaffRoles
{
    public const SUPER_ADMIN = 'SUPER_ADMIN';

    public const ADMINISTRATOR = 'ADMINISTRATOR';

    public const ALL = [
        self::SUPER_ADMIN,
        self::ADMINISTRATOR,
        'DATA_ENTRY',
        'REVIEWER',
        'SOCIAL_WORKER',
        'REPORTS_VIEWER',
    ];

    /** Roles only a SUPER_ADMIN may assign, or manage the holders of. */
    public const PRIVILEGED = [self::SUPER_ADMIN, self::ADMINISTRATOR];

    public const LABELS = [
        'SUPER_ADMIN' => 'مدير النظام',
        'ADMINISTRATOR' => 'مسؤول إداري',
        'DATA_ENTRY' => 'مدخل بيانات',
        'REVIEWER' => 'مراجع',
        'SOCIAL_WORKER' => 'أخصائي اجتماعي',
        'REPORTS_VIEWER' => 'مطلع على التقارير',
    ];

    public static function isStaff(?string $role): bool
    {
        return in_array($role, self::ALL, true);
    }
}
