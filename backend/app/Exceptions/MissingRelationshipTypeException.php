<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A flow that must record a known membership relationship (HEAD / SPOUSE)
 * found its reference type missing or inactive — a deployment/seed problem,
 * raised before any registry write (see App\Support\RelationshipTypes).
 */
class MissingRelationshipTypeException extends RuntimeException
{
    public function __construct(public readonly string $typeCode)
    {
        parent::__construct("Required relationship type {$typeCode} is missing or inactive (run RelationshipTypeSeeder).");
    }
}
