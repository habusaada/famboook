<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Import Apply activation gate (docs/03 §96b, docs/06 §61, docs/08)
    |--------------------------------------------------------------------------
    |
    | Off by default: deploying the Apply code grants nothing. When true, the
    | RolePermissionSeeder baseline grants `import.apply` to SUPER_ADMIN only
    | (RolePermissionSeeder::GATED_ROLE_PERMISSIONS) and
    | `famboook:verify-permissions` requires exactly that. Activation is an
    | explicit, documented operational step (docs/08) — never a manual DB edit.
    |
    */

    'apply_enabled' => (bool) env('IMPORT_APPLY_ENABLED', false),

];
