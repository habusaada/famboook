<?php

namespace App\Exceptions;

use App\Enums\FamilyAuthError;
use BackedEnum;
use RuntimeException;

/**
 * @internal Thrown inside an activation or password reset transaction: it
 * carries the public error and the internal reason out, so the rollback
 * happens before the refusal is recorded. Never rendered — the Domain Action
 * turns it into a FamilyAuthException.
 */
final class FamilyAuthFailure extends RuntimeException
{
    public function __construct(public readonly FamilyAuthError $error, public readonly BackedEnum $reason)
    {
        parent::__construct($error->value);
    }
}
