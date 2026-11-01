<?php

/*
 * FB-FAMILY-RECON-01 — household membership reconciliation
 * (docs/adr/FB-FAMILY-RECON-01-MEMBERSHIP-RECONCILIATION.md).
 *
 * OFF (default) | PILOT | ON. Read ONLY through HouseholdReconciliationPolicy,
 * which fails closed: missing or invalid → OFF. Foundation only: nothing reads
 * it to open a route or a workflow yet, so no value activates anything.
 * Independent of every other switch. Activation needs a separate approval.
 */
return [

    'mode' => env('HOUSEHOLD_RECONCILIATION_MODE', 'OFF'),

];
