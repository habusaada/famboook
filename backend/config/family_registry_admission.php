<?php

/*
 * FB-FAMILY-ADD-01 — direct admission of civil-verified children
 * (docs/adr/FB-FAMILY-ADD-01-DIRECT-ADMISSION.md §11).
 *
 * OFF (default) | PILOT | ON. Read ONLY through FamilyRegistryAdmissionPolicy,
 * which fails closed: missing or invalid → OFF. Foundation only: nothing reads
 * it to open a route or a workflow yet, so no value activates anything.
 * Independent of every other switch. Changing it needs gate G-07.
 */
return [

    'mode' => env('FAMILY_REGISTRY_ADMISSION_MODE', 'OFF'),

];
