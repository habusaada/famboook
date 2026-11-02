<?php

namespace App\Enums;

// The INTERNAL reasons of a suggested child's evaluation (FB-FAMILY-ADD-01 ADD-WP-05,
// ADR §5). Never sent to a client: a household head sees only RegistryChildStatus.
enum RegistryChildReason: string
{
    // E-3: the record does not name the head as father or mother.
    case NOT_A_CHILD_OF_HEAD = 'NOT_A_CHILD_OF_HEAD';
    // E-3: father and mother both equal the head's National ID.
    case PARENT_LINK_INDETERMINATE = 'PARENT_LINK_INDETERMINATE';
    // E-4: the record is the head.
    case IS_HEAD = 'IS_HEAD';
    // E-4: not nine digits, or a placeholder.
    case INVALID_NATIONAL_ID = 'INVALID_NATIONAL_ID';
    // E-8: already an active member of THIS household.
    case ALREADY_MEMBER = 'ALREADY_MEMBER';
    // E-8: an active membership in another household. Never disclosed.
    case ACTIVE_MEMBERSHIP_ELSEWHERE = 'ACTIVE_MEMBERSHIP_ELSEWHERE';
    // E-8: the registry Person is DECEASED, of UNKNOWN life status or inactive.
    case PERSON_NOT_ATTACHABLE = 'PERSON_NOT_ATTACHABLE';
    // E-8: several equivalent holders (ambiguous legacy data).
    case AMBIGUOUS_HOLDERS = 'AMBIGUOUS_HOLDERS';
    // E-8: a soft-deleted Person holds the National ID.
    case SOFT_DELETED_HOLDER = 'SOFT_DELETED_HOLDER';
    // E-9: an open ADD_FAMILY_MEMBER request for this ID in this household.
    case REQUEST_OPEN = 'REQUEST_OPEN';
    // E-5: the gender value is missing, indeterminate or not on the list.
    case GENDER_UNSUPPORTED = 'GENDER_UNSUPPORTED';
    // E-5: the gender mapping is provisional until gate G-04 approves it.
    case GENDER_MAPPING_PROVISIONAL = 'GENDER_MAPPING_PROVISIONAL';
    // A required source value (the full name) is missing.
    case SOURCE_INCOMPLETE = 'SOURCE_INCOMPLETE';
    // E-6: the registry shows a valid death date.
    case DECEASED = 'DECEASED';
    // E-6, D-06: no approved proof of life (gate G-05), or an invalid death date.
    case LIFE_UNVERIFIED = 'LIFE_UNVERIFIED';
}
