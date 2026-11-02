<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use RuntimeException;

/**
 * A registry child source answered inconsistently (FB-FAMILY-ADD-01 ADD-WP-06B-3R):
 * no release, rows or the head's record from another release, or counts that do
 * not match its rows. Discovery stops and shows nothing. The message is fixed:
 * never a National ID, a release id or a payload.
 */
final class RegistryChildSourceIntegrityException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The registry child source answered inconsistently.');
    }
}
