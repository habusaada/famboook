<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refused direct-admission reference (FB-FAMILY-ADD-01 ADD-WP-06B-2; ADR §7, §7a):
 * a fixed code and nothing else — never a National ID, a payload field, a key or a
 * decryption message. Which check failed is deliberately not said.
 */
class RegistryAdmissionReferenceException extends RuntimeException
{
    /** Tampered, expired, foreign, malformed or of another purpose: one answer. */
    public const REFERENCE_INVALID = 'REFERENCE_INVALID';

    /** A valid reference of an earlier published release: discover again. */
    public const RELEASE_CHANGED = 'RELEASE_CHANGED';

    private const STATUS = [self::REFERENCE_INVALID => 422, self::RELEASE_CHANGED => 409];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function status(): int
    {
        return self::STATUS[$this->reason];
    }
}
