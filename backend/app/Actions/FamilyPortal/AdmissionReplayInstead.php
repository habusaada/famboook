<?php

namespace App\Actions\FamilyPortal;

use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionEvidence;
use RuntimeException;

/**
 * @internal Thrown inside the admission transaction when the same idempotency key
 * committed while this request waited for the Family lock: the transaction rolls back,
 * and the action answers a replay instead. Never leaves the action.
 */
final class AdmissionReplayInstead extends RuntimeException
{
    public function __construct(public readonly RegistryAdmissionEvidence $evidence)
    {
        parent::__construct('replay');
    }
}
