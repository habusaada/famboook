<?php

namespace App\Enums;

// docs/03 §68 — the APPROVED Change Request type codes (code registry, AE-1:
// behaviour lives in code, the column is a checked varchar, no type table).
// A code here is not a submittable type: a type becomes available only when
// PWA-6 registers its handler (first release: docs/11 FP-ADR-059).
//
// The PFP-008 proposals (FAMILY_DATA_UPDATE, HOUSEHOLD_DECLARATION_UPDATE,
// HEALTH_RECORD_SUBMISSION, NEED_SUBMISSION) are NOT approved and are
// deliberately absent; each needs its approval, a case here and a migration
// of chk_change_request_type.
enum ChangeRequestType: string
{
    case CONTACT_UPDATE = 'CONTACT_UPDATE';
    case RESIDENCE_UPDATE = 'RESIDENCE_UPDATE';
    case PERSON_CORRECTION = 'PERSON_CORRECTION';
    case ADD_FAMILY_MEMBER = 'ADD_FAMILY_MEMBER';
    case MEMBERSHIP_CHANGE = 'MEMBERSHIP_CHANGE';
    case HOUSEHOLD_HEAD_CHANGE = 'HOUSEHOLD_HEAD_CHANGE';
    case BIRTH_REPORT = 'BIRTH_REPORT';
    case DEATH_REPORT = 'DEATH_REPORT';
    case MARRIAGE_UPDATE = 'MARRIAGE_UPDATE';
    case DOCUMENT_UPDATE = 'DOCUMENT_UPDATE';
    case OTHER = 'OTHER';
}
