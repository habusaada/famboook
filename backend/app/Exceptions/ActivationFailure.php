<?php

namespace App\Exceptions;

use App\Enums\ActivationError;
use BackedEnum;
use RuntimeException;

/**
 * @internal Thrown inside the activation transaction: it carries the public
 * error and the internal reason out, so the rollback happens before the
 * refusal is recorded. Never rendered — ActivateFamilyAccountAction turns it
 * into an ActivationException.
 */
final class ActivationFailure extends RuntimeException
{
    public function __construct(public readonly ActivationError $error, public readonly BackedEnum $reason)
    {
        parent::__construct($error->value);
    }
}
